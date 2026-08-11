<?php

namespace Tests\Unit;

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
    public function test_status_flow_declares_the_macro_phase_order_with_waiting_before_execution(): void
    {
        $script = (string) file_get_contents($this->desktopPath('public/assets/js/orders-status-modal.js'));

        $start = strpos($script, 'const MACRO_PHASES = [');
        $end = strpos($script, '];', $start === false ? 0 : $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        preg_match_all("/code: '([a-z_]+)'/", substr($script, $start, $end - $start), $matches);

        $this->assertSame(
            ['recepcao', 'diagnostico', 'orcamento', 'interrupcao', 'execucao', 'qualidade', 'concluido'],
            $matches[1]
        );
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
