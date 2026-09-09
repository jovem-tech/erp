<?php

namespace Tests\Unit;

use App\Support\OrderFlowMapLayout;
use App\Support\OrderStatusMacroGroups;
use PHPUnit\Framework\TestCase;

/**
 * O Mapa da OS deixou de ser um SVG estatico em 09/09/2026. Estes casos
 * cobrem exatamente o que estava quebrado antes: mexer no catalogo de status
 * nao mudava nada no desenho.
 */
class OrderFlowMapLayoutTest extends TestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $extra
     * @return array<int, array<string, mixed>>
     */
    private function catalog(array $extra = []): array
    {
        return array_merge([
            ['codigo' => 'triagem', 'nome' => 'Triagem', 'grupo_macro' => 'recepcao', 'ordem_fluxo' => 10],
            ['codigo' => 'diagnostico', 'nome' => 'Diagnóstico Técnico', 'grupo_macro' => 'diagnostico', 'ordem_fluxo' => 20],
            ['codigo' => 'aguardando_peca', 'nome' => 'Aguardando Peça', 'grupo_macro' => 'interrupcao', 'ordem_fluxo' => 120],
            ['codigo' => 'reparo_execucao', 'nome' => 'Em Execução do Serviço', 'grupo_macro' => 'execucao', 'ordem_fluxo' => 80],
            ['codigo' => 'cancelado', 'nome' => 'Cancelado', 'grupo_macro' => 'cancelado', 'ordem_fluxo' => 250],
            ['codigo' => 'entregue_reparado_pago', 'nome' => 'Entregue - Reparado e Pago', 'grupo_macro' => 'encerrado', 'ordem_fluxo' => 220],
        ], $extra);
    }

    public function test_every_active_status_gets_a_card(): void
    {
        $layout = OrderFlowMapLayout::build($this->catalog());

        $this->assertCount(6, $layout['cards']);
        $this->assertArrayHasKey('triagem', $layout['cards']);
        $this->assertArrayHasKey('entregue_reparado_pago', $layout['cards']);
    }

    /**
     * O caso que motivou a mudanca: criar um status na tela "Status de OS"
     * nao aparecia no mapa ate um dev rotear coordenadas no gerador Python.
     */
    public function test_a_status_created_now_shows_up_without_regenerating_anything(): void
    {
        $layout = OrderFlowMapLayout::build($this->catalog([
            ['codigo' => 'triagem_rapida', 'nome' => 'Triagem Rápida', 'grupo_macro' => 'recepcao', 'ordem_fluxo' => 15],
        ]));

        $this->assertArrayHasKey('triagem_rapida', $layout['cards']);
        $this->assertSame('recepcao', $layout['cards']['triagem_rapida']['grupo']);

        // Entrou na raia de Recepcao, abaixo de Triagem (ordem_fluxo 10 < 15).
        $this->assertGreaterThan(
            $layout['cards']['triagem']['y'],
            $layout['cards']['triagem_rapida']['y']
        );
    }

    public function test_renaming_a_status_changes_the_card_label(): void
    {
        $layout = OrderFlowMapLayout::build($this->catalog([
            ['codigo' => 'triagem_rapida', 'nome' => 'Conferência de Entrada', 'grupo_macro' => 'recepcao', 'ordem_fluxo' => 15],
        ]));

        $this->assertSame('Conferência de Entrada', $layout['cards']['triagem_rapida']['nome_completo']);
        $this->assertSame(['Conferência de', 'Entrada'], $layout['cards']['triagem_rapida']['lines']);
    }

    public function test_inactive_status_is_left_out_of_the_map(): void
    {
        $layout = OrderFlowMapLayout::build($this->catalog([
            ['codigo' => 'obsoleto', 'nome' => 'Obsoleto', 'grupo_macro' => 'recepcao', 'ordem_fluxo' => 11, 'ativo' => false],
        ]));

        $this->assertArrayNotHasKey('obsoleto', $layout['cards']);
    }

    /**
     * `grupo_macro` e texto livre no cadastro. Uma fase inventada tem de
     * ganhar raia propria — nunca sumir do desenho.
     */
    public function test_unknown_macro_group_gets_its_own_lane_at_the_end(): void
    {
        $layout = OrderFlowMapLayout::build($this->catalog([
            ['codigo' => 'higienizacao', 'nome' => 'Higienização', 'grupo_macro' => 'pos_venda', 'ordem_fluxo' => 95],
        ]));

        $grupos = array_column($layout['lanes'], 'grupo');

        $this->assertContains('pos_venda', $grupos);
        $this->assertArrayHasKey('higienizacao', $layout['cards']);
        $this->assertSame('Pos Venda', $layout['lanes'][array_search('pos_venda', $grupos, true)]['titulo']);

        // Depois das fases conhecidas de progresso.
        $this->assertGreaterThan(
            array_search('execucao', $grupos, true),
            array_search('pos_venda', $grupos, true)
        );
    }

    public function test_lanes_follow_the_official_chronology_with_waiting_before_execution(): void
    {
        $grupos = array_column(OrderFlowMapLayout::build($this->catalog())['lanes'], 'grupo');

        $this->assertLessThan(
            array_search('execucao', $grupos, true),
            array_search('interrupcao', $grupos, true)
        );

        // Encerramento e sempre o ultimo: so entra pela baixa.
        $this->assertSame(OrderStatusMacroGroups::CLOSURE_GROUP, end($grupos));
    }

    /**
     * Regra central do skill sistema-erp-os-fluxo-fechamento: os status de
     * `grupo_macro='encerrado'` so entram pela baixa. O mapa marca isso.
     */
    public function test_closure_statuses_are_marked_and_sit_behind_the_baixa_port(): void
    {
        $layout = OrderFlowMapLayout::build($this->catalog());

        $this->assertSame('closure', $layout['cards']['entregue_reparado_pago']['kind']);
        $this->assertSame('step', $layout['cards']['triagem']['kind']);

        $this->assertNotNull($layout['port']);
        $this->assertLessThan($layout['cards']['entregue_reparado_pago']['y'], $layout['port']['y']);
    }

    /**
     * O trajeto percorrido e a proxima etapa sugerida sao desenhados na cor do
     * card de destino (PHASE_COLORED em orders-map.js). Se duas macrofases
     * dividissem a mesma cor, a leitura por cor deixaria de funcionar.
     */
    public function test_cards_carry_the_phase_colour_and_phases_are_distinguishable(): void
    {
        $cards = OrderFlowMapLayout::build($this->catalog())['cards'];

        $this->assertSame(
            OrderStatusMacroGroups::flowAccent('recepcao')['color'],
            $cards['triagem']['color']
        );
        $this->assertSame(
            OrderStatusMacroGroups::flowAccent('interrupcao')['color'],
            $cards['aguardando_peca']['color']
        );

        // Fases distintas, cores distintas — Triagem (azul) e Aguardando Peca
        // (amarelo) sao justamente o exemplo do pedido.
        $this->assertNotSame($cards['triagem']['color'], $cards['aguardando_peca']['color']);
    }

    public function test_empty_catalog_does_not_blow_up(): void
    {
        $layout = OrderFlowMapLayout::build([]);

        $this->assertSame([], $layout['cards']);
        $this->assertSame([], $layout['lanes']);
        $this->assertNull($layout['port']);
        $this->assertGreaterThan(0, $layout['width']);
    }

    public function test_long_labels_wrap_and_never_exceed_three_lines(): void
    {
        $this->assertSame(['Triagem'], OrderFlowMapLayout::wrapLabel('Triagem'));
        $this->assertSame(
            ['Entregue -', 'Reparado em', 'Garantia'],
            OrderFlowMapLayout::wrapLabel('Entregue - Reparado em Garantia')
        );

        $lines = OrderFlowMapLayout::wrapLabel('Aguardando conferência final do supervisor técnico responsável');
        $this->assertCount(3, $lines);
        $this->assertStringEndsWith('…', $lines[2]);

        $this->assertSame(['—'], OrderFlowMapLayout::wrapLabel('   '));
    }
}
