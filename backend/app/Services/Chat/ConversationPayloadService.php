<?php

namespace App\Services\Chat;

use App\Models\Chat\Conversation;
use App\Models\Chat\Message;
use App\Models\Chat\MessageAttachment;
use App\Models\Legacy\LegacyClient;

class ConversationPayloadService
{
    /**
     * @return array<string, mixed>
     */
    public function conversationSummary(Conversation $conversation, int $unreadCount = 0): array
    {
        $contact = $conversation->contact;
        $latestMessage = $conversation->latestMessage;

        return [
            'id' => $conversation->id,
            'display_id' => $conversation->display_id,
            'status' => $conversation->status,
            'status_label' => $conversation->statusLabel(),
            'last_activity_at' => $conversation->last_activity_at?->toIso8601String(),
            'unread' => $unreadCount > 0,
            'unread_count' => max(0, $unreadCount),
            'contact' => $this->contactPayload($contact?->nome, $contact?->telefone, $contact?->id, $contact?->cliente_id, $contact?->client),
            'last_message' => $latestMessage instanceof Message ? $this->messagePreview($latestMessage) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function conversationDetail(Conversation $conversation, int $unreadCount = 0): array
    {
        return array_merge(
            $this->conversationSummary($conversation, $unreadCount),
            [
                'messages' => $conversation->messages->map(
                    fn (Message $message): array => $this->message($message)
                )->all(),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function message(Message $message): array
    {
        $message->loadMissing('attachments');

        return [
            'id' => $message->id,
            'conversa_id' => $message->conversa_id,
            'message_type' => $message->message_type,
            'content_type' => $message->content_type ?: Message::CONTENT_TYPE_TEXT,
            'conteudo' => $message->conteudo,
            'status' => $message->status,
            'sender_type' => $message->sender_type,
            'sender_id' => $message->sender_id,
            'created_at' => $message->created_at?->toIso8601String(),
            'attachments' => $message->attachments->map(
                fn (MessageAttachment $attachment): array => $this->attachment($attachment)
            )->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messageUpdate(Message $message): array
    {
        return [
            'id' => $message->id,
            'conversa_id' => $message->conversa_id,
            'status' => $message->status,
            'source_id' => $message->source_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attachment(MessageAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'attachment_type' => $attachment->attachment_type,
            'transfer_status' => $attachment->transfer_status,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'byte_size' => $attachment->byte_size !== null ? (int) $attachment->byte_size : null,
            'available' => $attachment->transfer_status === MessageAttachment::TRANSFER_AVAILABLE
                && $attachment->storage_path !== null,
            'url' => route('api.v1.chat.attachments.show', ['attachment' => $attachment->id], false),
            'provider_url' => $attachment->provider_url,
            'metadata' => $attachment->metadata,
        ];
    }

    private function messagePreview(Message $message): array
    {
        $preview = trim((string) ($message->conteudo ?? ''));
        $attachmentCount = $message->attachments->count();

        if ($preview === '') {
            $preview = $this->attachmentPreviewLabel($message->attachments->first());
        } elseif ($attachmentCount > 0) {
            $preview .= ' · ' . $attachmentCount . ' anexo' . ($attachmentCount > 1 ? 's' : '');
        }

        return [
            'id' => $message->id,
            'message_type' => $message->message_type,
            'content_type' => $message->content_type ?: Message::CONTENT_TYPE_TEXT,
            'sender_type' => $message->sender_type,
            'status' => $message->status,
            'created_at' => $message->created_at?->toIso8601String(),
            'preview' => mb_strimwidth($preview !== '' ? $preview : 'Mensagem sem texto', 0, 100, '…'),
            'attachment_count' => $attachmentCount,
        ];
    }

    private function attachmentPreviewLabel(?MessageAttachment $attachment): string
    {
        return match ($attachment?->attachment_type) {
            MessageAttachment::TYPE_IMAGE => 'Imagem',
            MessageAttachment::TYPE_AUDIO => 'Áudio',
            MessageAttachment::TYPE_VIDEO => 'Vídeo',
            MessageAttachment::TYPE_DOCUMENT => 'Documento',
            default => 'Anexo',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function contactPayload(
        ?string $name,
        ?string $phone,
        ?int $contactId,
        ?int $clientId,
        ?LegacyClient $client
    ): array {
        return [
            'id' => $contactId,
            'nome' => $name,
            'telefone' => $phone,
            'cliente_id' => $clientId,
            'cliente_nome' => $client?->nome_razao,
            'client' => $client instanceof LegacyClient ? [
                'id' => (int) $client->id,
                'nome_razao' => trim((string) ($client->nome_razao ?? '')),
                'cpf_cnpj' => trim((string) ($client->cpf_cnpj ?? '')),
                'cidade' => trim((string) ($client->cidade ?? '')),
                'uf' => trim((string) ($client->uf ?? '')),
                'telefone1' => trim((string) ($client->telefone1 ?? '')),
                'telefone2' => trim((string) ($client->telefone2 ?? '')),
                'telefone_contato' => trim((string) ($client->telefone_contato ?? '')),
                'nome_contato' => trim((string) ($client->nome_contato ?? '')),
            ] : null,
        ];
    }
}
