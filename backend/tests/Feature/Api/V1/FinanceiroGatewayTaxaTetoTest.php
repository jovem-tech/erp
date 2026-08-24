<?php

namespace Tests\Feature\Api\V1;

use App\Services\Financeiro\FinanceiroGatewayTaxaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Piso e teto na taxa de gateway.
 *
 * A tarifa de Pix cobranca do Inter e' 0,9% com minimo de R$ 0,10 e teto de
 * R$ 1,50. Sem o teto, uma OS de R$ 1.000 apareceria com R$ 9,00 de taxa — e
 * esse numero entra no calculo de margem.
 */
class FinanceiroGatewayTaxaTetoTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
    }

    private function service(): FinanceiroGatewayTaxaService
    {
        return app(FinanceiroGatewayTaxaService::class);
    }

    /**
     * As taxas de gateway NAO vivem em tabela propria: sao um JSON em
     * `configuracoes` (FinanceiroGatewayTaxaService::STORAGE_KEY). Gravar pelo
     * save() do servico e' o unico caminho real.
     */
    private function gravarTaxa(array $extra = []): void
    {
        $this->service()->save(array_merge([
            'provider' => 'inter',
            'modalidade' => 'PIX',
            'taxa_percentual' => 0.9,
            'taxa_fixa' => 0.00,
            'taxa_minima' => 0.10,
            'taxa_teto' => 1.50,
            'ordem_exibicao' => 5,
            'ativo' => true,
        ], $extra));
    }

    public function test_teto_limita_a_taxa_em_valores_altos(): void
    {
        $this->gravarTaxa();

        $r = $this->service()->calculateGrossAmount(1000.00, 'inter', 'PIX');

        // Sem teto seriam R$ 9,08 embutidos. Com teto, R$ 1,50.
        $this->assertEqualsWithDelta(1.50, $r['fee_amount'], 0.001);
        $this->assertEqualsWithDelta(1001.50, $r['charge_amount'], 0.001);
    }

    public function test_abaixo_do_teto_o_percentual_vale_normalmente(): void
    {
        $this->gravarTaxa();

        // R$ 100 -> 0,9% no gross-up da ~R$ 0,91, abaixo do teto.
        $r = $this->service()->calculateGrossAmount(100.00, 'inter', 'PIX');

        $this->assertGreaterThan(0.10, $r['fee_amount']);
        $this->assertLessThan(1.50, $r['fee_amount']);
        $this->assertEqualsWithDelta(100.00 + $r['fee_amount'], $r['charge_amount'], 0.011);
    }

    public function test_piso_eleva_a_taxa_em_valores_muito_baixos(): void
    {
        $this->gravarTaxa();

        // R$ 5 -> 0,9% daria ~R$ 0,05, abaixo do piso de R$ 0,10.
        $r = $this->service()->calculateGrossAmount(5.00, 'inter', 'PIX');

        $this->assertEqualsWithDelta(0.10, $r['fee_amount'], 0.001);
        $this->assertEqualsWithDelta(5.10, $r['charge_amount'], 0.001);
    }

    public function test_ponto_de_virada_fica_proximo_de_166_reais(): void
    {
        $this->gravarTaxa();

        $abaixo = $this->service()->calculateGrossAmount(150.00, 'inter', 'PIX');
        $acima = $this->service()->calculateGrossAmount(200.00, 'inter', 'PIX');

        $this->assertLessThan(1.50, $abaixo['fee_amount']);
        $this->assertEqualsWithDelta(1.50, $acima['fee_amount'], 0.001);
    }

    public function test_taxa_sem_piso_e_sem_teto_calcula_exatamente_como_antes(): void
    {
        // Este e' o caso de TODAS as taxas de cartao ja cadastradas: colunas
        // nulas nao podem mudar uma virgula do resultado.
        $this->gravarTaxa([
            'provider' => 'mercado_pago',
            'modalidade' => 'CREDIT_CARD',
            'taxa_percentual' => 4.99,
            'taxa_fixa' => 0.00,
            'taxa_minima' => null,
            'taxa_teto' => null,
        ]);

        $r = $this->service()->calculateGrossAmount(1000.00, 'mercado_pago', 'CREDIT_CARD');

        // Formula original, intocada: 1000 / (1 - 0,0499) = 1052,52
        $this->assertEqualsWithDelta(1052.52, $r['charge_amount'], 0.02);
        $this->assertEqualsWithDelta(52.52, $r['fee_amount'], 0.02);
        $this->assertNull($r['min_fee']);
        $this->assertNull($r['cap_fee']);
    }

    public function test_taxa_fixa_pura_continua_intacta(): void
    {
        $this->gravarTaxa([
            'provider' => 'asaas',
            'modalidade' => 'PIX',
            'taxa_percentual' => 0,
            'taxa_fixa' => 1.99,
            'taxa_minima' => null,
            'taxa_teto' => null,
        ]);

        $r = $this->service()->calculateGrossAmount(1000.00, 'asaas', 'PIX');

        $this->assertEqualsWithDelta(1.99, $r['fee_amount'], 0.001);
        $this->assertEqualsWithDelta(1001.99, $r['charge_amount'], 0.001);
    }

    public function test_liquido_recebido_bate_com_a_base_quando_o_teto_atua(): void
    {
        $this->gravarTaxa();

        $base = 2500.00;
        $r = $this->service()->calculateGrossAmount($base, 'inter', 'PIX');

        // O ponto do gross-up: o cliente paga charge_amount, o banco retem a
        // tarifa, e o que sobra tem que ser exatamente a base.
        $tarifaReal = min(0.9 / 100 * $r['charge_amount'], 1.50);
        $this->assertEqualsWithDelta($base, $r['charge_amount'] - $tarifaReal, 0.01);
    }

    public function test_inter_aparece_no_catalogo_de_gateways(): void
    {
        $catalogo = $this->service()->catalog();

        $this->assertArrayHasKey('inter', $catalogo);
        $this->assertSame('Banco Inter', $catalogo['inter']['label']);
        $this->assertSame('PIX', $catalogo['inter']['modes'][0]['code']);
    }
}
