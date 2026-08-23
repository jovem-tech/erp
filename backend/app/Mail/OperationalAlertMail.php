<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Alerta operacional por e-mail.
 *
 * Segue o mesmo formato do IntegrationTestMail (htmlString, sem view): o corpo
 * e' curto e gerado em codigo, entao uma Blade so' adicionaria um arquivo para
 * manter sincronizado.
 */
class OperationalAlertMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $contexto
     */
    public function __construct(
        private readonly string $titulo,
        private readonly string $mensagem,
        private readonly array $contexto = []
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[ERP] '.$this->titulo);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->corpoHtml());
    }

    private function corpoHtml(): string
    {
        $html = '<p><strong>'.e($this->titulo).'</strong></p>'
            .'<p>'.nl2br(e($this->mensagem)).'</p>';

        if ($this->contexto !== []) {
            $html .= '<table cellpadding="4" style="border-collapse:collapse;font-family:monospace;font-size:13px">';

            foreach ($this->contexto as $chave => $valor) {
                $html .= '<tr>'
                    .'<td style="border:1px solid #ddd"><strong>'.e((string) $chave).'</strong></td>'
                    .'<td style="border:1px solid #ddd">'.e($this->escalar($valor)).'</td>'
                    .'</tr>';
            }

            $html .= '</table>';
        }

        return $html.'<p style="color:#888;font-size:12px">Enviado automaticamente pelo Sistema ERP.</p>';
    }

    private function escalar(mixed $valor): string
    {
        if (is_bool($valor)) {
            return $valor ? 'true' : 'false';
        }

        if ($valor === null || is_scalar($valor)) {
            return (string) $valor;
        }

        return (string) json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
