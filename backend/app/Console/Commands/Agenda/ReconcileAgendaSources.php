<?php

namespace App\Console\Commands\Agenda;

use App\Services\Agenda\AgendaSourceReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ReconcileAgendaSources extends Command
{
    // Sem valor padrao nas opcoes de proposito: o horizonte da varredura vive
    // em AgendaSourceReconciler. Repeti-lo aqui ja causou um bug real - a
    // constante foi ampliada e o default do comando continuou em 180, entao a
    // execucao agendada seguiu cortando o que a classe passara a aceitar.
    protected $signature = 'agenda:sincronizar-origens
        {--dias-atras= : Sobrescreve quantos dias para tras considerar}
        {--dias-a-frente= : Sobrescreve quantos dias para frente considerar}';

    protected $description = 'Traz para a agenda os vencimentos, prazos, retornos e cobrancas dos demais modulos.';

    public function handle(AgendaSourceReconciler $reconciler): int
    {
        // Deploy em que o codigo sobe antes da migration: o scheduler roda a
        // cada 15 minutos e nao pode encher o log de erro nessa janela.
        if (! Schema::hasTable('agenda_compromissos')) {
            $this->warn('Tabela da agenda ainda não migrada — nada a fazer.');

            return self::SUCCESS;
        }

        $diasAtras = $this->option('dias-atras');
        $diasAFrente = $this->option('dias-a-frente');

        $from = $diasAtras !== null && $diasAtras !== ''
            ? CarbonImmutable::now()->subDays(max(0, (int) $diasAtras))->startOfDay()
            : null;
        $to = $diasAFrente !== null && $diasAFrente !== ''
            ? CarbonImmutable::now()->addDays(max(1, (int) $diasAFrente))->endOfDay()
            : null;

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
