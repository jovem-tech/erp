<?php

namespace App\Console\Commands;

use App\Models\OsMargem;
use App\Services\Financeiro\OsMargemService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Recalcula o cache de margem de contribuicao das OS.
 *
 * `os_margem` e cache: cada linha e gravada uma vez, na baixa da OS
 * (OrderWorkflowService::updateStatus -> calcularParaOs), e nunca mais e
 * revisitada. Isso e o comportamento certo no dia a dia — a margem de uma OS
 * entregue nao muda — mas significa que qualquer mudanca na FORMULA so vale
 * para OS futuras ate alguem reprocessar o historico.
 *
 * Foi exatamente o caso da entrega de 2026-08-26, que passou a descontar taxa
 * de recebimento e imposto: sem este comando, as linhas antigas continuam com
 * `custo_taxa_recebimento = 0` e o relatorio segue mostrando a margem inflada
 * de antes, misturada com a nova nas OS recentes — o pior dos dois mundos,
 * porque o numero fica errado E incomparavel entre meses.
 *
 * Roda em simulacao por padrao. Reescrever em massa a base de um relatorio
 * financeiro sem o operador ver antes o tamanho do estrago nao e aceitavel.
 */
class RecalcularMargemOs extends Command
{
    protected $signature = 'financeiro:recalcular-margem
        {--aplicar : Executa de fato; sem esta flag apenas simula}
        {--desde= : Recalcula so OS entregues a partir desta data (YYYY-MM-DD)}';

    protected $description = 'Recalcula a margem de contribuicao das OS com a formula atual (peca, comissao, taxa e imposto).';

    public function handle(OsMargemService $osMargemService): int
    {
        if (! Schema::hasTable('os_margem') || ! Schema::hasTable('os')) {
            $this->warn('Tabelas de margem ou de OS ausentes — nada a fazer.');

            return self::SUCCESS;
        }

        $desde = $this->resolveDesde();
        if ($desde === false) {
            $this->error('--desde precisa estar no formato YYYY-MM-DD.');

            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('aplicar');

        $antes = $this->fotografia($desde);

        if ($antes['total'] === 0) {
            $this->info('Nenhuma OS com margem calculada no recorte informado.');

            return self::SUCCESS;
        }

        $this->info('Situação atual' . ($desde !== null ? ' (entregues desde ' . $desde->toDateString() . ')' : '') . ':');
        $this->table(
            ['OS com margem', 'Margem total', 'Sem taxa apurada', 'Sem horas apontadas'],
            [[
                $antes['total'],
                $this->dinheiro($antes['margem']),
                $antes['sem_taxa'],
                $antes['sem_horas'],
            ]]
        );

        if (! $aplicar) {
            $this->newLine();
            $this->warn('Simulação: nada foi gravado. Rode com --aplicar para reprocessar.');
            $this->line('As ' . $antes['sem_taxa'] . ' OS sem taxa apurada estão com a margem ACIMA da real:');
            $this->line('a taxa de recebimento e o imposto ainda não foram descontados delas.');

            return self::SUCCESS;
        }

        $recalculadas = $osMargemService->recalcularEmLote($desde);

        $depois = $this->fotografia($desde);
        $delta = round($depois['margem'] - $antes['margem'], 2);

        $this->newLine();
        $this->info(sprintf('%d OS reprocessadas.', $recalculadas));
        $this->table(
            ['', 'Margem total'],
            [
                ['Antes', $this->dinheiro($antes['margem'])],
                ['Depois', $this->dinheiro($depois['margem'])],
                ['Diferença', $this->dinheiro($delta)],
            ]
        );

        if ($delta < 0) {
            $this->newLine();
            $this->warn('A margem caiu — era o esperado.');
            $this->line('A diferença é a taxa de recebimento e o imposto que antes não eram descontados.');
            $this->line('O resultado não piorou; ele só deixou de estar otimista por omissão.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{total: int, margem: float, sem_taxa: int, sem_horas: int}
     */
    private function fotografia(?CarbonImmutable $desde): array
    {
        $base = fn (): \Illuminate\Database\Eloquent\Builder => OsMargem::query()
            ->when($desde !== null, function ($query) use ($desde): void {
                $query->whereIn('os_id', function ($sub) use ($desde): void {
                    $sub->select('id')->from('os')->where('data_entrega', '>=', $desde->toDateString());
                });
            });

        return [
            'total' => (int) $base()->count(),
            'margem' => round((float) $base()->sum('margem_contribuicao'), 2),
            'sem_taxa' => (int) $base()->where('custo_taxa_recebimento', '<=', 0)->count(),
            'sem_horas' => (int) $base()->whereNull('tempo_tecnico_horas')->count(),
        ];
    }

    private function resolveDesde(): CarbonImmutable|false|null
    {
        $desde = trim((string) $this->option('desde'));

        if ($desde === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) !== 1) {
            return false;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $desde)->startOfDay();
        } catch (\Throwable) {
            return false;
        }
    }

    private function dinheiro(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }
}
