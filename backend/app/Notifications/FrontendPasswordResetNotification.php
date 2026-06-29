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
        $frontendUrl = rtrim((string) config('services.frontend_desktop.url', 'http://127.0.0.1:8080'), '/');
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
