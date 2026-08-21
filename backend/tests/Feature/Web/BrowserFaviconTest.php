<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * As respostas sem <head> desta API (PDF de orcamento aberto na aba, arquivo
 * de documento compartilhado) nao declaram icone: o navegador busca
 * /favicon.ico sozinho. A rota nunca pode falhar — aba com icone quebrado e'
 * pior que aba com icone generico.
 */
class BrowserFaviconTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
    }

    public function test_favicon_route_serves_an_icon_without_authentication(): void
    {
        $response = $this->get('/favicon.ico');

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/x-icon');

        $this->assertNotSame('', $response->getContent());
    }

    /**
     * Sem logo cadastrada o icone padrao do ERP entra no lugar, em vez de um
     * 404 que deixaria a aba sem marca nenhuma.
     */
    public function test_favicon_route_falls_back_to_the_default_icon(): void
    {
        $response = $this->get('/favicon.ico');

        $this->assertSame(
            file_get_contents(public_path('assets/img/favicon-default.ico')),
            $response->getContent()
        );
    }
}
