<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona 8 transições que faltavam no catálogo os_status_transicoes,
 * fechando as duas lacunas de processo documentadas em
 * scripts/python/README-diagrama-fluxo-os.md:
 *
 *  1. `cumprimento_garantia` era beco sem saída (nenhuma transição de saída
 *     cadastrada — uma OS ali só saía pela baixa): ganha saídas para
 *     garantia_concluida (garantia cumprida com sucesso) e irreparavel
 *     (defeito coberto pela garantia, mas sem conserto possível).
 *
 *  2. Teste reprovado não alcançava `retrabalho`: testes_finais -> retrabalho
 *     (checklist de saída reprovou) e testes_operacionais -> irreparavel
 *     (problema grave descoberto durante os testes). O ciclo de retrabalho
 *     também ganha retrabalho -> aguardando_reparo (re-enfileirar o serviço).
 *
 *  Também formaliza o fluxo de peça encomendada com sinal do cliente:
 *  aguardando_peca -> pagamento_pendente (sinal para comprar a peça),
 *  pagamento_pendente -> aguardando_reparo (sinal pago, volta pra fila) e
 *  aguardando_peca -> aguardando_reparo (peça chegou).
 *
 * Nenhum destino é de encerramento (grupo_macro='encerrado'), então todas
 * ficam disponíveis no fluxo normal ("Alterar status"). Resolve códigos ->
 * ids em runtime (nunca ids fixos) e pula pares cujo código não exista no
 * catálogo (idempotente também em bancos parciais, ex.: de teste).
 * O down() apenas DESATIVA (ativo=0) exatamente estes pares — nunca deleta,
 * mesmo comportamento da tela Conhecimento > Fluxo da OS ao desmarcar
 * (OrderStatusFlowService::syncTransitions).
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    private const TRANSITIONS = [
        ['cumprimento_garantia', 'garantia_concluida'],
        ['cumprimento_garantia', 'irreparavel'],
        ['testes_finais', 'retrabalho'],
        ['testes_operacionais', 'irreparavel'],
        ['retrabalho', 'aguardando_reparo'],
        ['aguardando_peca', 'pagamento_pendente'],
        ['pagamento_pendente', 'aguardando_reparo'],
        ['aguardando_peca', 'aguardando_reparo'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('os_status') || ! Schema::hasTable('os_status_transicoes')) {
            return;
        }

        $idsByCode = DB::table('os_status')->pluck('id', 'codigo')->all();

        foreach (self::TRANSITIONS as [$origem, $destino]) {
            $origemId = (int) ($idsByCode[$origem] ?? 0);
            $destinoId = (int) ($idsByCode[$destino] ?? 0);

            if ($origemId <= 0 || $destinoId <= 0) {
                continue;
            }

            $existing = DB::table('os_status_transicoes')
                ->where('status_origem_id', $origemId)
                ->where('status_destino_id', $destinoId)
                ->first(['id', 'ativo']);

            if ($existing !== null) {
                if (! (bool) $existing->ativo) {
                    DB::table('os_status_transicoes')
                        ->where('id', $existing->id)
                        ->update(['ativo' => 1, 'updated_at' => now()]);
                }

                continue;
            }

            DB::table('os_status_transicoes')->insert([
                'status_origem_id' => $origemId,
                'status_destino_id' => $destinoId,
                'ativo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('os_status') || ! Schema::hasTable('os_status_transicoes')) {
            return;
        }

        $idsByCode = DB::table('os_status')->pluck('id', 'codigo')->all();

        foreach (self::TRANSITIONS as [$origem, $destino]) {
            $origemId = (int) ($idsByCode[$origem] ?? 0);
            $destinoId = (int) ($idsByCode[$destino] ?? 0);

            if ($origemId <= 0 || $destinoId <= 0) {
                continue;
            }

            DB::table('os_status_transicoes')
                ->where('status_origem_id', $origemId)
                ->where('status_destino_id', $destinoId)
                ->update(['ativo' => 0, 'updated_at' => now()]);
        }
    }
};
