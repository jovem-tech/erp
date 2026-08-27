<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class UnsavedWorkGuardTest extends TestCase
{
    public function test_shell_exposes_a_single_guard_for_every_way_out(): void
    {
        $script = $this->asset('desktop.js');

        // A API precisa existir de forma síncrona: os scripts de página se
        // registram no mesmo DOMContentLoaded do shell.
        $this->assertStringContainsString('window.erpRegisterUnsavedWork = (probe) =>', $script);
        $this->assertStringNotContainsString('const initUnsavedWork', $script);

        $this->assertStringContainsString("window.addEventListener('beforeunload', (event) => {", $script);
        $this->assertStringContainsString('if (!hasUnsavedWork()) {', $script);
        $this->assertStringContainsString("event.returnValue = '';", $script);
    }

    /**
     * O loader de página é armado no clique do link, antes do diálogo. Se o
     * usuário decidir ficar, a navegação não acontece e nem `pageshow` nem
     * `pagehide` disparam — sem esconder o loader à mão, a tela fica coberta
     * para sempre.
     */
    public function test_guard_releases_the_page_loader_when_the_user_stays(): void
    {
        $script = $this->asset('desktop.js');

        $start = strpos($script, "window.addEventListener('beforeunload', (event) => {");
        $this->assertNotFalse($start);

        $guard = substr($script, $start, 1200);

        $this->assertStringContainsString('window.setTimeout(hidePageLoader, 0);', $guard);
    }

    /**
     * Uma sonda quebrada não pode prender o usuário na página.
     */
    public function test_a_broken_probe_does_not_trap_the_user(): void
    {
        $script = $this->asset('desktop.js');

        $start = strpos($script, 'const hasUnsavedWork = () => {');
        $end = strpos($script, 'window.erpRegisterUnsavedWork', (int) $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $body = substr($script, (int) $start, (int) $end - (int) $start);

        $this->assertStringContainsString('try {', $body);
        $this->assertStringContainsString('} catch (error) {', $body);
        $this->assertStringContainsString('return false;', $body);
    }

    /**
     * A sonda do PDV tem que excluir a venda que está sendo enviada — sem isso,
     * toda venda concluída faria o navegador perguntar se o operador quer mesmo
     * sair, justamente no momento de maior pressa do balcão.
     */
    public function test_pdv_probe_ignores_the_sale_being_submitted(): void
    {
        $script = $this->asset('vendas-pdv.js');

        $this->assertStringContainsString('window.erpRegisterUnsavedWork?.(', $script);
        $this->assertStringContainsString(
            "() => !submitLiberado && itensBody.querySelectorAll('.pdv-item').length > 0",
            $script
        );
    }

    /**
     * Esc é a mesma tecla do reflexo de "cancelar"; com carrinho cheio ela
     * precisa perguntar antes, e não apagar direto.
     */
    public function test_escape_asks_before_discarding_the_cart(): void
    {
        $script = $this->asset('vendas-pdv.js');

        $start = strpos($script, "if (evento.key === 'Escape') {");
        $this->assertNotFalse($start);

        $handler = substr($script, (int) $start, 900);

        $this->assertStringContainsString('limparVendaComConfirmacao();', $handler);
        // O caminho direto some do handler: quem apaga é o helper, depois do sim.
        $this->assertStringNotContainsString("itensBody.innerHTML = '';", $handler);
    }

    private function asset(string $file): string
    {
        $path = dirname(__DIR__, 2).'/public/assets/js/'.$file;
        $script = file_get_contents($path);

        $this->assertIsString($script, $file.' deveria ser legível.');

        return (string) $script;
    }
}
