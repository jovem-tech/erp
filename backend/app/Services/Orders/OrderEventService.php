<?php

namespace App\Services\Orders;

use App\Models\OrderEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Writer unico da timeline de eventos da OS (`os_eventos`).
 *
 * Regras de projeto:
 * - Append-only: este service so INSERE. Nenhum caminho de update/delete deve
 *   existir na aplicacao (excecao: os:backfill-eventos, que gerencia apenas as
 *   linhas importadas do legado, com legacy_tabela preenchida).
 * - Falha ao registrar evento NUNCA pode quebrar a acao de negocio que o
 *   emitiu — qualquer excecao aqui vira log de warning e segue o jogo.
 * - Chamado inline nos pontos de emissao: quando a acao roda dentro de uma
 *   DB::transaction, o evento participa da mesma transacao e sofre rollback
 *   junto (a timeline nunca mostra acao que nao foi commitada).
 */
class OrderEventService
{
    private const MAX_DADOS_STRING = 500;

    /**
     * @param array<string, mixed> $dados
     */
    public function record(
        int $osId,
        string $categoria,
        string $tipo,
        string $titulo,
        ?string $descricao = null,
        array $dados = [],
        ?int $usuarioId = null,
        string $origem = OrderEvent::ORIGEM_USUARIO,
        ?Carbon $timestamp = null
    ): void {
        try {
            // FinanceiroService (e outros caminhos genericos) nao recebem o
            // actor por parametro — resolve pelo guard da request quando a
            // origem indica acao humana.
            if ($usuarioId === null && $origem === OrderEvent::ORIGEM_USUARIO) {
                $authId = Auth::id();
                $usuarioId = $authId !== null ? (int) $authId : null;
            }

            OrderEvent::query()->create([
                'os_id' => $osId,
                'categoria' => $categoria,
                'tipo' => $tipo,
                'titulo' => mb_substr($titulo, 0, 160),
                'descricao' => $descricao !== null && trim($descricao) !== '' ? trim($descricao) : null,
                'dados' => $dados !== [] ? $this->truncateDados($dados) : null,
                'usuario_id' => $usuarioId,
                'origem' => $origem,
                'created_at' => $timestamp ?? Carbon::now(),
            ]);
        } catch (Throwable $exception) {
            logger()->warning('[OS][EVENTOS] Falha ao registrar evento da OS', [
                'os_id' => $osId,
                'categoria' => $categoria,
                'tipo' => $tipo,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Limita o tamanho de strings dentro do payload auditavel para o JSON nao
     * inchar com textos livres (observacoes longas, user agents etc).
     *
     * @param array<string, mixed> $dados
     * @return array<string, mixed>
     */
    private function truncateDados(array $dados): array
    {
        foreach ($dados as $key => $value) {
            if (is_string($value) && mb_strlen($value) > self::MAX_DADOS_STRING) {
                $dados[$key] = mb_substr($value, 0, self::MAX_DADOS_STRING) . '…';
            } elseif (is_array($value)) {
                $dados[$key] = $this->truncateDados($value);
            }
        }

        return $dados;
    }
}
