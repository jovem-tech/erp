<?php

namespace App\Services\Orders;

use App\Models\OrderStatus;
use App\Models\OrderStatusTransition;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Estatisticas do fluxo real das OS: com que frequencia cada transicao de
 * status aconteceu de fato.
 *
 * Existe porque a "rota provavel" do Mapa da OS era, ate 09/09/2026, um
 * Dijkstra sobre o catalogo `os_status_transicoes` mirando um alvo fixo
 * (`reparo_concluido`), com o caminho feliz DECLARADO a mao em 2026-07 no
 * gerador Python do desenho. Duas coisas invalidaram aquilo:
 *
 * 1. desde 09/08/2026 o backend aceita qualquer status ativo nao-encerramento
 *    (`OrderWorkflowService::updateStatus()`), entao o catalogo nao descreve
 *    mais o que as OS fazem;
 * 2. o editor da matriz de transicoes saiu do desktop em 23/08/2026, entao
 *    `os_status_transicoes` esta congelado e ninguem consegue mais mante-lo.
 *
 * A rota agora e MEDIDA: sai do que as OS de verdade percorreram.
 *
 * Este servico devolve a matriz do catalogo inteiro (nao por OS) de proposito:
 * um unico payload cacheado serve a pagina cheia do mapa e a aba do modal, e o
 * frontend recalcula a rota na hora em que a OS se move, sem round trip.
 */
class OrderFlowStatisticsService
{
    private const CACHE_KEY = 'os-flow:transition-frequencies:v1';

    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Abaixo disso a amostra nao sustenta uma sugestao: e ruido de uma OS
     * isolada, nao um caminho. A caminhada para em vez de inventar rota.
     */
    public const MIN_SAMPLE = 3;

    /**
     * Frequencia das transicoes que realmente aconteceram, agrupadas por
     * status de origem e ordenadas da mais comum para a menos comum.
     *
     * @return array<string, array{total: int, destinos: list<array{para: string, n: int, pct: float}>}>
     */
    public function transitionFrequencies(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->computeTransitionFrequencies()
        );
    }

    /**
     * @return array<string, array{total: int, destinos: list<array{para: string, n: int, pct: float}>}>
     */
    private function computeTransitionFrequencies(): array
    {
        // NAO usar `status_anterior` diretamente: no banco real so 728 das
        // 4.394 linhas de os_status_historico tem esse campo preenchido (as
        // demais vieram do legado sem ele). Reconstruir o salto com LAG sobre
        // a sequencia de cada OS recupera a historia inteira — 746 saltos em
        // 234 OS na medicao de 09/09/2026, contra 728 pelo campo cru.
        $rows = DB::table(DB::raw('(
            SELECT
                LAG(status_novo) OVER (PARTITION BY os_id ORDER BY created_at, id) AS de,
                status_novo AS para
            FROM os_status_historico
        ) AS saltos'))
            ->select('de', 'para', DB::raw('COUNT(*) AS n'))
            ->whereNotNull('de')
            ->whereColumn('de', '!=', 'para')
            ->groupBy('de', 'para')
            ->get();

        $byOrigin = [];

        foreach ($rows as $row) {
            $de = trim((string) $row->de);
            $para = trim((string) $row->para);

            if ($de === '' || $para === '') {
                continue;
            }

            $byOrigin[$de][$para] = (int) $row->n;
        }

        $result = [];

        foreach ($byOrigin as $de => $destinos) {
            $total = array_sum($destinos);

            if ($total <= 0) {
                continue;
            }

            arsort($destinos);

            $result[$de] = [
                'total' => $total,
                'destinos' => array_map(
                    static fn (string $para): array => [
                        'para' => $para,
                        'n' => $destinos[$para],
                        'pct' => round($destinos[$para] * 100 / $total, 1),
                    ],
                    array_keys($destinos)
                ),
            ];
        }

        return $result;
    }

    /**
     * Pares (origem, destino) ativos do catalogo, ja resolvidos para codigo.
     *
     * @return list<array{de: string, para: string}>
     */
    private function catalogTransitions(): array
    {
        $codes = OrderStatus::query()->pluck('codigo', 'id')->all();

        return OrderStatusTransition::query()
            ->where('ativo', true)
            ->get()
            ->map(static function (OrderStatusTransition $transition) use ($codes): ?array {
                $de = $codes[(int) $transition->status_origem_id] ?? null;
                $para = $codes[(int) $transition->status_destino_id] ?? null;

                return ($de === null || $para === null) ? null : ['de' => (string) $de, 'para' => (string) $para];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Payload consumido pelo Mapa da OS: a matriz medida mais os metadados
     * que o frontend precisa para caminhar por ela sem reimplementar regra.
     *
     * @return array<string, mixed>
     */
    public function mapPayload(bool $fresh = false): array
    {
        $frequencias = $this->transitionFrequencies($fresh);

        return [
            'transicoes' => $frequencias,
            // Catalogo cadastrado (os_status_transicoes) — material do overlay
            // opcional do mapa, desligado por padrao. Continua exposto porque
            // ainda alimenta `proximas_etapas`, mas esta congelado desde que o
            // editor da matriz saiu do desktop em 23/08/2026: e leitura do que
            // ja foi desenhado, nao regra do que pode acontecer.
            'catalogo_transicoes' => $this->catalogTransitions(),
            'amostra_minima' => self::MIN_SAMPLE,
            'total_saltos' => array_sum(array_column($frequencias, 'total')),
            // O frontend precisa saber onde a caminhada termina. Os codigos de
            // encerramento e de saida vem do backend (fonte da verdade da
            // regra — ver skill sistema-erp-os-fluxo-fechamento), nunca
            // hardcoded no JS.
            'codigos_encerramento' => OrderStatus::closureCodes(),
            'codigos_saida' => OrderStatus::flowExitCodes(),
        ];
    }
}
