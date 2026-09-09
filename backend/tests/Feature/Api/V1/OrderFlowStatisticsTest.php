<?php

namespace Tests\Feature\Api\V1;

use App\Services\Orders\OrderFlowStatisticsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * A "rota provavel" do Mapa da OS passou a ser MEDIDA do historico real em
 * 09/09/2026. Antes era um Dijkstra sobre o catalogo `os_status_transicoes`
 * mirando um alvo fixo (`reparo_concluido`), com o caminho feliz declarado a
 * mao em 2026-07 — e o catalogo esta congelado desde que o editor da matriz
 * saiu do desktop (23/08/2026).
 */
class OrderFlowStatisticsTest extends TestCase
{
    use BuildsLegacyErpSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedOrderCatalog();
        Cache::flush();
    }

    private function service(): OrderFlowStatisticsService
    {
        return app(OrderFlowStatisticsService::class);
    }

    /** @var array<int, int> apelido do teste (1, 2, 3...) -> id real da OS */
    private array $orderIds = [];

    /**
     * `os_status_historico.os_id` tem FK para `os`, entao cada OS citada pelo
     * teste precisa existir de verdade. O apelido numerico do caso de teste e
     * traduzido para o id real aqui.
     */
    private function orderId(int $alias): int
    {
        if (! isset($this->orderIds[$alias])) {
            $clientId = $this->createClientRecord(['nome_razao' => 'Cliente '.$alias]);
            $equipmentId = $this->createEquipmentRecord($clientId);

            $this->orderIds[$alias] = $this->createOrderRecord([
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
            ]);
        }

        return $this->orderIds[$alias];
    }

    /**
     * Grava a sequencia de status de cada OS SEM preencher `status_anterior` —
     * de proposito: e assim que a maior parte do historico real esta gravada.
     *
     * @param  list<array{0: int, 1: string, 2: string}>  $rows  [apelido da OS, status, timestamp]
     */
    private function seedHistory(array $rows): void
    {
        foreach ($rows as [$alias, $status, $at]) {
            DB::table('os_status_historico')->insert([
                'os_id' => $this->orderId($alias),
                'status_anterior' => null,
                'status_novo' => $status,
                'estado_fluxo' => 'em_atendimento',
                'usuario_id' => null,
                'created_at' => $at,
            ]);
        }
    }

    /**
     * O ponto critico da implementacao: no banco real so 728 das 4.394 linhas
     * de os_status_historico tem `status_anterior` preenchido (o resto veio do
     * legado sem ele). Contar por esse campo perderia a maior parte da
     * historia — o salto e reconstruido com LAG sobre a sequencia de cada OS.
     */
    public function test_hops_are_reconstructed_even_when_status_anterior_is_null(): void
    {
        $this->seedHistory([
            [1, 'triagem', '2026-01-01 09:00:00'],
            [1, 'diagnostico', '2026-01-01 10:00:00'],
            [1, 'reparo_concluido', '2026-01-01 11:00:00'],
        ]);

        $freq = $this->service()->transitionFrequencies(true);

        $this->assertSame('diagnostico', $freq['triagem']['destinos'][0]['para']);
        $this->assertSame(1, $freq['triagem']['destinos'][0]['n']);
        $this->assertSame('reparo_concluido', $freq['diagnostico']['destinos'][0]['para']);
    }

    public function test_destinations_are_ordered_by_real_frequency_with_percentages(): void
    {
        $rows = [];
        $id = 1;

        // 3 OS foram triagem -> diagnostico, 1 foi triagem -> irreparavel.
        foreach (['diagnostico', 'diagnostico', 'diagnostico', 'irreparavel'] as $destino) {
            $rows[] = [$id, 'triagem', '2026-01-01 09:00:00'];
            $rows[] = [$id, $destino, '2026-01-01 10:00:00'];
            $id++;
        }

        $this->seedHistory($rows);

        $destinos = $this->service()->transitionFrequencies(true)['triagem']['destinos'];

        $this->assertSame('diagnostico', $destinos[0]['para']);
        $this->assertSame(3, $destinos[0]['n']);
        $this->assertSame(75.0, $destinos[0]['pct']);
        $this->assertSame('irreparavel', $destinos[1]['para']);
        $this->assertSame(25.0, $destinos[1]['pct']);
    }

    /** Salvar o mesmo status de novo nao e um salto — nao pode virar rota. */
    public function test_self_transitions_are_ignored(): void
    {
        $this->seedHistory([
            [1, 'triagem', '2026-01-01 09:00:00'],
            [1, 'triagem', '2026-01-01 09:30:00'],
            [1, 'diagnostico', '2026-01-01 10:00:00'],
        ]);

        $destinos = $this->service()->transitionFrequencies(true)['triagem']['destinos'];

        $this->assertCount(1, $destinos);
        $this->assertSame('diagnostico', $destinos[0]['para']);
    }

    /** Saltos de OS diferentes nunca podem ser costurados num so. */
    public function test_hops_never_cross_between_orders(): void
    {
        $this->seedHistory([
            [1, 'triagem', '2026-01-01 09:00:00'],
            [2, 'reparo_concluido', '2026-01-01 09:30:00'],
        ]);

        $this->assertSame([], $this->service()->transitionFrequencies(true));
    }

    public function test_map_payload_carries_the_stop_codes_and_the_frozen_catalog(): void
    {
        $this->seedHistory([
            [1, 'triagem', '2026-01-01 09:00:00'],
            [1, 'diagnostico', '2026-01-01 10:00:00'],
        ]);

        $payload = $this->service()->mapPayload(true);

        $this->assertSame(1, $payload['total_saltos']);
        $this->assertSame(OrderFlowStatisticsService::MIN_SAMPLE, $payload['amostra_minima']);

        // O JS precisa saber onde a caminhada para; a regra vem do backend,
        // nunca hardcoded no frontend (skill sistema-erp-os-fluxo-fechamento).
        $this->assertContains('entregue_reparado_pago', $payload['codigos_encerramento']);
        $this->assertContains('cancelado', $payload['codigos_saida']);
        $this->assertIsArray($payload['catalogo_transicoes']);
    }

    public function test_frequencies_are_cached_between_calls(): void
    {
        $this->seedHistory([
            [1, 'triagem', '2026-01-01 09:00:00'],
            [1, 'diagnostico', '2026-01-01 10:00:00'],
        ]);

        $service = $this->service();
        $this->assertSame(1, $service->transitionFrequencies(true)['triagem']['total']);

        // Historico novo nao aparece ate o cache expirar/ser invalidado.
        $this->seedHistory([
            [2, 'triagem', '2026-02-01 09:00:00'],
            [2, 'diagnostico', '2026-02-01 10:00:00'],
        ]);

        $this->assertSame(1, $service->transitionFrequencies()['triagem']['total']);
        $this->assertSame(2, $service->transitionFrequencies(true)['triagem']['total']);
    }

    public function test_empty_history_returns_empty_matrix(): void
    {
        $this->assertSame([], $this->service()->transitionFrequencies(true));
        $this->assertSame(0, $this->service()->mapPayload(true)['total_saltos']);
    }
}
