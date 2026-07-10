<?php

namespace App\Notifications;

use App\Services\Integrations\EmailIntegrationSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FrontendPasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Porta HTTPS publica da API/backend. Links de redefinicao precisam abrir
     * no frontend desktop, nao no host da API.
     *
     * @var array<int, int>
     */
    private const BACKEND_API_PORTS = [8443];

    public function __construct(
        private readonly string $token,
        private readonly ?string $frontend = null
    ) {
    }

    /**
     * @param mixed $notifiable
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public static function resetUrlFor(string $email, string $token): string
    {
        $frontendUrl = self::frontendDesktopUrl();
        $query = http_build_query([
            'email' => $email,
        ]);

        return sprintf(
            '%s/redefinir-senha/%s?%s',
            $frontendUrl,
            rawurlencode($token),
            $query
        );
    }

    public static function frontendDesktopUrl(): string
    {
        $configuredUrl = trim((string) config('services.frontend_desktop.url', ''));
        $frontendUrl = $configuredUrl !== ''
            ? $configuredUrl
            : (string) config('app.url', 'http://127.0.0.1:8080');

        return self::normalizeFrontendUrl($frontendUrl);
    }

    private static function normalizeFrontendUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        if ($url === '') {
            return 'http://127.0.0.1:8080';
        }

        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        $port = (int) ($parts['port'] ?? 0);

        if (! in_array($port, self::BACKEND_API_PORTS, true)) {
            return $url;
        }

        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';

        return sprintf('%s://%s%s', $parts['scheme'], $parts['host'], $path);
    }

    /**
     * @param mixed $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        app(EmailIntegrationSettingsService::class)->applyRuntimeConfig();

        $frontendLabel = 'desktop';
        $email = (string) $notifiable->getEmailForPasswordReset();
        $resetUrl = self::resetUrlFor($email, $this->token);

        return (new MailMessage)
            ->subject('Redefinição de senha do Sistema ERP')
            ->greeting('Olá!')
            ->line('Recebemos uma solicitação para redefinir a senha da sua conta no Sistema ERP.')
            ->line("Se foi você quem pediu, clique no botão abaixo para escolher uma nova senha no {$frontendLabel}.")
            ->action('Redefinir senha', $resetUrl)
            ->line('Este link expira em 60 minutos.')
            ->line('Se você não solicitou esta alteração, desconsidere esta mensagem.')
            ->salutation('Equipe Sistema ERP');
    }
}
