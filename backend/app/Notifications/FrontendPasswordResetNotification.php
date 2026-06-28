<?php

namespace App\Notifications;

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

    /**
     * @param mixed $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        $frontendLabel = 'desktop';
        $frontendUrl = rtrim((string) config('services.frontend_desktop.url', 'http://127.0.0.1:8080'), '/');
        $email = (string) $notifiable->getEmailForPasswordReset();
        $query = http_build_query([
            'email' => $email,
        ]);

        $resetUrl = sprintf(
            '%s/redefinir-senha/%s?%s',
            $frontendUrl,
            rawurlencode($this->token),
            $query
        );

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
