<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona 10 transições cadastradas manualmente em Conhecimento > Fluxo da
 * OS (ambiente de homologação, 2026-07-17), formalizando-as como código pra
 * chegarem em qualquer ambiente (produção incluída) via `php artisan
 * migrate`, em vez de depender de alguém repetir o clique na tela em cada
 * banco separadamente.
 *
 * `testes_operacionais` ganha 5 saídas novas:
 *   - verificacao_garantia / aguardando_orcamento: o teste revelou algo que
 *     precisa reavaliar garantia ou reorçar (retorno).
 *   - reparo_concluido / garantia_concluida: atalho quando já dá pra
 *     concluir direto, sem passar por testes_finais.
 *   - cancelado: cancelamento nessa etapa.
 *
 * `irreparavel` deixa de ser (quase) definitivo — ganha volta pra
 * diagnostico, aguardando_orcamento, aguardando_reparo, reparo_execucao e
 * retrabalho, permitindo reavaliar um equipamento antes marcado sem
 * conserto.
 *
 * Nenhum destino é de encerramento (grupo_macro='encerrado'), então todas
 * ficam disponíveis no fluxo normal ("Alterar status"). Mesmo padrão da
 * migration 2026_07_16_000001_add_missing_os_status_transitions: resolve
 * códigos -> ids em runtime, pula pares cujo código não exista no catálogo
 * (idempotente também em bancos parciais, ex.: de teste), e o down() só
 * DESATIVA (ativo=0) — nunca deleta, mesmo comportamento da tela ao
 * desmarcar (OrderStatusFlowService::syncTransitions).
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    private const TRANSITIONS = [
        ['testes_operacionais', 'verificacao_garantia'],
        ['testes_operacionais', 'aguardando_orcamento'],
        ['testes_operacionais', 'reparo_concluido'],
        ['testes_operacionais', 'garantia_concluida'],
        ['testes_operacionais', 'cancelado'],
        ['irreparavel', 'diagnostico'],
        ['irreparavel', 'aguardando_orcamento'],
        ['irreparavel', 'aguardando_reparo'],
        ['irreparavel', 'reparo_execucao'],
        ['irreparavel', 'retrabalho'],
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
