<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona 8 transições cadastradas manualmente em Conhecimento > Fluxo da
 * OS (ambiente de homologação, 2026-07-17), formalizando-as como código pra
 * chegarem em qualquer ambiente (produção incluída) via `php artisan
 * migrate`, em vez de depender de alguém repetir o clique na tela em cada
 * banco separadamente.
 *
 * 3 são volta pra `retrabalho` a partir de etapas já bem avançadas da raia
 * CONCLUÍDO (reparo_concluido, reparado_disponivel_loja,
 * garantia_concluida) — cobre o caso de a OS ter chegado perto do fim e
 * precisar ser retrabalhada antes de entregar.
 *
 * As outras 5 apontam para um encerramento (grupo_macro='encerrado'):
 * garantia_concluida -> entregue_reparado_garantia,
 * irreparavel_disponivel_loja -> descartado, reparado_disponivel_loja ->
 * entregue_reparado_sem_custo, reparo_concluido ->
 * entregue_reparado_sem_custo, reparo_recusado -> descartado. Assim como as
 * 17 linhas equivalentes já existentes no catálogo, ficam inertes no fluxo
 * normal (bloqueadas por closure_status_requires_baixa_flow — só a baixa da
 * OS aplica um encerramento); formalizadas aqui só para o catálogo
 * continuar espelhando fielmente o que foi cadastrado na tela.
 *
 * Mesmo padrão das migrations
 * 2026_07_16_000001_add_missing_os_status_transitions e
 * 2026_07_17_000001_add_testes_operacionais_e_irreparavel_transitions:
 * resolve códigos -> ids em runtime, pula pares cujo código não exista no
 * catálogo (idempotente também em bancos parciais, ex.: de teste), e o
 * down() só DESATIVA (ativo=0) — nunca deleta, mesmo comportamento da tela
 * ao desmarcar (OrderStatusFlowService::syncTransitions).
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    private const TRANSITIONS = [
        ['reparo_concluido', 'retrabalho'],
        ['reparado_disponivel_loja', 'retrabalho'],
        ['garantia_concluida', 'retrabalho'],
        ['garantia_concluida', 'entregue_reparado_garantia'],
        ['irreparavel_disponivel_loja', 'descartado'],
        ['reparado_disponivel_loja', 'entregue_reparado_sem_custo'],
        ['reparo_concluido', 'entregue_reparado_sem_custo'],
        ['reparo_recusado', 'descartado'],
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
