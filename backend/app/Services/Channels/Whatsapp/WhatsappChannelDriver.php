<?php

namespace App\Services\Channels\Whatsapp;

use App\Contracts\ChannelDriverInterface;
use App\Models\Chat\Conversation;
use App\Models\Chat\Message;
use App\Models\Chat\MessageAttachment;
use App\Services\Integrations\IntegrationSettingsService;
use Illuminate\Support\Facades\Storage;

/**
 * Driver do canal WhatsApp: NAO guarda credenciais proprias, delega para o
 * IntegrationSettingsService ja existente, que gerencia a integracao Evolution API
 * configurada na tela de Configuracoes.
 */
class WhatsappChannelDriver implements ChannelDriverInterface
{
    public function __construct(
        private readonly IntegrationSettingsService $integrationSettingsService
    ) {
    }

    public function sendMessage(Conversation $conversation, Message $message): array
    {
        $phone = (string) $conversation->loadMissing('contactInbox')->contactInbox?->source_id;
        $message->loadMissing('attachments');

        if ($message->attachments->isEmpty()) {
            return $this->normalizeResult(
                $this->integrationSettingsService->sendDirectMessage($phone, (string) $message->conteudo)
            );
        }

        $responses = [];
        $providerMessageIds = [];
        $caption = trim((string) ($message->conteudo ?? ''));
        $successful = true;

        foreach ($message->attachments as $index => $attachment) {
            if (! $attachment instanceof MessageAttachment || $attachment->storage_path === null || $attachment->disk === null) {
                $successful = false;
                $responses[] = [
                    'ok' => false,
                    'message' => 'Anexo indisponivel para envio.',
                    'attachment_id' => $attachment?->id,
                ];

                continue;
            }

            $absolutePath = Storage::disk($attachment->disk)->path($attachment->storage_path);
            $result = $this->integrationSettingsService->sendDirectMedia(
                $phone,
                $absolutePath,
                (string) $attachment->attachment_type,
                $index === 0 ? $caption : null,
                (string) ($attachment->original_name ?: $attachment->stored_name)
            );

            $responses[] = $result;
            $providerId = data_get($result, 'response.key.id');
            if (is_string($providerId) && trim($providerId) !== '') {
                $providerMessageIds[] = trim($providerId);
            }

            if (! ($result['ok'] ?? false)) {
                $successful = false;
            }
        }

        return [
            'ok' => $successful && $responses !== [],
            'provider' => $responses[0]['provider'] ?? null,
            'status_code' => $responses[0]['status_code'] ?? 422,
            'message' => $successful
                ? 'Mensagem enviada com sucesso.'
                : 'Falha ao enviar um ou mais anexos para o WhatsApp.',
            'response' => ['attempts' => $responses],
            'provider_message_ids' => $providerMessageIds,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function validateProviderConfig(array $config): bool
    {
        return true;
    }

    /**
     * @return array<int, mixed>
     */
    public function syncTemplates(): array
    {
        return [];
    }

    public function webhookSetup(): void
    {
        // O endpoint POST /webhooks/whatsapp ja existe e e configurado/validado pela tela
        // de Configuracoes existente (IntegrationSettingsService::selfCheckInbound()).
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function normalizeResult(array $result): array
    {
        $providerId = data_get($result, 'response.key.id');

        return array_merge($result, [
            'provider_message_ids' => is_string($providerId) && trim($providerId) !== ''
                ? [trim($providerId)]
                : [],
        ]);
    }
}
