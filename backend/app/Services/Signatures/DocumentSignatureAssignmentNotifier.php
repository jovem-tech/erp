<?php

namespace App\Services\Signatures;

use App\Jobs\DispatchDocumentSignatureAssignmentJob;
use App\Models\DocumentSignatureDelivery;
use App\Models\DocumentSignatureRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\Integrations\EmailIntegrationSettingsService;
use App\Services\Integrations\IntegrationSettingsService;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Pdf\PdfTemplateRegistry;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DocumentSignatureAssignmentNotifier
{
    private const CHANNEL_IN_APP = 'in_app';

    private const CHANNEL_EMAIL = 'email';

    private const CHANNEL_WHATSAPP = 'whatsapp';

    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly NotificationDispatchService $notificationDispatch,
        private readonly EmailIntegrationSettingsService $emailSettings,
        private readonly IntegrationSettingsService $integrationSettings,
        private readonly PdfTemplateRegistry $templateRegistry
    ) {
    }

    /**
     * @param array<int, DocumentSignatureRequest> $requests
     */
    public function notifyAssignments(array $requests, Order $order, User $actor, User $responsible): void
    {
        foreach ($requests as $signatureRequest) {
            if (! $signatureRequest instanceof DocumentSignatureRequest) {
                continue;
            }

            $label = $this->documentLabel((string) $signatureRequest->tipo_documento);
            $orderNumber = trim((string) $order->numero_os) ?: ('OS #' . (int) $order->id);
            $route = $this->documentRoute((int) $order->id);

            $inAppDelivery = null;
            $shouldSendInApp = true;
            if (Schema::hasTable('documento_assinatura_notificacoes')) {
                $inAppDelivery = DocumentSignatureDelivery::query()->firstOrCreate(
                    [
                        'solicitacao_id' => (int) $signatureRequest->id,
                        'canal' => self::CHANNEL_IN_APP,
                    ],
                    [
                        'status' => 'pendente',
                        'destinatario_resumo' => 'Usuário #' . (int) $responsible->id,
                    ]
                );
                $shouldSendInApp = (string) $inAppDelivery->status !== 'enviada';
            }

            if ($shouldSendInApp) {
                try {
                    $inAppDelivery?->forceFill([
                        'status' => 'processando',
                        'tentativas' => (int) $inAppDelivery->tentativas + 1,
                        'ultima_tentativa_em' => now(),
                        'erro' => null,
                    ])->save();

                    $this->notificationDispatch->toUsers([(int) $responsible->id], [
                        'kind' => 'document.signature.requested',
                        'title' => 'Documento aguardando sua assinatura',
                        'body' => sprintf('%s da %s foi encaminhado por %s.', $label, $orderNumber, (string) $actor->nome),
                        'route' => $route,
                        'icon' => 'envelope-paper',
                        'signature_request_id' => (int) $signatureRequest->id,
                        'order_id' => (int) $order->id,
                        'document_type' => (string) $signatureRequest->tipo_documento,
                    ]);

                    $inAppDelivery?->forceFill([
                        'status' => 'enviada',
                        'enviada_em' => now(),
                    ])->save();
                } catch (Throwable $exception) {
                    $inAppDelivery?->forceFill([
                        'status' => 'falha',
                        'erro' => $this->sanitizeError($exception->getMessage()),
                    ])->save();
                    logger()->error('[SIGNATURE][NOTIFY] Falha no aviso interno de assinatura', [
                        'signature_request_id' => (int) $signatureRequest->id,
                        'message' => $this->sanitizeError($exception->getMessage()),
                    ]);
                }
            }

            if (! Schema::hasTable('documento_assinatura_notificacoes')) {
                continue;
            }

            foreach ([self::CHANNEL_EMAIL, self::CHANNEL_WHATSAPP] as $channel) {
                $destination = $this->destinationFor($responsible, $channel);
                DocumentSignatureDelivery::query()->firstOrCreate(
                    [
                        'solicitacao_id' => (int) $signatureRequest->id,
                        'canal' => $channel,
                    ],
                    [
                        'status' => 'pendente',
                        'destinatario_hash' => $this->destinationHash($destination),
                        'destinatario_resumo' => $this->maskDestination($destination, $channel),
                    ]
                );
            }

            try {
                DispatchDocumentSignatureAssignmentJob::dispatch((int) $signatureRequest->id);
            } catch (Throwable $exception) {
                logger()->error('[SIGNATURE][NOTIFY] Não foi possível enfileirar os avisos externos', [
                    'signature_request_id' => (int) $signatureRequest->id,
                    'message' => $this->sanitizeError($exception->getMessage()),
                ]);
            }
        }
    }

    public function dispatchExternal(int $signatureRequestId): void
    {
        $signatureRequest = DocumentSignatureRequest::query()
            ->with(['order:id,numero_os', 'requester:id,nome', 'responsibleUser.teamMember'])
            ->find($signatureRequestId);

        if (! $signatureRequest instanceof DocumentSignatureRequest
            || (string) $signatureRequest->status !== 'pendente'
            || ! ($signatureRequest->responsibleUser instanceof User)) {
            return;
        }

        $hasRetryableFailure = false;
        foreach ([self::CHANNEL_EMAIL, self::CHANNEL_WHATSAPP] as $channel) {
            $delivery = DocumentSignatureDelivery::query()
                ->where('solicitacao_id', $signatureRequestId)
                ->where('canal', $channel)
                ->first();

            if (! $delivery instanceof DocumentSignatureDelivery
                || in_array((string) $delivery->status, ['enviada', 'ignorada'], true)
                || (int) $delivery->tentativas >= self::MAX_ATTEMPTS) {
                continue;
            }

            $destination = $this->destinationFor($signatureRequest->responsibleUser, $channel);
            $delivery->forceFill([
                'status' => 'processando',
                'tentativas' => (int) $delivery->tentativas + 1,
                'destinatario_hash' => $this->destinationHash($destination),
                'destinatario_resumo' => $this->maskDestination($destination, $channel),
                'ultima_tentativa_em' => now(),
                'erro' => null,
            ])->save();

            try {
                $result = $channel === self::CHANNEL_EMAIL
                    ? $this->sendEmail($signatureRequest, $destination)
                    : $this->sendWhatsapp($signatureRequest, $destination);
            } catch (Throwable $exception) {
                $result = [
                    'status' => 'falha',
                    'provider' => $channel,
                    'reference' => null,
                    'message' => $exception->getMessage(),
                ];
            }

            $status = (string) ($result['status'] ?? 'falha');
            $delivery->forceFill([
                'status' => $status,
                'provider' => Str::limit(trim((string) ($result['provider'] ?? $channel)), 60, ''),
                'referencia' => Str::limit(trim((string) ($result['reference'] ?? '')), 190, '') ?: null,
                'erro' => $status === 'falha' ? $this->sanitizeError((string) ($result['message'] ?? 'Falha não especificada.')) : null,
                'enviada_em' => $status === 'enviada' ? now() : null,
            ])->save();

            if ($status === 'falha' && (int) $delivery->tentativas < self::MAX_ATTEMPTS) {
                $hasRetryableFailure = true;
            }
        }

        if ($hasRetryableFailure) {
            throw new RuntimeException('Um ou mais canais externos de assinatura não foram entregues.');
        }
    }

    /** @return array{status: string, provider: string, reference: string|null, message: string} */
    private function sendEmail(DocumentSignatureRequest $request, string $email): array
    {
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['status' => 'ignorada', 'provider' => 'smtp', 'reference' => null, 'message' => 'Usuário sem e-mail válido.'];
        }
        if (! $this->emailSettings->operationalMailerAvailable()) {
            return ['status' => 'falha', 'provider' => 'smtp', 'reference' => null, 'message' => 'SMTP operacional não configurado.'];
        }

        $responsible = $request->responsibleUser;
        $label = $this->documentLabel((string) $request->tipo_documento);
        $orderNumber = trim((string) ($request->order?->numero_os ?? '')) ?: ('OS #' . (int) $request->os_id);
        $requesterName = trim((string) ($request->requester?->nome ?? '')) ?: 'um usuário do ERP';
        $url = $this->documentUrl((int) $request->os_id);
        $subject = sprintf('Assinatura pendente: %s - %s', $label, $orderNumber);
        $html = sprintf(
            '<p>Olá, <strong>%s</strong>.</p><p>%s encaminhou o documento <strong>%s</strong> da <strong>%s</strong> para sua assinatura.</p><p><a href="%s">Abrir documento pendente no ERP</a></p><p>Esta mensagem foi gerada automaticamente.</p>',
            e((string) ($responsible?->nome ?? 'Usuário')),
            e($requesterName),
            e($label),
            e($orderNumber),
            e($url)
        );

        Mail::html($html, static function ($mail) use ($email, $subject): void {
            $mail->to($email)->subject($subject);
        });

        return ['status' => 'enviada', 'provider' => 'smtp', 'reference' => null, 'message' => 'E-mail enviado.'];
    }

    /** @return array{status: string, provider: string, reference: string|null, message: string} */
    private function sendWhatsapp(DocumentSignatureRequest $request, string $phone): array
    {
        if ($phone === '') {
            return ['status' => 'ignorada', 'provider' => 'whatsapp', 'reference' => null, 'message' => 'Usuário sem telefone.'];
        }

        $label = $this->documentLabel((string) $request->tipo_documento);
        $orderNumber = trim((string) ($request->order?->numero_os ?? '')) ?: ('OS #' . (int) $request->os_id);
        $requesterName = trim((string) ($request->requester?->nome ?? '')) ?: 'Um usuário do ERP';
        $message = sprintf(
            'Olá, %s. %s encaminhou o documento "%s" da %s para sua assinatura. Acesse: %s',
            (string) ($request->responsibleUser?->nome ?? 'usuário'),
            $requesterName,
            $label,
            $orderNumber,
            $this->documentUrl((int) $request->os_id)
        );
        $result = $this->integrationSettings->sendDirectMessage($phone, $message);

        return [
            'status' => (bool) ($result['ok'] ?? false) ? 'enviada' : 'falha',
            'provider' => trim((string) ($result['provider'] ?? 'whatsapp')) ?: 'whatsapp',
            'reference' => trim((string) ($result['reference'] ?? '')) ?: null,
            'message' => trim((string) ($result['message'] ?? '')) ?: 'Falha no envio pelo WhatsApp.',
        ];
    }

    private function documentLabel(string $type): string
    {
        try {
            $descriptor = $this->templateRegistry->get($type);
            $label = trim((string) ($descriptor['nome'] ?? ''));

            return $label !== '' ? $label : Str::headline($type);
        } catch (Throwable) {
            return Str::headline($type);
        }
    }

    private function documentRoute(int $orderId): string
    {
        return '/os/' . $orderId . '/documentos#assinaturas-pendentes';
    }

    private function documentUrl(int $orderId): string
    {
        $baseUrl = rtrim(trim((string) config('services.frontend.desktop.url')), '/');
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            $baseUrl = rtrim(trim((string) config('app.public_url', config('app.url'))), '/');
        }

        return $baseUrl . $this->documentRoute($orderId);
    }

    private function destinationFor(User $responsible, string $channel): string
    {
        if ($channel === self::CHANNEL_EMAIL) {
            return strtolower(trim((string) $responsible->email));
        }

        $responsible->loadMissing('teamMember');

        return trim((string) ($responsible->telefone ?: $responsible->teamMember?->telefone));
    }

    private function destinationHash(string $destination): ?string
    {
        return $destination === ''
            ? null
            : hash_hmac('sha256', $destination, (string) config('app.key'));
    }

    private function maskDestination(string $destination, string $channel): ?string
    {
        if ($destination === '') {
            return null;
        }
        if ($channel === self::CHANNEL_EMAIL) {
            [$local, $domain] = array_pad(explode('@', $destination, 2), 2, '');

            return mb_substr($local, 0, 2) . '***@' . $domain;
        }

        $digits = preg_replace('/\D+/', '', $destination) ?: '';

        return $digits === '' ? null : '***' . substr($digits, -4);
    }

    private function sanitizeError(string $message): string
    {
        $plain = trim(strip_tags($message));
        $redacted = preg_replace(
            '/\b(token|password|senha|secret|api[_-]?key)\s*[:=]\s*[^\s,;]+/iu',
            '$1=[REDACTED]',
            $plain
        ) ?: 'Falha não especificada.';

        return Str::limit($redacted, 2000, '…');
    }
}
