<?php

namespace Tests\Unit;

use App\Support\OrderStatusMacroGroups;
use PHPUnit\Framework\TestCase;

/**
 * Guardas da aba "Mapa de status" do modal de alteração de status da OS.
 * Ambos os casos aqui são bugs que já aconteceram de verdade (2026-08-09) —
 * ver skill sistema-erp-os-fluxo-fechamento, seção "Mapa de status dentro do
 * modal".
 */
class OrderStatusMapAssetsTest extends TestCase
{
    private function desktopPath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.ltrim($relative, '/');
    }

    /**
     * A aba do mapa depende de window.DesktopOsMap, registrado por
     * orders-map.js. Sem esse script a aba renderiza o SVG estático: sem
     * decoração, sem zoom/pan e sem clique para mover (o mapa fica "travado").
     */
    public function test_every_view_loading_the_status_modal_also_loads_the_map_widget_script(): void
    {
        $views = glob($this->desktopPath('resources/views/orders/*.blade.php'));

        $this->assertNotEmpty($views);

        $checked = 0;

        foreach ($views as $view) {
            $contents = (string) file_get_contents($view);

            if (! str_contains($contents, 'assets/js/orders-status-modal.js')) {
                continue;
            }

            $checked++;

            $this->assertStringContainsString(
                'assets/js/orders-map.js',
                $contents,
                basename($view).' carrega orders-status-modal.js mas não orders-map.js —'
                    .' a aba "Mapa de status" ficaria sem window.DesktopOsMap (mapa travado).'
            );
        }

        // Sanidade: se ninguém mais incluir o modal, o teste vira vácuo.
        $this->assertGreaterThanOrEqual(5, $checked);
    }

    /**
     * Diálogos do SweetAlert2 abertos com o modal do Bootstrap aberto PRECISAM
     * de `target` apontando para o modal. Sem isso o Swal é anexado ao <body>,
     * fora do modal, e o focus trap do Bootstrap devolve o foco para dentro do
     * modal a cada focusin — o operador não consegue digitar no campo do
     * diálogo (bug real: a mensagem ao cliente não podia ser editada).
     */
    public function test_client_message_dialog_is_anchored_inside_the_modal(): void
    {
        $script = (string) file_get_contents($this->desktopPath('public/assets/js/orders-status-modal.js'));

        $start = strpos($script, 'const openClientMessageDialog =');
        $end = strpos($script, 'notifyEl?.addEventListener', $start === false ? 0 : $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $this->assertStringContainsString(
            'target: modalEl',
            substr($script, $start, $end - $start),
            'Sem target: modalEl o focus trap do Bootstrap impede digitar na mensagem ao cliente.'
        );
    }

    public function test_map_dialogs_are_anchored_to_the_modal_when_embedded(): void
    {
        $script = (string) file_get_contents($this->desktopPath('public/assets/js/orders-map.js'));

        // confirmMove() do mapa tem textarea (observação) e input de data; na
        // aba "Mapa de status" ele roda dentro do modal.
        $this->assertStringContainsString("root.closest('.modal')", $script);
    }

    /**
     * A ordem das macrofases no fluxograma da aba "Status" é declarada em
     * MACRO_PHASES, não derivada de os_status.ordem_fluxo — no banco
     * 'interrupcao' (Em espera) viria depois de Execução/Qualidade, mas o
     * fluxo real da OS a coloca logo após Orçamento.
     */
    /**
     * A ordem cronologica das macrofases e decisao de produto (2026-08-10):
     * "Em espera" vem logo depois de Orcamento, e NAO derivada de
     * `os_status.ordem_fluxo` (no banco `interrupcao` e 120-140, cairia depois
     * de Execucao/Qualidade).
     *
     * Desde 09/09/2026 a lista vive em UM lugar so — antes existiam quatro
     * copias divergentes (esta classe, o MACRO_PHASES de
     * orders-status-modal.js, a paleta CSS de _status_modal.blade.php e as
     * LANES do gerador Python do mapa), com tres ordens diferentes entre si.
     */
    public function test_status_flow_declares_the_macro_phase_order_with_waiting_before_execution(): void
    {
        $this->assertSame(
            ['recepcao', 'diagnostico', 'orcamento', 'interrupcao', 'execucao', 'qualidade', 'concluido'],
            OrderStatusMacroGroups::order()
        );

        $this->assertSame(['finalizado_sem_reparo', 'cancelado'], OrderStatusMacroGroups::exitOrder());

        // "Em espera" tem de vir ANTES de "Execucao" na ordenacao real.
        $this->assertLessThan(
            OrderStatusMacroGroups::orderIndex('execucao'),
            OrderStatusMacroGroups::orderIndex('interrupcao')
        );

        // Saidas e encerramento sempre depois das fases de progresso.
        $this->assertGreaterThan(
            OrderStatusMacroGroups::orderIndex('concluido'),
            OrderStatusMacroGroups::orderIndex('cancelado')
        );
        $this->assertGreaterThan(
            OrderStatusMacroGroups::orderIndex('cancelado'),
            OrderStatusMacroGroups::orderIndex('encerrado')
        );
    }

    /**
     * O JS nao pode voltar a declarar a ordem por conta propria: e assim que
     * as copias divergem de novo. Ele consome window.__DESKTOP_OS_FLOW_PHASES,
     * emitido por OrderStatusMacroGroups::toPayload().
     */
    public function test_status_modal_reads_the_macro_phase_order_from_php(): void
    {
        $script = (string) file_get_contents($this->desktopPath('public/assets/js/orders-status-modal.js'));

        $this->assertStringContainsString('window.__DESKTOP_OS_FLOW_PHASES', $script);
        $this->assertStringNotContainsString("{ code: 'recepcao', label:", $script);

        $modal = (string) file_get_contents($this->desktopPath('resources/views/orders/_status_modal.blade.php'));

        $this->assertStringContainsString('OrderStatusMacroGroups::toPayload()', $modal);
    }

    /**
     * O desenho do mapa passou a ser gerado do catalogo vivo em 09/09/2026.
     * Se voltar a existir um artefato estatico, criar/renomear/desativar um
     * status na tela "Status de OS" para de aparecer no mapa outra vez.
     */
    public function test_map_svg_is_generated_from_the_live_catalog(): void
    {
        $svg = (string) file_get_contents($this->desktopPath('resources/views/orders/_flow_map_svg.blade.php'));

        $this->assertStringContainsString('OrderFlowMapLayoutFactory', $svg);
        $this->assertStringContainsString("@foreach (\$layout['cards']", $svg);

        // Nenhum rotulo de status pode estar escrito no template.
        $this->assertStringNotContainsString('Reparo concluído', $svg);
        $this->assertStringNotContainsString('G1 ·', $svg);

        $this->assertFileDoesNotExist(
            dirname(__DIR__, 4).'/scripts/python/diagrama_fluxo_os_organizado.py'
        );
    }

    /**
     * As setas andam pelos vaos entre raias e pelos canais entre linhas de
     * raias — faixas garantidamente vazias. A primeira versao ligava as
     * caixas pelo ponto medio e cortava por cima dos cards que estivessem no
     * caminho.
     *
     * A checagem geometrica de verdade (toda rota x todo card) e feita fora
     * do PHPUnit; aqui o que se protege e a ESTRUTURA: se alguem trocar o
     * roteador por um que ignore os corredores, isto quebra.
     */
    public function test_edge_router_uses_card_free_corridors(): void
    {
        $script = (string) file_get_contents($this->desktopPath('public/assets/js/orders-map.js'));

        $this->assertStringContainsString('const buildCorridors', $script);
        $this->assertStringContainsString('gutterBeside', $script);
        $this->assertStringContainsString('pickChannel', $script);

        // A porta da baixa fica entre a raia de saidas e a de encerramento,
        // na mesma coluna dos cards: o teste de "tem algo no meio?" precisa
        // olhar todos os obstaculos, nao so os cards (bug real corrigido).
        $this->assertStringContainsString('blockedBetween', $script);
        $this->assertStringContainsString('corridors.obstacles', $script);
        $this->assertStringNotContainsString('const cardBetween', $script);
    }

    /**
     * Legenda que nao bate com o desenho e pior que legenda nenhuma: cada
     * amostra tem de existir para a camada de aresta correspondente.
     */
    public function test_legend_matches_the_edge_layers_that_are_drawn(): void
    {
        foreach (['resources/views/orders/map.blade.php', 'resources/views/orders/_status_modal.blade.php'] as $view) {
            $html = (string) file_get_contents($this->desktopPath($view));

            foreach (['traveled', 'route', 'next', 'baixa', 'catalog'] as $layer) {
                $this->assertStringContainsString(
                    "os-map-legend-swatch--{$layer}",
                    $html,
                    "{$view}: falta a amostra da camada '{$layer}' na legenda."
                );
                $this->assertStringContainsString(".os-map-edge.is-{$layer}", $html);
            }

            // Trajeto e proxima etapa sao desenhados na cor da etapa de
            // destino: amostra de cor chapada aqui seria mentira.
            $this->assertMatchesRegularExpression(
                '/\.os-map-legend-swatch--traveled,\s*\n\s*\.os-map-legend-swatch--next\s*\{[^}]*linear-gradient/',
                $html,
                "{$view}: as amostras de trajeto/proxima etapa precisam ser faixa de gradiente, nao cor unica."
            );
            $this->assertStringContainsString('trajeto percorrido (cor da etapa)', $html);
            $this->assertStringContainsString('próxima etapa sugerida (cor da etapa)', $html);

            // Nomes da versao anterior, quando a legenda mostrava "proximas
            // etapas" como bolinha e nao havia overlay de catalogo.
            $this->assertStringNotContainsString('os-map-legend-swatch--suggested', $html);
            $this->assertStringNotContainsString('os-map-legend-dot--clickable', $html);
        }
    }

    /**
     * Pedido do usuario (2026-09-09): o traco assume a cor do card de DESTINO,
     * para dar para seguir o percurso e saber em que fase a OS entrou sem ler
     * rotulo — Triagem -> Aguardando Peca sai amarelo, a cor de "Em espera".
     *
     * A cor vem do proprio no (`data-cor`), nao de uma consulta por macrofase:
     * assim a linha nunca diverge do card, inclusive quando o usuario inventa
     * um grupo_macro novo (o campo e texto livre) e o card cai na cor padrao.
     */
    public function test_travelled_and_next_edges_take_the_destination_colour(): void
    {
        $script = (string) file_get_contents($this->desktopPath('public/assets/js/orders-map.js'));

        $this->assertStringContainsString("const PHASE_COLORED = new Set(['traveled', 'next']);", $script);
        $this->assertStringContainsString("toEl.dataset?.cor", $script);
        $this->assertStringContainsString('osMapArrowFase', $script);

        // A rota provavel e' estatistica e fica azul de proposito; baixa e
        // catalogo sao regra/leitura, nao etapas. Nenhuma delas pode entrar.
        $phaseColored = substr($script, strpos($script, 'const PHASE_COLORED'), 60);
        foreach (['route', 'baixa', 'catalog'] as $layer) {
            $this->assertStringNotContainsString($layer, $phaseColored);
        }
    }

    /**
     * O no precisa carregar a propria cor e cada cor precisa da sua ponta de
     * seta: marker nao herda o stroke do path (`fill="context-stroke"` e' SVG2
     * e nem todo navegador suporta), entao sem isso a seta amarela terminaria
     * com uma ponta verde.
     */
    public function test_map_svg_exposes_node_colour_and_a_marker_per_phase(): void
    {
        $svg = (string) file_get_contents($this->desktopPath('resources/views/orders/_flow_map_svg.blade.php'));

        $this->assertStringContainsString("data-cor=\"{{ \$card['color'] }}\"", $svg);
        $this->assertStringContainsString('osMapArrowFase-{{ $arrowId }}', $svg);
        $this->assertStringContainsString('osMapArrowFaseNext-{{ $arrowId }}', $svg);

        // Os markers de cor fixa continuam existindo: sao o fallback de quem
        // nao tem cor de destino (a porta da baixa) e servem rota/baixa/catalogo.
        foreach (['osMapArrowTraveled', 'osMapArrowRoute', 'osMapArrowNext', 'osMapArrowBaixa', 'osMapArrowCatalog'] as $marker) {
            $this->assertStringContainsString($marker, $svg);
        }
    }

    /**
     * O trajeto percorrido tem de ser DESENHADO, nao procurado entre setas
     * pre-existentes. Bug real: a OS 3654 foi aguardando_reparo ->
     * reparo_concluido (salto que o backend aceita desde 09/08/2026 mas que
     * nao esta em os_status_transicoes) e o mapa nao mostrava linha nenhuma.
     */
    public function test_map_draws_the_travelled_path_even_without_a_catalog_transition(): void
    {
        $script = (string) file_get_contents($this->desktopPath('public/assets/js/orders-map.js'));

        $this->assertStringContainsString("drawEdge(nodesByCode[de], nodesByCode[para], 'traveled'", $script);

        // O grafo de arestas pre-desenhadas nao pode voltar.
        $this->assertStringNotContainsString('edgesByPair', $script);
        $this->assertStringNotContainsString("const DESTINO_FINAL", $script);
    }

    /**
     * O guard de sessão do layout só detecta navegação interna por clique em
     * <a>, submit e F5. Navegação programática precisa se declarar via
     * window.erpMarkInternalNavigation() — senão o pagehide grava "navegador
     * fechado" e a página de destino desloga o usuário sozinha (POST /logout).
     */
    public function test_session_guard_exposes_the_internal_navigation_hook(): void
    {
        $layout = (string) file_get_contents($this->desktopPath('resources/views/layouts/app.blade.php'));

        $this->assertStringContainsString(
            'window.erpMarkInternalNavigation = markInternalNavigation;',
            $layout
        );
    }

    public function test_map_declares_internal_navigation_before_going_to_the_closure_screen(): void
    {
        $script = (string) file_get_contents($this->desktopPath('public/assets/js/orders-map.js'));

        $navigation = strpos($script, 'window.location.href = String(config.closureUrl');

        $this->assertNotFalse($navigation, 'A navegação para a tela de baixa sumiu do orders-map.js.');

        // O hook tem de ser chamado ANTES da navegação, na mesma vizinhança.
        $before = substr($script, max(0, $navigation - 400), 400);

        $this->assertStringContainsString(
            'window.erpMarkInternalNavigation?.()',
            $before,
            'Ir para a baixa pelo mapa sem declarar navegação interna desloga o usuário ao carregar a tela de baixa.'
        );
    }

    /**
     * `status_disponiveis` é o catálogo COMPLETO e inclui os status de baixa
     * (grupo_macro = 'encerrado'). O widget precisa descartá-los ao montar
     * etapaByCode, senão os nós de encerramento viram clicáveis no mapa e o
     * backend recusa com 422 closure_status_requires_baixa_flow.
     */
    public function test_map_widget_drops_closure_statuses_from_the_clickable_catalog(): void
    {
        $script = (string) file_get_contents($this->desktopPath('public/assets/js/orders-map.js'));

        $start = strpos($script, 'const applyState = ');
        $end = strpos($script, 'applyState(config);', $start === false ? 0 : $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $applyState = substr($script, $start, $end - $start);

        $this->assertStringContainsString("=== 'encerrado'", $applyState);
    }
}
