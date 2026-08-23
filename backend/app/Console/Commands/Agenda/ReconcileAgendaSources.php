<?php

namespace App\Console\Commands\Agenda;

use App\Services\Agenda\AgendaSourceReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ReconcileAgendaSources extends Command
{
    protected $signature = 'agenda:sincronizar-origens
        {--dias-atras=30 : Quantos dias para tras considerar}
        {--dias-a-frente=180 : Quantos dias para frente considerar}';

    protected $description = 'Traz para a agenda os vencimentos, prazos, retornos e cobrancas dos demais modulos.';

    public function handle(AgendaSourceReconciler $reconciler): int
    {
        // Deploy em que o codigo sobe antes da migration: o scheduler roda a
        // cada 15 minutos e nao pode encher o log de erro nessa janela.
        if (! Schema::hasTable('agenda_compromissos')) {
            $this->warn('Tabela da agenda ainda não migrada — nada a fazer.');

            return self::SUCCESS;
        }

        $from = CarbonImmutable::now()->subDays(max(0, (int) $this->option('dias-atras')))->startOfDay();
        $to = CarbonImmutable::now()->addDays(max(1, (int) $this->option('dias-a-frente')))->endOfDay();

        $report = $reconciler->reconcile($from, $to);

        $rows = [];
        foreach ($report as $key => $stats) {
            $rows[] = [
                $key,
                $stats['criados'],
                $stats['atualizados'],
                $stats['concluidos'],
                $stats['cancelados'],
                $stats['erro'] ?? '',
            ];
        }

        $this->table(['Fonte', 'Criados', 'Atualizados', 'Concluídos', 'Cancelados', 'Erro'], $rows);

        return self::SUCCESS;
    }
}
