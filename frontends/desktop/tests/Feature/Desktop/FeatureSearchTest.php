<?php

namespace Tests\Feature\Desktop;

use App\Support\DesktopFeatureCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A busca global tambem localiza telas, menus, abas e botoes do sistema
 * (secao "Funções do sistema"), para o usuario nao precisar lembrar o caminho
 * de "Fluxo de Caixa" ou do simulador de precos escondido numa aba.
 */
class FeatureSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_finds_the_price_simulator_tab_that_is_not_in_the_sidebar(): void
    {
        $this->fakeBackend();

        $response = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar'], 'precificacao' => ['visualizar']]))
            ->getJson('/buscar/sugestoes?q=simulador%20de%20pre%C3%A7os%20de%20pe%C3%A7as&scope=funcionalidades')
            ->assertOk()
            ->assertJsonPath('data.sections.0.key', 'funcionalidades');

        $items = $response->json('data.sections.0.items');
        $this->assertNotEmpty($items);
        $this->assertSame('Simulador de preços', $items[0]['label']);
        $this->assertSame('Funcionalidade', $items[0]['kind']);
        $this->assertStringContainsString('/financeiro/precificacao?tab=simulador', $items[0]['url']);
        $this->assertStringContainsString('Precificação', $items[0]['subtitle']);
    }

    public function test_finds_a_menu_item_by_name_with_its_breadcrumb_and_ignores_accents_and_case(): void
    {
        $this->fakeBackend();
        $session = $this->desktopSession(['dashboard' => ['visualizar'], 'financeiro' => ['visualizar']]);

        foreach (['fluxo de caixa', 'FLUXO caixa', 'fluxo'] as $term) {
            $items = $this
                ->withSession($session)
                ->getJson('/buscar/sugestoes?q=' . rawurlencode($term) . '&scope=funcionalidades')
                ->assertOk()
                ->json('data.sections.0.items');

            $labels = array_column($items, 'label');
            $this->assertContains('Fluxo de Caixa', $labels, "Termo '{$term}' nao achou Fluxo de Caixa");

            $fluxo = $items[array_search('Fluxo de Caixa', $labels, true)];
            $this->assertSame('Financeiro › Relatórios › Fluxo de Caixa', $fluxo['subtitle']);
            $this->assertSame(route('financeiro.relatorios.fluxo-caixa'), $fluxo['url']);
        }

        $items = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar'], 'orcamentos' => ['visualizar']]))
            ->getJson('/buscar/sugestoes?q=orcamentos&scope=funcionalidades')
            ->assertOk()
            ->json('data.sections.0.items');

        $this->assertContains('Orçamentos', array_column($items, 'label'));
    }

    public function test_respects_rbac_per_feature(): void
    {
        $this->fakeBackend();

        // Sem financeiro:visualizar, Fluxo de Caixa nao aparece.
        $payload = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar'], 'clientes' => ['visualizar']]))
            ->getJson('/buscar/sugestoes?q=fluxo%20de%20caixa&scope=funcionalidades')
            ->assertOk()
            ->json('data');

        $labels = array_column($payload['sections'][0]['items'] ?? [], 'label');
        $this->assertNotContains('Fluxo de Caixa', $labels);

        // "Nova OS" exige os:criar — so' visualizar nao basta.
        $labels = array_column(
            $this
                ->withSession($this->desktopSession(['dashboard' => ['visualizar'], 'os' => ['visualizar']]))
                ->getJson('/buscar/sugestoes?q=nova%20os&scope=funcionalidades')
                ->json('data.sections.0.items') ?? [],
            'label'
        );
        $this->assertNotContains('Nova OS', $labels);

        $items = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar'], 'os' => ['visualizar', 'criar']]))
            ->getJson('/buscar/sugestoes?q=nova%20os&scope=funcionalidades')
            ->json('data.sections.0.items');

        $novaOs = collect($items)->firstWhere('label', 'Nova OS');
        $this->assertNotNull($novaOs);
        $this->assertSame('Atalho F1', $novaOs['meta']);
        $this->assertSame(route('orders.create'), $novaOs['url']);
    }

    public function test_feature_scope_alone_does_not_call_the_backend(): void
    {
        Http::fake();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar'], 'os' => ['visualizar']]))
            ->getJson('/buscar/sugestoes?q=painel&scope=funcionalidades')
            ->assertOk()
            ->assertJsonPath('data.sections.0.key', 'funcionalidades')
            ->assertJsonPath('data.sections.0.items.0.label', 'Dashboard')
            ->assertJsonCount(1, 'data.sections');

        Http::assertNothingSent();
    }

    public function test_full_search_lists_features_first_and_still_searches_records(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orders*' => Http::response([
                'status' => 'success',
                'data' => ['orders' => [
                    ['id' => 7, 'numero_os' => 'OS0007', 'cliente_nome' => 'Cliente Caixa', 'status_nome' => 'Aberta', 'status_cor' => '#000'],
                ]],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/*' => Http::response(['status' => 'success', 'data' => [], 'error' => null, 'meta' => []]),
        ]);

        $sections = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar'], 'os' => ['visualizar'], 'caixa' => ['visualizar']]))
            ->getJson('/buscar/sugestoes?q=caixa&scope=tudo')
            ->assertOk()
            ->json('data.sections');

        $this->assertSame('funcionalidades', $sections[0]['key']);
        $this->assertContains('Caixa', array_column($sections[0]['items'], 'label'));
        $this->assertContains('os', array_column($sections, 'key'));
    }

    public function test_search_page_offers_the_feature_scope_and_renders_results(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar'], 'precificacao' => ['visualizar']]))
            ->get('/buscar?scope=funcionalidades&q=simulador')
            ->assertOk()
            ->assertSee('Funções do sistema')
            ->assertSee('Simulador de preços')
            ->assertSee('Precificação › aba Simulador')
            ->assertSee('/financeiro/precificacao?tab=simulador', false);
    }

    public function test_nothing_matches_when_a_term_is_absent(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar'], 'financeiro' => ['visualizar']]))
            ->getJson('/buscar/sugestoes?q=fluxo%20xyzzy&scope=funcionalidades')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonCount(0, 'data.sections');
    }

    /**
     * Rede de seguranca do catalogo curado: toda entrada aponta para rota
     * registrada e tem chave unica. Uma entrada quebrada nao pode passar
     * despercebida so' porque ninguem digitou o termo certo.
     */
    public function test_catalog_entries_all_point_to_registered_routes_with_unique_keys(): void
    {
        $this->fakeBackend();

        // Perfil "tudo liberado" para o catalogo devolver todas as entradas.
        $all = [];
        foreach (['dashboard', 'agenda', 'os', 'orcamentos', 'vendas', 'caixa', 'clientes', 'fornecedores', 'equipamentos', 'servicos', 'estoque', 'fiscal', 'financeiro', 'contas_saldos', 'precificacao', 'conhecimento', 'funcionarios', 'arquivos', 'configuracoes', 'backups', 'usuarios', 'grupos'] as $module) {
            $all[$module] = ['visualizar', 'criar', 'editar', 'exportar', 'importar', 'listar'];
        }

        $this->withSession($this->desktopSession($all))->get('/dashboard')->assertOk();

        $items = DesktopFeatureCatalog::all();
        $keys = array_column($items, 'key');

        $this->assertGreaterThan(80, count($items));
        $this->assertSame($keys, array_values(array_unique($keys)), 'Chaves duplicadas no catalogo');

        foreach ($items as $item) {
            $this->assertTrue(Route::has($item['route']), "Rota inexistente: {$item['route']} ({$item['key']})");
            $this->assertNotSame('', $item['label']);
            $this->assertNotEmpty($item['path'], "Sem caminho: {$item['key']}");
            route($item['route'], $item['params']); // lanca se faltarem parametros obrigatorios
        }

        $labels = array_column($items, 'label');
        $this->assertContains('Simulador de preços', $labels);
        $this->assertContains('Ajuda — Estoque', $labels);
        $this->assertContains('Baixa da OS', $labels);
    }

    private function fakeBackend(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [], 'unread_count' => 0],
                'error' => null,
                'meta' => ['pagination' => [
                    'current_page' => 1, 'per_page' => 6, 'total' => 0,
                    'last_page' => 1, 'from' => 0, 'to' => 0,
                ]],
            ], 200),
            'http://127.0.0.1:8000/api/v1/*' => Http::response([
                'status' => 'success',
                'data' => [],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);
    }

    /**
     * @param  array<string, array<int, string>>  $permissions
     * @return array<string, mixed>
     */
    private function desktopSession(array $permissions): array
    {
        return [
            'desktop_auth' => [
                'token' => 'desktop-session-token',
                'synced_at' => time(),
                'user' => [
                    'id' => 99,
                    'nome' => 'Usuário de Teste',
                    'email' => 'usuario@teste.local',
                    'perfil' => 'admin',
                    'group' => ['id' => 1, 'nome' => 'Administrador', 'sistema' => true],
                    'modules' => array_keys($permissions),
                    'permissions' => $permissions,
                    'foto' => '',
                    'ativo' => true,
                    'assinatura_cadastrada' => true,
                ],
            ],
        ];
    }
}
