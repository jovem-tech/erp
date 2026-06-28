<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class SupplierFlowTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, [
            'fornecedores' => ['visualizar', 'criar', 'editar', 'encerrar', 'excluir'],
        ]);
        $this->grantGroupPermissions(3, [
            'fornecedores' => ['visualizar'],
        ]);
    }

    public function test_suppliers_index_supports_search_and_sorting(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.suppliers@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $this->createSupplierRecord([
            'nome_fantasia' => 'Claro',
            'razao_social' => 'CLARO S.A.',
            'cnpj_cpf' => '40.432.544/0001-47',
            'telefone1' => '(11) 4313-4620',
            'ativo' => 1,
        ]);

        $this->createSupplierRecord([
            'nome_fantasia' => 'Enel Distribuicao Rio',
            'razao_social' => 'AMPLA ENERGIA E SERVICOS S.A.',
            'cnpj_cpf' => '33.050.071/0001-58',
            'telefone1' => '(21) 2716-1101',
            'ativo' => 1,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/suppliers?search=Claro&per_page=15');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.suppliers.0.nome_fantasia', 'Claro')
            ->assertJsonPath('data.suppliers.0.cnpj_cpf', '40.432.544/0001-47');
    }

    public function test_admin_can_create_update_and_delete_supplier(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.suppliers.write@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/suppliers', [
                'tipo_pessoa' => 'juridica',
                'nome_fantasia' => 'Fornecedor Operacional',
                'razao_social' => 'Fornecedor Operacional LTDA',
                'cnpj_cpf' => '11.111.111/0001-11',
                'ie_rg' => 'ISENTO',
                'email' => 'operacional@fornecedor.com',
                'telefone1' => '(11) 98888-0000',
                'telefone2' => '(11) 97777-0000',
                'cep' => '01000-000',
                'endereco' => 'Rua Central',
                'numero' => '100',
                'complemento' => 'Sala 12',
                'bairro' => 'Centro',
                'cidade' => 'São Paulo',
                'uf' => 'SP',
                'observacoes' => 'Fornecedor estratégico',
                'ativo' => true,
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.supplier.nome_fantasia', 'Fornecedor Operacional')
            ->assertJsonPath('data.supplier.tipo_pessoa', 'juridica');

        $supplierId = (int) $createResponse->json('data.supplier.id');

        $this->assertDatabaseHas('fornecedores', [
            'id' => $supplierId,
            'nome_fantasia' => 'Fornecedor Operacional',
            'ativo' => 1,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/v1/suppliers/' . $supplierId, [
                'tipo_pessoa' => 'juridica',
                'nome_fantasia' => 'Fornecedor Operacional Atualizado',
                'razao_social' => 'Fornecedor Operacional LTDA',
                'cnpj_cpf' => '11.111.111/0001-11',
                'telefone1' => '(11) 98888-0000',
                'ativo' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.supplier.nome_fantasia', 'Fornecedor Operacional Atualizado')
            ->assertJsonPath('data.supplier.ativo', false);

        $this->assertDatabaseHas('fornecedores', [
            'id' => $supplierId,
            'nome_fantasia' => 'Fornecedor Operacional Atualizado',
            'ativo' => 0,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/suppliers/' . $supplierId)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('fornecedores', [
            'id' => $supplierId,
        ]);
    }

    public function test_admin_can_close_supplier_without_deleting_it(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.suppliers.close@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $supplierId = $this->createSupplierRecord([
            'nome_fantasia' => 'Fornecedor Encerravel',
            'ativo' => 1,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/v1/suppliers/' . $supplierId . '/encerrar')
            ->assertOk()
            ->assertJsonPath('data.supplier.id', $supplierId)
            ->assertJsonPath('data.supplier.ativo', false);

        $this->assertDatabaseHas('fornecedores', [
            'id' => $supplierId,
            'ativo' => 0,
        ]);
    }

    public function test_supplier_cnpj_lookup_uses_public_provider_payload(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.suppliers.lookup@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        Http::fake([
            'https://brasilapi.com.br/api/cnpj/v1/40432544000147' => Http::response([
                'razao_social' => 'CLARO S.A.',
                'nome_fantasia' => 'Claro',
                'email' => 'contato@claro.com.br',
                'ddd_telefone_1' => '1130000000',
                'ddd_telefone_2' => '1140000000',
                'cep' => '01310930',
                'logradouro' => 'Rua Exemplo',
                'numero' => '100',
                'complemento' => '10 andar',
                'bairro' => 'Centro',
                'municipio' => 'São Paulo',
                'uf' => 'SP',
                'inscricao_estadual' => '123456789',
                'descricao_situacao_cadastral' => 'ATIVA',
            ]),
            'https://publica.cnpj.ws/cnpj/40432544000147' => Http::response([], 404),
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/suppliers/consultar-cnpj?cnpj=40.432.544/0001-47');

        $response->assertOk()
            ->assertJsonPath('data.lookup.success', true)
            ->assertJsonPath('data.lookup.data.nome_fantasia', 'Claro')
            ->assertJsonPath('data.lookup.data.razao_social', 'CLARO S.A.');
    }

    public function test_user_without_supplier_permissions_receives_403(): void
    {
        $viewer = $this->createUserRecord([
            'nome' => 'Somente leitura',
            'email' => 'viewer.suppliers@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]);

        $token = $this->loginAndGetToken($viewer->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/suppliers', [
                'tipo_pessoa' => 'fisica',
                'nome_fantasia' => 'Bloqueado',
                'telefone1' => '(11) 90000-0000',
            ])
            ->assertForbidden();
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-suppliers',
        ]);

        return (string) $response->json('data.access_token');
    }
}
