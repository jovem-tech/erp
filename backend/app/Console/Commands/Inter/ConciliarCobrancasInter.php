<?php

namespace App\Console\Commands\Inter;

use App\Jobs\Inter\LiquidarCobrancaInterJob;
use App\Models\Inter\InterCobranca;
use App\Services\Integrations\Inter\InterCredentials;
use App\Services\Integrations\Inter\InterLiquidacaoService;
use Illuminate\Console\Command;

/**
 * Confere as cobrancas Pix abertas contra o Banco Inter.
 *
 * Este comando e' o caminho PRINCIPAL de baixa, nao um plano B. Ele funciona
 * sem webhook, sem porta aberta e sem VPS — o webhook (Fase 6) apenas reduz a
 * latencia de ~15 minutos para ~1 minuto.
 *
 * Tambem e' a rede que pega o que o webhook perderia: entrega que falhou,
 * cobranca emitida com desfecho desconhecido, e qualquer janela em que a
 * integracao ficou fora do ar.
 */
class ConciliarCobrancasInter extends Command
{
    protected $signature = 'inter:conciliar
        {--sincrono : Processa na hora em vez de enfileirar}
        {--limite=100 : Maximo de cobrancas por execucao}';

    protected $description = 'Reconsulta as cobrancas Pix abertas no Banco Inter e registra as baixas confirmadas.';

    public function handle(InterCredentials $credentials, InterLiquidacaoService $liquidacoes): int
    {
        if (! $credentials->estaConfigurado()) {
            $this->line('Integracao com o Banco Inter nao configurada — nada a conciliar.');

            return self::SUCCESS;
        }

        $cobrancas = InterCobranca::query()
            ->paraReconciliar()
            // Cobranca vencida ha muito tempo nao volta a ser paga; continuar
            // consultando gastaria chamada de API para sempre. A folga cobre o
            // Pix pago perto do vencimento que so' aparece depois.
            ->where(function ($q): void {
                $q->whereNull('expira_em')
                    ->orWhere('expira_em', '>=', now()->subDays($this->diasDeFolga()));
            })
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limite')))
            ->get();

        if ($cobrancas->isEmpty()) {
            $this->line('Nenhuma cobranca aberta para conferir.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Cobrancas a conferir: %d', $cobrancas->count()));

        $totais = ['liquidadas' => 0, 'ja_processadas' => 0, 'divergentes' => 0];

        foreach ($cobrancas as $cobranca) {
            if (! $this->option('sincrono')) {
                LiquidarCobrancaInterJob::dispatch((int) $cobranca->id);

                continue;
            }

            $resumo = $liquidacoes->conciliar($cobranca);

            foreach ($totais as $chave => $_) {
                $totais[$chave] += (int) ($resumo[$chave] ?? 0);
            }
        }

        if (! $this->option('sincrono')) {
            $this->info('Cobrancas enfileiradas para conferencia.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Baixas registradas: %d | ja processadas: %d | pendentes de revisao: %d',
            $totais['liquidadas'],
            $totais['ja_processadas'],
            $totais['divergentes']
        ));

        return self::SUCCESS;
    }

    private function diasDeFolga(): int
    {
        return max(0, (int) config('inter.cobranca.conciliar_apos_vencimento_dias', 7));
    }
}
