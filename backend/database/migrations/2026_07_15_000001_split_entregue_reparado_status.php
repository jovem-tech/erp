<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Divide o encerramento "Equipamento Entregue" (entregue_reparado) em três
 * códigos explícitos, todos grupo_macro='encerrado', status_final=1:
 *
 *  - entregue_reparado_pago     (renome do antigo entregue_reparado) — reparado,
 *                                 entregue e pago. Único que gera receita
 *                                 (OrderStatus::REVENUE_CLOSURE_CODE).
 *  - entregue_reparado_sem_custo (novo) — reparado e entregue sem custo, R$0,
 *                                 sem lançamentos.
 *  - entregue_reparado_garantia  (novo) — reparado e entregue em cumprimento de
 *                                 garantia, R$0, sem lançamentos.
 *
 * Ver skill sistema-erp-os-fluxo-fechamento. Idempotente e reversível.
 */
return new class extends Migration
{
    private const OLD_CODE = 'entregue_reparado';

    private const PAID_CODE = 'entregue_reparado_pago';

    public function up(): void
    {
        if (! Schema::hasTable('os_status')) {
            return;
        }

        // 1) Renomeia a linha do catálogo (mesma linha, ordem_fluxo preservada).
        //    Só age se o código antigo ainda existir e o novo ainda não — assim
        //    rodar de novo é inofensivo.
        $renamedNome = 'Entregue - Reparado e Pago';
        if (
            DB::table('os_status')->where('codigo', self::OLD_CODE)->exists()
            && ! DB::table('os_status')->where('codigo', self::PAID_CODE)->exists()
        ) {
            DB::table('os_status')
                ->where('codigo', self::OLD_CODE)
                ->update([
                    'codigo' => self::PAID_CODE,
                    'nome' => $renamedNome,
                    'updated_at' => now(),
                ]);
        }

        // Ordem de exibição da linha paga (herda a do antigo 'entregue_reparado',
        // que em produção é 220) — os novos entram logo em seguida.
        $ordemPaga = (int) (DB::table('os_status')
            ->where('codigo', self::PAID_CODE)
            ->value('ordem_fluxo') ?? 220);

        // 2) Insere os dois encerramentos gratuitos (se ainda não existirem),
        //    espelhando as colunas da linha paga.
        $novos = [
            'entregue_reparado_sem_custo' => [
                'nome' => 'Entregue - Reparado Sem Custo',
                'cor' => 'success',
                'ordem_fluxo' => $ordemPaga + 1,
            ],
            'entregue_reparado_garantia' => [
                'nome' => 'Entregue - Reparado em Garantia',
                'cor' => 'info',
                'ordem_fluxo' => $ordemPaga + 2,
            ],
        ];

        foreach ($novos as $codigo => $dados) {
            DB::table('os_status')->updateOrInsert(
                ['codigo' => $codigo],
                [
                    'nome' => $dados['nome'],
                    'grupo_macro' => 'encerrado',
                    'icone' => null,
                    'cor' => $dados['cor'],
                    'ordem_fluxo' => $dados['ordem_fluxo'],
                    'status_final' => 1,
                    'status_pausa' => 0,
                    'gera_evento_crm' => 1,
                    'estado_fluxo_padrao' => 'encerrado',
                    'ativo' => 1,
                    'updated_at' => now(),
                ]
            );
        }

        // 3) Migra os dados das OS existentes: quem estava 'entregue_reparado'
        //    passou a 'entregue_reparado_pago' (todas foram entregas pagas — o
        //    único encerramento de reparo que existia). Cobre também o campo de
        //    resolução de pendência e a auditoria de histórico.
        if (Schema::hasTable('os')) {
            DB::table('os')
                ->where('status', self::OLD_CODE)
                ->update(['status' => self::PAID_CODE]);

            DB::table('os')
                ->where('status_final_pendente_pagamento', self::OLD_CODE)
                ->update(['status_final_pendente_pagamento' => self::PAID_CODE]);
        }

        if (Schema::hasTable('os_status_historico')) {
            DB::table('os_status_historico')
                ->where('status_novo', self::OLD_CODE)
                ->update(['status_novo' => self::PAID_CODE]);

            DB::table('os_status_historico')
                ->where('status_anterior', self::OLD_CODE)
                ->update(['status_anterior' => self::PAID_CODE]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('os_status')) {
            return;
        }

        // Reverte os dados das OS gratuitas para o único encerramento de reparo
        // que existia antes (entregue_reparado) — as gratuitas não tinham
        // equivalente, então reclassifica para o pago (renomeado de volta).
        if (Schema::hasTable('os')) {
            DB::table('os')
                ->whereIn('status', ['entregue_reparado_sem_custo', 'entregue_reparado_garantia'])
                ->update(['status' => self::OLD_CODE]);

            DB::table('os')
                ->where('status', self::PAID_CODE)
                ->update(['status' => self::OLD_CODE]);

            DB::table('os')
                ->where('status_final_pendente_pagamento', self::PAID_CODE)
                ->update(['status_final_pendente_pagamento' => self::OLD_CODE]);
        }

        if (Schema::hasTable('os_status_historico')) {
            DB::table('os_status_historico')
                ->where('status_novo', self::PAID_CODE)
                ->update(['status_novo' => self::OLD_CODE]);

            DB::table('os_status_historico')
                ->where('status_anterior', self::PAID_CODE)
                ->update(['status_anterior' => self::OLD_CODE]);
        }

        DB::table('os_status')
            ->whereIn('codigo', ['entregue_reparado_sem_custo', 'entregue_reparado_garantia'])
            ->delete();

        DB::table('os_status')
            ->where('codigo', self::PAID_CODE)
            ->update([
                'codigo' => self::OLD_CODE,
                'nome' => 'Equipamento Entregue',
                'updated_at' => now(),
            ]);
    }
};
