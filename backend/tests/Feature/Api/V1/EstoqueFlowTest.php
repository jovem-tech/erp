<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class EstoqueFlowTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, [
            'estoque' => ['visualizar', 'criar', 'editar', 'excluir', 'encerrar', 'exportar', 'importar'],
        ]);
        $this->grantGroupPermissions(3, [
            'estoque' => ['visualizar'],
        ]);
    }

    public function test_estoque_index_and_low_stock_support_search(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.estoque@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $this->createPecaRecord([
            'codigo' => 'PC00001',
            'nome' => 'Fonte 500W',
            'categoria' => 'Insumos',
            'quantidade_atual' => 2,
            'estoque_minimo' => 3,
        ]);

        $this->createPecaRecord([
            'codigo' => 'PC00002',
            'nome' => 'Memória DDR4',
            'categoria' => 'Componentes',
            'quantidade_atual' => 12,
            'estoque_minimo' => 4,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/estoque?search=Fonte&per_page=10');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.pecas.0.nome', 'Fonte 500W');

        $lowStockResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/estoque/baixo');

        $lowStockResponse->assertOk()
            ->assertJsonPath('data.pecas.0.nome', 'Fonte 500W');
    }

    public function test_admin_can_crud_movements_export_and_import_parts(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.estoque.write@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/estoque', [
                'codigo' => 'PC90000',
                'codigo_fabricante' => 'FAB-900',
                'nome' => 'SSD 1TB',
                'categoria' => 'Armazenamento',
                'tipo_equipamento' => 'Desktop',
                'modelos_compativeis' => 'Universal',
                'fornecedor' => 'Fornecedor Teste',
                'localizacao' => 'B2',
                'preco_custo' => 250,
                'preco_venda' => 390,
                'quantidade_atual' => 8,
                'estoque_minimo' => 2,
                'estoque_maximo' => 15,
                'status' => 'ativo',
                'ativo' => true,
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.peca.nome', 'SSD 1TB')
            ->assertJsonPath('data.peca.codigo', 'PC90000');

        $partId = (int) $createResponse->json('data.peca.id');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/estoque/' . $partId . '/movimentacoes', [
                'tipo' => 'saida',
                'quantidade' => 3,
                'motivo' => 'Uso em OS',
            ])
            ->assertOk()
            ->assertJsonPath('data.peca.quantidade_atual', 5);

        $this->assertDatabaseHas('movimentacoes', [
            'peca_id' => $partId,
            'tipo' => 'saida',
            'quantidade' => 3,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/v1/estoque/' . $partId . '/encerrar')
            ->assertOk()
            ->assertJsonPath('data.peca.ativo', false);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/estoque/exportar-csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = UploadedFile::fake()->createWithContent('estoque.csv', implode("\n", [
            'codigo;codigo_fabricante;nome;categoria;tipo_equipamento;modelos_compativeis;fornecedor;localizacao;preco_custo;preco_venda;quantidade_atual;estoque_minimo;estoque_maximo;status;observacoes',
            'PC99000;FAB-990;Teclado USB;Periféricos;Desktop;Universal;Fornecedor Teste;C9;25,00;45,00;20;5;50;ativo;Importado',
        ]));

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post('/api/v1/estoque/importar-lote', [
                'arquivo' => $csv,
            ])
            ->assertOk()
            ->assertJsonPath('data.imported', 1);

        $this->assertDatabaseHas('pecas', [
            'codigo' => 'PC99000',
            'nome' => 'Teclado USB',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/estoque/' . $partId)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseHas('pecas', [
            'id' => $partId,
            'ativo' => 0,
        ]);
    }

    public function test_user_without_stock_permissions_receives_403(): void
    {
        $viewer = $this->createUserRecord([
            'nome' => 'Leitura',
            'email' => 'viewer.estoque@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]);

        $token = $this->loginAndGetToken($viewer->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/estoque', [
                'nome' => 'Bloqueada',
                'quantidade_atual' => 1,
            ])
            ->assertForbidden();
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-estoque',
        ]);

        return (string) $response->json('data.access_token');
    }
}
