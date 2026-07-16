<?php

namespace App\Console\Commands;

use App\Models\OrderStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Preenche data_conclusao das OS's que já estão hoje em um dos
 * OrderStatus::DEADLINE_FREEZE_CODES mas nunca tiveram esse campo carimbado
 * (mecanismo novo — nenhum fluxo existente escrevia nele antes desta feature).
 * Sem isso, o prazo (SLA) dessas OS's continuaria contando para sempre contra
 * now() até a próxima troca de status.
 *
 * Idempotente: só afeta linhas com data_conclusao NULL, então rodar de novo
 * não tem efeito nenhum.
 */
class BackfillOrderDeadlineFinalization extends Command
{
    protected $signature = 'os:backfill-prazo-finalizacao {--os= : Restringe o backfill a uma unica OS (id)}';

    protected $description = 'Preenche data_conclusao (congela o prazo/SLA) das OS ja paradas em um status final, usando status_atualizado_em como referencia.';

    public function handle(): int
    {
        $osId = (int) ($this->option('os') ?? 0);

        $query = DB::table('os')
            ->whereIn('status', OrderStatus::DEADLINE_FREEZE_CODES)
            ->whereNull('data_conclusao');

        if ($osId > 0) {
            $query->where('id', $osId);
        }

        $affected = $query->update(['data_conclusao' => DB::raw('status_atualizado_em')]);

        $this->info(sprintf("OS's atualizadas: %d.", $affected));

        return self::SUCCESS;
    }
}
