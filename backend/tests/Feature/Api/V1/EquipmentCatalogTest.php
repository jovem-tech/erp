<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Tela "Equipamentos" em Cadastros (specs/044-catalogo-equipamentos-csv):
 * listagem de tipos/marcas/modelos, CRUD inline (sem exclusao real) e
 * importacao/exportacao CSV em lote.
 *
 * Fixture herdada de seedEquipmentCatalog() (BuildsLegacyErpSchema):
 * tipos 1=Desktop, 2=Notebook, 3=Smartphone; marcas 1=Dell, 2=Montado,
 * 3=Apple; modelos 1=Inspiron 15 (Dell), 2=Desktop montado (Montado),
 * 3=iPhone 13 (Apple); relacoes (1,2,2) (2,1,1) (3,3,3).
 */
class EquipmentCatalogTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(3, [
            'equipamentos' => ['visualizar', 'criar', 'editar', 'excluir', 'exportar', 'importar'],
        ]);
    }

    public function test_lista_modelos_paginada_com_filtros_e_sem_ancora(): void
    {
        Sanctum::actingAs($this->makeCatalogManager(), ['*']);

        // Cria uma ancora tecnica (marca vinculada a um tipo sem modelo real)
        // para garantir que ela nunca aparece na listagem.
        $this->postJson('/api/v1/equipments/catalog/brands', ['tipo_id' => 1, 'nome' => 'Lenovo'])
            ->assertCreated();

        $response = $this->getJson('/api/v1/equipments/catalog/models');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonCount(3, 'data.modelos');

        $names = collect($response->json('data.modelos'))->pluck('nome')->all();
        $this->assertNotContains('__CATALOG_BRAND_SCOPE__', $names);

        $this->getJson('/api/v1/equipments/catalog/models?tipo_id=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.modelos')
            ->assertJsonPath('data.modelos.0.nome', 'Inspiron 15')
            ->assertJsonPath('data.modelos.0.marca.nome', 'Dell')
            ->assertJsonPath('data.modelos.0.tipos.0.nome', 'Notebook');

        $this->getJson('/api/v1/equipments/catalog/models?marca_id=3')
            ->assertOk()
            ->assertJsonCount(1, 'data.modelos')
            ->assertJsonPath('data.modelos.0.nome', 'iPhone 13');

        $this->getJson('/api/v1/equipments/catalog/models?search=inspiron')
            ->assertOk()
            ->assertJsonCount(1, 'data.modelos')
            ->assertJsonPath('data.modelos.0.nome', 'Inspiron 15');

        $this->getJson('/api/v1/equipments/catalog/models?ativo=0')
            ->assertOk()
            ->assertJsonCount(0, 'data.modelos');
    }

    public function test_lista_marcas_com_contagens_e_tipos(): void
    {
        Sanctum::actingAs($this->makeCatalogManager(), ['*']);

        $response = $this->getJson('/api/v1/equipments/catalog/brands');

        $response->assertOk()->assertJsonCount(3, 'data.marcas');

        $dell = collect($response->json('data.marcas'))->firstWhere('nome', 'Dell');
        $this->assertSame(1, $dell['modelos_ativos']);
        $this->assertSame('Notebook', $dell['tipos'][0]['nome']);

        $this->getJson('/api/v1/equipments/catalog/brands?tipo_id=3')
            ->assertOk()
            ->assertJsonCount(1, 'data.marcas')
            ->assertJsonPath('data.marcas.0.nome', 'Apple');
    }

    public function test_lista_tipos_com_contagens(): void
    {
        Sanctum::actingAs($this->makeCatalogManager(), ['*']);

        $response = $this->getJson('/api/v1/equipments/catalog/types');

        $response->assertOk()->assertJsonCount(3, 'data.tipos');

        $notebook = collect($response->json('data.tipos'))->firstWhere('nome', 'Notebook');
        $this->assertSame(1, $notebook['marcas']);
        $this->assertSame(1, $notebook['modelos']);
    }

    public function test_cria_renomeia_e_desativa_modelo_sem_excluir(): void
    {
        Sanctum::actingAs($this->makeCatalogManager(), ['*']);

        $store = $this->postJson('/api/v1/equipments/catalog/models', [
            'tipo_id' => 2,
            'marca_id' => 1,
            'nome' => 'XPS 13',
        ]);
        $store->assertCreated()->assertJsonPath('data.modelo.nome', 'XPS 13');
        $modelId = (int) $store->json('data.modelo.id');

        $this->patchJson("/api/v1/equipments/catalog/models/{$modelId}", ['nome' => 'XPS 13 Plus'])
            ->assertOk()
            ->assertJsonPath('data.modelo.nome', 'XPS 13 Plus');

        $this->patchJson("/api/v1/equipments/catalog/models/{$modelId}", ['ativo' => false])
            ->assertOk()
            ->assertJsonPath('data.modelo.ativo', false);

        $this->assertDatabaseHas('equipamentos_modelos', [
            'id' => $modelId,
            'nome' => 'XPS 13 Plus',
            'ativo' => 0,
        ]);

        // Renomear para um nome ja usado pela mesma marca falha (422).
        $this->patchJson("/api/v1/equipments/catalog/models/{$modelId}", ['nome' => 'Inspiron 15'])
            ->assertStatus(422);

        // A ancora tecnica nunca pode ser alterada diretamente.
        $anchorId = (int) DB::table('equipamentos_modelos')->where('nome', '__CATALOG_BRAND_SCOPE__')->value('id');
        if ($anchorId === 0) {
            $this->postJson('/api/v1/equipments/catalog/brands', ['tipo_id' => 1, 'nome' => 'Positivo'])->assertCreated();
            $anchorId = (int) DB::table('equipamentos_modelos')->where('nome', '__CATALOG_BRAND_SCOPE__')->value('id');
        }
        $this->assertGreaterThan(0, $anchorId);

        $this->patchJson("/api/v1/equipments/catalog/models/{$anchorId}", ['ativo' => true])
            ->assertStatus(422);
    }

    public function test_cria_marca_ja_vinculada_ao_tipo_via_ancora(): void
    {
        Sanctum::actingAs($this->makeCatalogManager(), ['*']);

        $store = $this->postJson('/api/v1/equipments/catalog/brands', ['tipo_id' => 1, 'nome' => 'Lenovo']);
        $store->assertCreated()->assertJsonPath('data.marca.nome', 'Lenovo');
        $brandId = (int) $store->json('data.marca.id');

        $anchorModelId = (int) DB::table('equipamentos_catalogo_relacoes')
            ->where('tipo_id', 1)
            ->where('marca_id', $brandId)
            ->value('modelo_id');
        $this->assertGreaterThan(0, $anchorModelId);

        $this->assertDatabaseHas('equipamentos_modelos', [
            'id' => $anchorModelId,
            'nome' => '__CATALOG_BRAND_SCOPE__',
            'ativo' => 0,
        ]);

        // A marca ja aparece filtrada pelo tipo mesmo sem nenhum modelo real.
        $this->getJson('/api/v1/equipments/catalog/brands?tipo_id=1')
            ->assertOk()
            ->assertJsonFragment(['nome' => 'Lenovo']);
    }

    public function test_importa_csv_upsert_preservando_casing_e_reportando_erros(): void
    {
        Sanctum::actingAs($this->makeCatalogManager(), ['*']);

        $csv = UploadedFile::fake()->createWithContent('catalogo.csv', implode("\n", [
            'tipo;fabricante;modelo;ativo',
            'Notebook;dell;Inspiron 15;1',
            'Smartphone;Apple;iPhone 15 Pro Max;1',
            'Smartwatch;Apple;Watch SE;1',
            '',
            'Smartphone;Apple;iPhone 15 Pro Max;1',
            'Desktop;Montado;Desktop montado;0',
        ]));

        $response = $this->postJson('/api/v1/equipments/catalog/importar-lote', ['arquivo' => $csv]);

        $response->assertOk()
            ->assertJsonPath('data.resultado.criados.marcas', 0)
            ->assertJsonPath('data.resultado.criados.modelos', 1)
            ->assertJsonPath('data.resultado.ignorados', 1)
            ->assertJsonPath('data.resultado.desativados', 1)
            ->assertJsonCount(1, 'data.resultado.erros')
            ->assertJsonPath('data.resultado.erros.0.linha', 4);

        // "dell" (minusculo, no CSV) casou com a marca existente "Dell" por
        // LOWER(nome) e atualizou o casing para o do arquivo -- e' a regra
        // definida no plano: o CSV e' a fonte curada, entao o casing dele
        // prevalece sobre o que ja estava no banco.
        $this->assertDatabaseHas('equipamentos_marcas', ['id' => 1, 'nome' => 'dell']);
        $this->assertDatabaseCount('equipamentos_marcas', 3);

        $this->assertDatabaseHas('equipamentos_modelos', [
            'marca_id' => 3,
            'nome' => 'iPhone 15 Pro Max',
            'ativo' => 1,
        ]);

        $this->assertDatabaseHas('equipamentos_modelos', [
            'id' => 2,
            'nome' => 'Desktop montado',
            'ativo' => 0,
        ]);
    }

    public function test_importa_csv_rejeita_cabecalho_invalido(): void
    {
        Sanctum::actingAs($this->makeCatalogManager(), ['*']);

        $csv = UploadedFile::fake()->createWithContent('invalido.csv', implode("\n", [
            'coluna_a;coluna_b',
            'x;y',
        ]));

        $this->postJson('/api/v1/equipments/catalog/importar-lote', ['arquivo' => $csv])
            ->assertStatus(422);
    }

    public function test_importa_csv_rejeita_arquivo_acima_do_limite_de_linhas(): void
    {
        Sanctum::actingAs($this->makeCatalogManager(), ['*']);

        $lines = ['tipo;marca;modelo;ativo'];
        for ($i = 0; $i < 5001; $i++) {
            $lines[] = "Notebook;Dell;Modelo {$i};1";
        }

        $csv = UploadedFile::fake()->createWithContent('grande.csv', implode("\n", $lines));

        $this->postJson('/api/v1/equipments/catalog/importar-lote', ['arquivo' => $csv])
            ->assertStatus(422);
    }

    public function test_exporta_csv_e_reimportar_nao_cria_nada_novo(): void
    {
        Sanctum::actingAs($this->makeCatalogManager(), ['*']);

        $export = $this->getJson('/api/v1/equipments/catalog/exportar-csv');
        $export->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = UploadedFile::fake()->createWithContent('exportado.csv', $export->streamedContent());

        $reimport = $this->postJson('/api/v1/equipments/catalog/importar-lote', ['arquivo' => $csv]);

        $reimport->assertOk()
            ->assertJsonPath('data.resultado.criados.marcas', 0)
            ->assertJsonPath('data.resultado.criados.modelos', 0)
            ->assertJsonPath('data.resultado.criados.relacoes', 0)
            ->assertJsonCount(0, 'data.resultado.erros');
    }

    public function test_permissoes_do_catalogo(): void
    {
        $user = $this->createUserRecord([
            'nome' => 'Sem Permissao',
            'email' => 'sem.permissao.catalogo@example.com',
            'perfil' => 'tecnico',
            'grupo_id' => 4,
        ]);
        $this->grantGroupPermissions(4, ['equipamentos' => ['visualizar']]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/equipments/catalog/models')->assertOk();

        $csv = UploadedFile::fake()->createWithContent('x.csv', "tipo;marca;modelo;ativo\nNotebook;Dell;X1;1");
        $this->postJson('/api/v1/equipments/catalog/importar-lote', ['arquivo' => $csv])->assertForbidden();
        $this->getJson('/api/v1/equipments/catalog/exportar-csv')->assertForbidden();
        $this->postJson('/api/v1/equipments/catalog/models', ['tipo_id' => 1, 'marca_id' => 1, 'nome' => 'Y'])->assertForbidden();
    }

    public function test_exporta_csv_inclui_id_do_modelo(): void
    {
        Sanctum::actingAs($this->makeCatalogManager(), ['*']);

        $export = $this->getJson('/api/v1/equipments/catalog/exportar-csv');
        $export->assertOk();

        $csvBody = $export->streamedContent();
        $bom = chr(239).chr(187).chr(191);
        if (str_starts_with($csvBody, $bom)) {
            $csvBody = substr($csvBody, strlen($bom));
        }
        $lines = array_values(array_filter(explode("\n", $csvBody)));
        $header = str_getcsv($lines[0], ';');
        $this->assertSame(['id', 'tipo', 'marca', 'modelo', 'ativo', 'mesclar_com_id'], $header);

        $inspironLine = collect($lines)->first(fn (string $line) => str_contains($line, 'Inspiron 15'));
        $this->assertNotNull($inspironLine);
        $row = str_getcsv($inspironLine, ';');
        $this->assertSame('1', $row[0]); // id do modelo Inspiron 15 (fixture)
    }

    public function test_import_por_id_renomeia_e_reativa_sem_casar_por_nome(): void
    {
        Sanctum::actingAs($this->makeCatalogManager(), ['*']);

        // Modelo 1 = Inspiron 15, marca 1 = Dell, tipo 2 = Notebook (fixture).
        $csv = UploadedFile::fake()->createWithContent('por-id.csv', implode("\n", [
            'id;tipo;marca;modelo;ativo;mesclar_com_id',
            '1;Notebook;Dell;Inspiron 15 3520 Premium;0;',
        ]));

        $response = $this->postJson('/api/v1/equipments/catalog/importar-lote', ['arquivo' => $csv]);

        $response->assertOk()
            ->assertJsonPath('data.resultado.desativados', 1)
            ->assertJsonPath('data.resultado.criados.marcas', 0)
            ->assertJsonPath('data.resultado.criados.modelos', 0)
            ->assertJsonCount(0, 'data.resultado.erros');

        $this->assertDatabaseHas('equipamentos_modelos', [
            'id' => 1,
            'nome' => 'Inspiron 15 3520 Premium',
            'ativo' => 0,
        ]);
        // Nao criou um modelo novo com esse nome (seria o comportamento sem id).
        $this->assertDatabaseCount('equipamentos_modelos', 3 + 0);
    }

    public function test_import_por_id_rejeita_marca_incompativel(): void
    {
        Sanctum::actingAs($this->makeCatalogManager(), ['*']);

        // Modelo 1 pertence a marca Dell (id 1), arquivo diz Apple.
        $csv = UploadedFile::fake()->createWithContent('por-id.csv', implode("\n", [
            'id;tipo;marca;modelo;ativo;mesclar_com_id',
            '1;Notebook;Apple;Inspiron 15;1;',
        ]));

        $response = $this->postJson('/api/v1/equipments/catalog/importar-lote', ['arquivo' => $csv]);

        $response->assertOk()
            ->assertJsonCount(1, 'data.resultado.erros');

        $this->assertStringContainsString('pertence a marca', $response->json('data.resultado.erros.0.motivo'));
    }

    public function test_import_por_id_repetido_com_dados_conflitantes_gera_erro_mas_identico_e_idempotente(): void
    {
        Sanctum::actingAs($this->makeCatalogManager(), ['*']);

        $csvConflitante = UploadedFile::fake()->createWithContent('conflito.csv', implode("\n", [
            'id;tipo;marca;modelo;ativo;mesclar_com_id',
            '1;Notebook;Dell;Inspiron 15 A;1;',
            '1;Notebook;Dell;Inspiron 15 B;1;',
        ]));

        $conflito = $this->postJson('/api/v1/equipments/catalog/importar-lote', ['arquivo' => $csvConflitante]);
        $conflito->assertOk()->assertJsonCount(1, 'data.resultado.erros');
        $this->assertStringContainsString('ja foi processado', $conflito->json('data.resultado.erros.0.motivo'));

        $csvIdentico = UploadedFile::fake()->createWithContent('identico.csv', implode("\n", [
            'id;tipo;marca;modelo;ativo;mesclar_com_id',
            '1;Notebook;Dell;Inspiron 15 A;1;',
            '1;Notebook;Dell;Inspiron 15 A;1;',
        ]));

        $identico = $this->postJson('/api/v1/equipments/catalog/importar-lote', ['arquivo' => $csvIdentico]);
        $identico->assertOk()
            ->assertJsonCount(0, 'data.resultado.erros')
            ->assertJsonPath('data.resultado.ignorados', 1);
    }

    public function test_mesclagem_reponta_equipamentos_orcamentos_e_relacoes_e_desativa_perdedor(): void
    {
        Sanctum::actingAs($this->makeCatalogManager(), ['*']);

        // Duplicata ambigua do modelo 1 (Inspiron 15, Dell, ja vinculado ao
        // tipo Notebook via relacao da fixture).
        $storeResponse = $this->postJson('/api/v1/equipments/catalog/models', [
            'tipo_id' => 2,
            'marca_id' => 1,
            'nome' => 'Inspiron 15 3520',
        ]);
        $storeResponse->assertCreated();
        $loserId = (int) $storeResponse->json('data.modelo.id');

        $clientId = $this->createClientRecord();
        $equipmentId = $this->createEquipmentRecord($clientId, ['modelo_id' => $loserId, 'marca_id' => 1, 'tipo_id' => 2]);
        $orderId = $this->createOrderRecord(['cliente_id' => $clientId, 'equipamento_id' => $equipmentId]);
        $budgetId = $this->createBudgetRecord(['equipamento_modelo_id' => $loserId]);

        $csv = UploadedFile::fake()->createWithContent('mesclagem.csv', implode("\n", [
            'id;tipo;marca;modelo;ativo;mesclar_com_id',
            "{$loserId};Notebook;Dell;Inspiron 15 3520;1;1",
        ]));

        $response = $this->postJson('/api/v1/equipments/catalog/importar-lote', ['arquivo' => $csv]);

        $response->assertOk()
            ->assertJsonPath('data.resultado.mesclados', 1)
            ->assertJsonPath('data.resultado.mesclagens.0.de', $loserId)
            ->assertJsonPath('data.resultado.mesclagens.0.para', 1)
            ->assertJsonCount(0, 'data.resultado.erros');

        $this->assertDatabaseHas('equipamentos', ['id' => $equipmentId, 'modelo_id' => 1]);
        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'equipamento_modelo_id' => 1]);
        $this->assertDatabaseHas('equipamentos_modelos', ['id' => $loserId, 'ativo' => 0]);
        $this->assertDatabaseHas('equipamentos_catalogo_relacoes', [
            'tipo_id' => 2, 'marca_id' => 1, 'modelo_id' => 1, 'ativo' => 1,
        ]);
        $this->assertDatabaseMissing('equipamentos_catalogo_relacoes', ['modelo_id' => $loserId]);

        // Busca da OS foi reindexada: o nome do vencedor aparece, o do
        // perdedor (que so' ele tinha, "3520") nao aparece mais.
        $buscaTexto = (string) DB::table('os')->where('id', $orderId)->value('busca_texto');
        $this->assertStringContainsString('inspiron 15', $buscaTexto);
        $this->assertStringNotContainsString('3520', $buscaTexto);
    }

    public function test_mesclagem_rejeita_marcas_diferentes_alvo_inativo_ele_mesmo_e_cadeia(): void
    {
        Sanctum::actingAs($this->makeCatalogManager(), ['*']);

        // Alvo de marca diferente (modelo 3 = iPhone 13, marca Apple).
        $csvMarcaDiferente = UploadedFile::fake()->createWithContent('a.csv', implode("\n", [
            'id;tipo;marca;modelo;ativo;mesclar_com_id',
            '1;Notebook;Dell;Inspiron 15;1;3',
        ]));
        $respostaMarca = $this->postJson('/api/v1/equipments/catalog/importar-lote', ['arquivo' => $csvMarcaDiferente]);
        $respostaMarca->assertOk()->assertJsonCount(1, 'data.resultado.erros');
        $this->assertStringContainsString('mesma marca', $respostaMarca->json('data.resultado.erros.0.motivo'));

        // Ele mesmo.
        $csvSiMesmo = UploadedFile::fake()->createWithContent('b.csv', implode("\n", [
            'id;tipo;marca;modelo;ativo;mesclar_com_id',
            '1;Notebook;Dell;Inspiron 15;1;1',
        ]));
        $respostaSiMesmo = $this->postJson('/api/v1/equipments/catalog/importar-lote', ['arquivo' => $csvSiMesmo]);
        $respostaSiMesmo->assertOk()->assertJsonCount(1, 'data.resultado.erros');
        $this->assertStringContainsString('ele mesmo', $respostaSiMesmo->json('data.resultado.erros.0.motivo'));

        // Cria dois modelos Dell extras para os cenarios de alvo inativo e cadeia.
        $duplicado = $this->postJson('/api/v1/equipments/catalog/models', ['tipo_id' => 2, 'marca_id' => 1, 'nome' => 'Inspiron 15 Duplicado']);
        $duplicadoId = (int) $duplicado->json('data.modelo.id');
        $terceiro = $this->postJson('/api/v1/equipments/catalog/models', ['tipo_id' => 2, 'marca_id' => 1, 'nome' => 'Inspiron 15 Terceiro']);
        $terceiroId = (int) $terceiro->json('data.modelo.id');

        // Alvo inativo: "Duplicado" tenta se fundir no modelo 1, que esta desativado.
        DB::table('equipamentos_modelos')->where('id', 1)->update(['ativo' => 0]);
        $csvAlvoInativo = UploadedFile::fake()->createWithContent('c.csv', implode("\n", [
            'id;tipo;marca;modelo;ativo;mesclar_com_id',
            "{$duplicadoId};Notebook;Dell;Inspiron 15 Duplicado;1;1",
        ]));
        $respostaAlvoInativo = $this->postJson('/api/v1/equipments/catalog/importar-lote', ['arquivo' => $csvAlvoInativo]);
        $respostaAlvoInativo->assertOk()->assertJsonCount(1, 'data.resultado.erros');
        $this->assertStringContainsString('inativo', $respostaAlvoInativo->json('data.resultado.erros.0.motivo'));
        DB::table('equipamentos_modelos')->where('id', 1)->update(['ativo' => 1]);

        // Cadeia no mesmo arquivo: "Duplicado" funde em 1, e 1 tambem funde em "Terceiro".
        $csvCadeia = UploadedFile::fake()->createWithContent('d.csv', implode("\n", [
            'id;tipo;marca;modelo;ativo;mesclar_com_id',
            "{$duplicadoId};Notebook;Dell;Inspiron 15 Duplicado;1;1",
            "1;Notebook;Dell;Inspiron 15;1;{$terceiroId}",
        ]));
        $respostaCadeia = $this->postJson('/api/v1/equipments/catalog/importar-lote', ['arquivo' => $csvCadeia]);
        $respostaCadeia->assertOk()->assertJsonCount(1, 'data.resultado.erros');
        $this->assertStringContainsString('tambem esta sendo mesclado', $respostaCadeia->json('data.resultado.erros.0.motivo'));
    }

    private function makeCatalogManager()
    {
        return $this->createUserRecord([
            'nome' => 'Gestor de Catalogo',
            'email' => 'catalogo.manager@example.com',
            'perfil' => 'gerente',
            'grupo_id' => 3,
        ]);
    }
}
