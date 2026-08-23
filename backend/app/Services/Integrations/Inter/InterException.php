<?php

namespace App\Services\Integrations\Inter;

use RuntimeException;
use Throwable;

/**
 * Falha na integracao com o Banco Inter.
 *
 * Carrega o status HTTP quando a origem foi uma resposta do banco, para o
 * chamador distinguir "credencial invalida" (401) de "cobranca nao existe"
 * (404) de "banco fora do ar" (5xx) sem parsear string de mensagem.
 */
class InterException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $contexto
     * @param  bool  $origemLocal  true quando a falha foi detectada AQUI, antes
     *                             de qualquer chamada — credencial ausente,
     *                             periodo invalido, resposta em formato
     *                             inesperado. Distingue "voce pediu errado" de
     *                             "o banco esta fora", que exigem acoes opostas
     *                             de quem le' o erro.
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
     * Falha nossa, nao do banco: nao adianta tentar de novo sem mudar algo.
     *
     * @param  array<string, mixed>  $contexto
     */
    public static function local(string $message, array $contexto = []): self
    {
        return new self($message, null, $contexto, null, origemLocal: true);
    }

    public function ehCredencialInvalida(): bool
    {
        return in_array($this->statusHttp, [400, 401, 403], true);
    }

    public function ehFalhaTemporaria(): bool
    {
        // Falha local nunca e' temporaria: repetir a mesma consulta com o mesmo
        // periodo invalido, ou sem credencial, da' o mesmo resultado.
        if ($this->origemLocal) {
            return false;
        }

        return $this->statusHttp === null || $this->statusHttp >= 500 || $this->statusHttp === 429;
    }
}
