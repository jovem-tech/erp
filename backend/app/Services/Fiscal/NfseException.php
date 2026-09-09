<?php

namespace App\Services\Fiscal;

use RuntimeException;
use Throwable;

/**
 * Falha na transmissao da NFS-e ao Ambiente Nacional.
 *
 * A distincao que esta classe carrega nao e' decorativa: ela decide o que
 * acontece com o documento fiscal. Rejeicao de regra de negocio vira
 * `registrarRejeicao()` — o operador precisa corrigir o cadastro e reenviar.
 * Falha de transporte NAO vira rejeicao: a nota pode ter sido emitida do outro
 * lado, e marcar o documento como rejeitado apagaria a pista de que ha' algo a
 * consultar.
 */
class NfseException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $contexto
     * @param  bool  $origemLocal  true quando a falha foi detectada AQUI, antes
     *                             de qualquer chamada — certificado ausente,
     *                             empresa sem CNPJ, resposta ilegivel.
     */
    public function __construct(
        string $message,
        public readonly ?int $statusHttp = null,
        public readonly array $contexto = [],
        ?Throwable $previous = null,
        public readonly bool $origemLocal = false
    ) {
        parent::__construct($message, $statusHttp ?? 0, $previous);
    }

    /**
     * Falha nossa, antes da rede: insistir nao muda nada.
     *
     * @param  array<string, mixed>  $contexto
     */
    public static function local(string $message, array $contexto = []): self
    {
        return new self($message, null, $contexto, null, origemLocal: true);
    }

    /**
     * O ADN recusou por regra de negocio — serie ja' usada, competencia
     * fechada, codigo de tributacao incompativel. Reenviar identico da' o mesmo
     * resultado; alguem precisa corrigir o dado antes.
     */
    public function ehRejeicaoFiscal(): bool
    {
        if ($this->origemLocal) {
            return false;
        }

        return $this->statusHttp !== null
            && $this->statusHttp >= 400
            && $this->statusHttp < 500
            // 408/429 sao "tente de novo", nao "seu dado esta errado".
            && ! in_array($this->statusHttp, [408, 429], true);
    }

    /**
     * Vale tentar de novo — mas SEMPRE consultando antes, porque a tentativa
     * anterior pode ter emitido a nota e morrido na volta.
     */
    public function ehFalhaTemporaria(): bool
    {
        if ($this->origemLocal) {
            return false;
        }

        return $this->statusHttp === null
            || $this->statusHttp >= 500
            || in_array($this->statusHttp, [408, 429], true);
    }
}
