<?php

namespace App\Jobs\Inter;

use App\Models\Inter\InterCobranca;
use App\Services\Integrations\Inter\InterLiquidacaoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reconsulta uma cobranca no Banco Inter e liquida o que estiver confirmado.
 *
 * ShouldBeUnique por cobranca: o polling roda a cada 15 minutos e o webhook
 * (Fase 6) dispara o mesmo job. Sem isso, os dois caminhos processariam a mesma
 * cobranca ao mesmo tempo.
 *
 * A unicidade do job e' conveniencia, NAO a garantia de idempotencia — ela
 * expira, e o lock do Redis pode cair. A garantia de verdade e o UNIQUE do
 * `e2eid` em `inter_liquidacoes`, no banco de dados.
 */
class LiquidarCobrancaInterJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $cobrancaId
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'inter-liquidacao-'.$this->cobrancaId;
    }

    public function handle(InterLiquidacaoService $liquidacoes): void
    {
        $cobranca = InterCobranca::query()->find($this->cobrancaId);

        if (! $cobranca instanceof InterCobranca) {
            return;
        }

        $liquidacoes->conciliar($cobranca);
    }
}
