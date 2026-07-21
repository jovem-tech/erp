<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PageTransitionScriptTest extends TestCase
{
    public function test_page_loader_is_not_armed_by_beforeunload(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/public/assets/js/desktop.js');

        $this->assertIsString($script);

        $start = strpos($script, 'const initPageTransitions = () => {');
        $end = strpos($script, 'const initSidebar = () => {', $start === false ? 0 : $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $pageTransitions = substr($script, $start, $end - $start);

        $this->assertStringContainsString("document.addEventListener('click'", $pageTransitions);
        $this->assertStringContainsString("document.addEventListener('submit'", $pageTransitions);
        $this->assertStringContainsString("window.addEventListener('pageshow', hidePageLoader)", $pageTransitions);
        $this->assertStringNotContainsString("addEventListener('beforeunload'", $pageTransitions);
    }
}
