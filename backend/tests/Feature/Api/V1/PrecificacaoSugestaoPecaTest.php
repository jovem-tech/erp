<?php

namespace Tests\Feature\Api\V1;

use App\Support\VisibilidadeCusto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Sugestao de preco de peca — specs/037, Fase 2.
 *
 * O simulador servia so a tela de precificacao e exigia `financeiro:visualizar`.
 * Quem cadastra peca no dia a dia costuma ser um estoquista sem permissao
 * financeira nenhuma: manter a exigencia deixaria o preco sugerido inacessivel
 * justamente para quem digita o preco.
 */
class PrecificacaoSugestaoPecaTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, ['financeiro' => ['visualizar']]);
        // Estoquista: cadastra peça, não enxerga o financeiro.
        $this->grantGroupPermissions(2, ['estoque' => ['visualizar', 'criar', 'editar']]);
        // Atendente: nenhuma das duas.
        $this->grantGroupPermissions(3, ['os' => ['visualizar']]);
    }

    public function test_estoquista_recebe_sugestao_sem_a_composicao_de_custo(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 2]), ['*']);

        $response = $this->postJson('/api/v1/financeiro/precificacao/simular-peca', [
            'preco_custo' => 100.00,
        ])->assertOk();

        $simulacao = $response->json('data.simulation');

        // Custo 100 + encargos 12% + margem 45% = 157.
        // Os 12% vem dos componentes semeados (4+5+3), que SOBRESCREVEM o
        // percentual manual de 15% quando `usar_componentes` esta ligado —
        // ver PrecificacaoService::buildPieceRules().
        $this->assertEqualsWithDelta(157.0, (float) $simulacao['valor_recomendado'], 0.01);
        $this->assertSame(VisibilidadeCusto::INDICATIVO, $simulacao['visibilidade_custo']);

        // A composição não pode existir no payload de quem não pode vê-la.
        $this->assertArrayNotHasKey('preco_custo_referencia', $simulacao);
        $this->assertArrayNotHasKey('valor_margem', $simulacao);
        $this->assertArrayNotHasKey('percentual_encargos', $simulacao);
    }

    public function test_quem_tem_financeiro_recebe_a_composicao_completa(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $simulacao = $this->postJson('/api/v1/financeiro/precificacao/simular-peca', [
            'preco_custo' => 100.00,
        ])->assertOk()->json('data.simulation');

        $this->assertSame(VisibilidadeCusto::COMPLETO, $simulacao['visibilidade_custo']);
        $this->assertEqualsWithDelta(100.0, (float) $simulacao['preco_custo_referencia'], 0.01);
        $this->assertEqualsWithDelta(45.0, (float) $simulacao['valor_margem'], 0.01);
        $this->assertEqualsWithDelta(12.0, (float) $simulacao['percentual_encargos'], 0.01);
    }

    public function test_quem_nao_cadastra_nem_ve_financeiro_recebe_403(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 3]), ['*']);

        $this->postJson('/api/v1/financeiro/precificacao/simular-peca', ['preco_custo' => 100.00])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PRECIFICACAO_NAO_AUTORIZADO');
    }

    /**
     * Com `respeitar_preco_venda` ligado, o recomendado vira o proprio preco de
     * tabela quando este e maior que o calculado. Sem `valor_calculado`, a dica
     * na tela sugeriria exatamente o numero que ja esta no campo — e leria como
     * quebrada.
     */
    public function test_expoe_o_calculado_alem_do_recomendado(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $simulacao = $this->postJson('/api/v1/financeiro/precificacao/simular-peca', [
            'preco_custo' => 100.00,
            'preco_venda' => 250.00,
        ])->assertOk()->json('data.simulation');

        // Tabela (250) vence o calculado (157) e vira o recomendado...
        $this->assertEqualsWithDelta(250.0, (float) $simulacao['valor_recomendado'], 0.01);
        // ...mas o calculado continua visível, para a tela poder mostrar os dois.
        $this->assertEqualsWithDelta(157.0, (float) $simulacao['valor_calculado'], 0.01);
    }
}
