<?php

namespace Tests\Feature\Desktop;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tela "Equipamentos" em Cadastros (specs/044-catalogo-equipamentos-csv):
 * listagem por abas (Modelos/Marcas/Tipos), CRUD inline sem exclusão real e
 * importação/exportação CSV. O desktop nunca fala com o banco — tudo aqui
 * é BFF sobre a API central em http://127.0.0.1:8000/api/v1 (fakeada).
 */
class EquipmentCatalogTest extends TestCase
{
    private function fakePaginateModels(array $items, array $paginationOverrides = []): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/equipments/catalog/models*' => Http::response([
                'status' => 'success',
                'data' => ['modelos' => $items],
                'error' => null,
                'meta' => [
                    'pagination' => array_merge([
                        'current_page' => 1,
                        'per_page' => 15,
                        'total' => count($items),
                        'last_page' => 1,
                        'from' => count($items) > 0 ? 1 : 0,
                        'to' => count($items),
                    ], $paginationOverrides),
                ],
            ], 200),
            'http://127.0.0.1:8000/api/v1/equipments/catalog/brands*' => Http::response([
                'status' => 'success',
                'data' => ['marcas' => []],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/equipments/catalog/types*' => Http::response([
                'status' => 'success',
                'data' => ['tipos' => []],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);
    }

    public function test_menu_equipamentos_aparece_em_cadastros_com_permissao(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/equipments/catalog/*' => Http::response([
                'status' => 'success',
                'data' => ['modelos' => [], 'marcas' => [], 'tipos' => []],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $this
            ->withSession($this->desktopSession(['equipamentos' => ['visualizar']]))
            ->get('/equipamentos/catalogo')
            ->assertOk()
            ->assertSee('Cadastros')
            ->assertSee('Equipamentos');
    }

    public function test_menu_equipamentos_some_sem_permissao(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/suppliers*' => Http::response([
                'status' => 'success',
                'data' => ['suppliers' => []],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0]],
            ], 200),
        ]);

        $this
            ->withSession($this->desktopSession(['fornecedores' => ['visualizar']]))
            ->get('/fornecedores')
            ->assertOk()
            ->assertDontSee('Equipamentos');
    }

    public function test_aba_modelos_lista_e_mantem_filtros_na_paginacao(): void
    {
        $this->fakePaginateModels([
            [
                'id' => 10,
                'nome' => 'Galaxy S24 Ultra',
                'ativo' => true,
                'marca' => ['id' => 5, 'nome' => 'Samsung', 'ativo' => true],
                'tipos' => [['id' => 2, 'nome' => 'Smartphone']],
            ],
        ], ['current_page' => 2, 'last_page' => 3, 'total' => 40, 'from' => 16, 'to' => 30]);

        $response = $this
            ->withSession($this->desktopSession(['equipamentos' => ['visualizar']]))
            ->get('/equipamentos/catalogo?aba=modelos&tipo_id=2&marca_id=5&page=2');

        $response
            ->assertOk()
            ->assertSee('Galaxy S24 Ultra')
            ->assertSee('Samsung')
            ->assertSee('Smartphone');

        // Paginação precisa preservar aba/tipo_id/marca_id ao trocar de página.
        $response->assertSee('aba=modelos', false);
        $response->assertSee('tipo_id=2', false);
        $response->assertSee('marca_id=5', false);

        Http::assertSent(function ($request) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/equipments/catalog/models')
                && ($query['tipo_id'] ?? null) == 2
                && ($query['marca_id'] ?? null) == 5
                && ($query['page'] ?? null) == 2;
        });
    }

    public function test_abas_marcas_e_tipos_renderizam_contagens(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/equipments/catalog/brands*' => Http::response([
                'status' => 'success',
                'data' => ['marcas' => [
                    ['id' => 5, 'nome' => 'Samsung', 'ativo' => true, 'modelos_ativos' => 12, 'tipos' => [['id' => 2, 'nome' => 'Smartphone']]],
                ]],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/equipments/catalog/types*' => Http::response([
                'status' => 'success',
                'data' => ['tipos' => [
                    ['id' => 2, 'nome' => 'Smartphone', 'ativo' => true, 'marcas' => 8, 'modelos' => 120],
                ]],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $this
            ->withSession($this->desktopSession(['equipamentos' => ['visualizar']]))
            ->get('/equipamentos/catalogo?aba=marcas')
            ->assertOk()
            ->assertSee('Samsung')
            ->assertSee('12');

        $this
            ->withSession($this->desktopSession(['equipamentos' => ['visualizar']]))
            ->get('/equipamentos/catalogo?aba=tipos')
            ->assertOk()
            ->assertSee('Smartphone')
            ->assertSee('120');
    }

    public function test_salvar_modelo_faz_post_na_api_e_redireciona_com_flash(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments/catalog/models' => Http::response([
                'status' => 'success',
                'data' => ['modelo' => ['id' => 99, 'nome' => 'Galaxy S25', 'ativo' => true, 'marca_id' => 5]],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $this
            ->withSession($this->desktopSession(['equipamentos' => ['criar']]))
            ->post('/equipamentos/catalogo/modelos', [
                'tipo_id' => 2,
                'marca_id' => 5,
                'nome' => 'Galaxy S25',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/equipments/catalog/models')
                && $request->data()['nome'] === 'Galaxy S25';
        });
    }

    public function test_desativar_marca_envia_patch_ativo_false(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments/catalog/brands/5' => Http::response([
                'status' => 'success',
                'data' => ['marca' => ['id' => 5, 'nome' => 'Samsung', 'ativo' => false]],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $this
            ->withSession($this->desktopSession(['equipamentos' => ['editar']]))
            ->patch('/equipamentos/catalogo/marcas/5/ativo', ['ativo' => '0'])
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/equipments/catalog/brands/5')
                && $request->data()['ativo'] === false;
        });
    }

    public function test_importar_csv_mostra_relatorio_com_erros_por_linha(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments/catalog/importar-lote' => Http::response([
                'status' => 'success',
                'data' => ['resultado' => [
                    'linhas' => 3,
                    'criados' => ['marcas' => 0, 'modelos' => 1, 'relacoes' => 1],
                    'atualizados' => 0,
                    'reativados' => 0,
                    'desativados' => 0,
                    'ignorados' => 0,
                    'erros' => [
                        ['linha' => 3, 'motivo' => 'Tipo "Smartwatch" nao existe — cadastre o tipo antes de importar.'],
                    ],
                ]],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $csv = UploadedFile::fake()->createWithContent('catalogo.csv', "tipo;marca;modelo;ativo\nSmartphone;Apple;iPhone 15;1");

        $response = $this
            ->withSession($this->desktopSession(['equipamentos' => ['importar']]))
            ->post('/equipamentos/catalogo/importar-lote', ['arquivo' => $csv]);

        $response
            ->assertRedirect(route('equipments.catalog.index'))
            ->assertSessionHas('success')
            ->assertSessionHas('catalog_import_report');

        $report = $response->getSession()->get('catalog_import_report');
        $this->assertSame(1, $report['criados']['modelos']);
        $this->assertCount(1, $report['erros']);
    }

    public function test_aba_modelos_mostra_a_coluna_id(): void
    {
        $this->fakePaginateModels([
            [
                'id' => 321,
                'nome' => 'Galaxy S24 Ultra',
                'ativo' => true,
                'marca' => ['id' => 5, 'nome' => 'Samsung', 'ativo' => true],
                'tipos' => [['id' => 2, 'nome' => 'Smartphone']],
            ],
        ]);

        $this
            ->withSession($this->desktopSession(['equipamentos' => ['visualizar']]))
            ->get('/equipamentos/catalogo?aba=modelos')
            ->assertOk()
            ->assertSee('321');
    }

    public function test_importar_csv_mostra_relatorio_de_mesclagens(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments/catalog/importar-lote' => Http::response([
                'status' => 'success',
                'data' => ['resultado' => [
                    'linhas' => 1,
                    'criados' => ['marcas' => 0, 'modelos' => 0, 'relacoes' => 0],
                    'atualizados' => 0,
                    'reativados' => 0,
                    'desativados' => 0,
                    'ignorados' => 0,
                    'mesclados' => 1,
                    'mesclagens' => [
                        ['de' => 42, 'para' => 1],
                    ],
                    'erros' => [],
                ]],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $csv = UploadedFile::fake()->createWithContent('mesclagem.csv', "id;tipo;marca;modelo;ativo;mesclar_com_id\n42;Notebook;Dell;Inspiron 15 Dup;1;1");

        $response = $this
            ->withSession($this->desktopSession(['equipamentos' => ['importar']]))
            ->post('/equipamentos/catalogo/importar-lote', ['arquivo' => $csv]);

        $response
            ->assertRedirect(route('equipments.catalog.index'))
            ->assertSessionHas('success');

        $report = $response->getSession()->get('catalog_import_report');
        $this->assertSame(1, $report['mesclados']);
        $this->assertSame(['de' => 42, 'para' => 1], $report['mesclagens'][0]);
    }

    public function test_importar_sem_arquivo_valido_volta_com_erro(): void
    {
        $this
            ->withSession($this->desktopSession(['equipamentos' => ['importar']]))
            ->post('/equipamentos/catalogo/importar-lote', [])
            ->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionHasErrors('arquivo');
    }

    public function test_botoes_de_importar_e_exportar_respeitam_permissoes(): void
    {
        $this->fakePaginateModels([]);

        $this
            ->withSession($this->desktopSession(['equipamentos' => ['visualizar']]))
            ->get('/equipamentos/catalogo')
            ->assertOk()
            ->assertDontSee('Exportar CSV')
            ->assertDontSee('Importar em lote')
            ->assertDontSee('Modelo CSV');

        // Acesso direto à rota sem a permissão redireciona (nunca chega na API).
        $this
            ->withSession($this->desktopSession(['equipamentos' => ['visualizar']]))
            ->get('/equipamentos/catalogo/exportar-csv')
            ->assertRedirect();
    }

    /**
     * Sem `data-no-page-loader`, o overlay global de "Carregando página"
     * (desktop.js `shouldHandleLinkNavigation`) fica preso para sempre nesses
     * links: ele só se esconde em `pageshow`/`pagehide`, e um download de
     * arquivo nunca dispara nenhum dos dois (a página não navega).
     */
    public function test_links_de_exportar_e_modelo_csv_nao_travam_no_loader_de_pagina(): void
    {
        $this->fakePaginateModels([]);

        $this
            ->withSession($this->desktopSession(['equipamentos' => ['visualizar', 'exportar', 'importar']]))
            ->get('/equipamentos/catalogo')
            ->assertOk()
            ->assertSee('href="'.route('equipments.catalog.export.csv').'" class="dropdown-item" data-no-page-loader="true"', false)
            ->assertSee('href="'.route('equipments.catalog.download-template').'" class="dropdown-item" data-no-page-loader="true"', false);
    }

    public function test_ajuda_traz_o_prompt_pronto_para_revisar_com_ia(): void
    {
        $this
            ->withSession($this->desktopSession(['equipamentos' => ['visualizar']]))
            ->get('/equipamentos/catalogo/ajuda')
            ->assertOk()
            ->assertSee('Prompt pronto para revisar o CSV com uma IA')
            ->assertSee('id;tipo;marca;modelo;ativo;mesclar_com_id', false)
            ->assertSee('mesclar_com_id', false);
    }

    /**
     * @param array<string, array<int, string>> $permissions
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

    /**
     * @return array<string, mixed>
     */
    private function fakeNotificationsPayload(): array
    {
        return [
            'status' => 'success',
            'data' => ['items' => [], 'unread_count' => 0],
            'error' => null,
            'meta' => [
                'pagination' => ['current_page' => 1, 'per_page' => 6, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0],
            ],
        ];
    }
}
