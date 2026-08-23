<?php

namespace App\Services\Notifications;

use App\Mail\OperationalAlertMail;
use App\Services\Channels\Whatsapp\WhatsappMessagingService;
use App\Services\Integrations\EmailIntegrationSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Alerta operacional para falhas que ninguem ve' sozinho.
 *
 * Existe porque o sistema nao tinha canal de saida nenhum: MAIL_MAILER=log e
 * LOG_LEVEL=warning significam que um certificado vencido, uma conciliacao
 * parada ou uma baixa divergente ficariam invisiveis ate' um cliente reclamar.
 *
 * Duas garantias que o chamador pode assumir:
 *
 * 1. NUNCA lanca. Alerta e' caminho de observabilidade; falhar ao avisar nao
 *    pode derrubar a operacao que estava sendo observada.
 * 2. SEMPRE registra no canal `pagamentos`, mesmo quando a entrega falha — o
 *    log e' a trilha de ultimo recurso, e ele nao depende de rede.
 */
class OperationalAlertService
{
    public function __construct(
        private readonly WhatsappMessagingService $whatsapp,
        private readonly EmailIntegrationSettingsService $emailSettings,
    ) {
    }

    /**
     * Urgente: vai para o WhatsApp (celular) e tambem por e-mail.
     *
     * @param  array<string, mixed>  $contexto
     * @param  string|null  $chaveDedupe  Quando informado, o mesmo alerta nao se
     *                                    repete dentro de alertas.dedupe_minutos.
     */
    public function urgente(
        string $titulo,
        string $mensagem,
        array $contexto = [],
        ?string $chaveDedupe = null
    ): bool {
        return $this->despachar($titulo, $mensagem, $contexto, $chaveDedupe, whatsapp: true, email: true);
    }

    /**
     * Relatorio/rotina: so' e-mail, para nao virar ruido no celular.
     *
     * @param  array<string, mixed>  $contexto
     */
    public function relatorio(
        string $titulo,
        string $mensagem,
        array $contexto = [],
        ?string $chaveDedupe = null
    ): bool {
        return $this->despachar($titulo, $mensagem, $contexto, $chaveDedupe, whatsapp: false, email: true);
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    private function despachar(
        string $titulo,
        string $mensagem,
        array $contexto,
        ?string $chaveDedupe,
        bool $whatsapp,
        bool $email
    ): bool {
        $log = Log::channel('pagamentos');

        if (! (bool) config('alertas.enabled', true)) {
            $log->info('[ALERTA] Suprimido (alertas desligados).', ['titulo' => $titulo]);

            return false;
        }

        if ($chaveDedupe !== null && ! $this->reservarDedupe($chaveDedupe)) {
            $log->info('[ALERTA] Suprimido por deduplicacao.', [
                'titulo' => $titulo,
                'chave' => $chaveDedupe,
            ]);

            return false;
        }

        // Trilha primeiro: se a entrega explodir, o registro ja esta gravado.
        $log->warning('[ALERTA] '.$titulo, ['mensagem' => $mensagem] + $contexto);

        $entregue = false;

        if ($whatsapp) {
            $entregue = $this->viaWhatsapp($titulo, $mensagem) || $entregue;
        }

        if ($email) {
            $entregue = $this->viaEmail($titulo, $mensagem, $contexto) || $entregue;
        }

        if (! $entregue) {
            $log->error('[ALERTA] Nenhum canal entregou o alerta.', [
                'titulo' => $titulo,
                'whatsapp_configurado' => $this->numeroWhatsapp() !== '',
                'email_configurado' => $this->enderecoEmail() !== '',
            ]);
        }

        return $entregue;
    }

    private function viaWhatsapp(string $titulo, string $mensagem): bool
    {
        $numero = $this->numeroWhatsapp();

        if ($numero === '') {
            return false;
        }

        try {
            $resultado = $this->whatsapp->sendSystemMessage(
                $numero,
                sprintf("*%s*\n\n%s", $titulo, $mensagem),
                contentAttributes: ['origin' => 'alerta_operacional']
            );

            return (bool) ($resultado['ok'] ?? false);
        } catch (Throwable $e) {
            Log::channel('pagamentos')->error('[ALERTA] Falha no envio por WhatsApp.', [
                'titulo' => $titulo,
                'erro' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    private function viaEmail(string $titulo, string $mensagem, array $contexto): bool
    {
        $destino = $this->enderecoEmail();

        if ($destino === '') {
            return false;
        }

        try {
            // Sem SMTP real configurado o Laravel cairia no mailer `log` e o
            // alerta iria para o mesmo lugar que ja' estamos gravando —
            // reportar "entregue" nesse caso seria mentira.
            if (! $this->emailSettings->operationalMailerAvailable()) {
                return false;
            }

            Mail::to($destino)->send(new OperationalAlertMail($titulo, $mensagem, $contexto));

            return true;
        } catch (Throwable $e) {
            Log::channel('pagamentos')->error('[ALERTA] Falha no envio por e-mail.', [
                'titulo' => $titulo,
                'erro' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function reservarDedupe(string $chave): bool
    {
        $minutos = max(0, (int) config('alertas.dedupe_minutos', 720));

        if ($minutos === 0) {
            return true;
        }

        return Cache::add('alerta:'.sha1($chave), 1, now()->addMinutes($minutos));
    }

    private function numeroWhatsapp(): string
    {
        return trim((string) config('alertas.whatsapp', ''));
    }

    private function enderecoEmail(): string
    {
        return trim((string) config('alertas.email', ''));
    }
}
