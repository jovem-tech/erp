<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class ClientFlowTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();

        $this->grantGroupPermissions(3, [
            'clientes' => ['visualizar'],
        ]);
    }

    public function test_clients_index_supports_status_filter_search_and_sorting(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.clients@example.com',
            'perfil' => 'admin',
            'grupo_id' => null,
        ]);

        $alphaClientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Alpha',
            'status_cadastro' => 'completo',
            'nome_contato' => 'Contato Alpha',
            'telefone1' => '(11) 99999-1111',
            'email' => 'alpha@example.com',
        ]);

        $this->createClientRecord([
            'nome_razao' => 'Cliente Beta',
            'status_cadastro' => 'inativo',
            'telefone1' => '(11) 99999-2222',
            'email' => 'beta@example.com',
        ]);

        $this->createClientRecord([
            'nome_razao' => 'Cliente Zeta',
            'status_cadastro' => 'completo',
            'telefone1' => '(11) 99999-3333',
            'email' => 'zeta@example.com',
        ]);

        $alphaEquipmentId = $this->createEquipmentRecord($alphaClientId, [
            'resumo_tecnico' => 'Notebook Dell Inspiron',
        ]);

        $this->createOrderRecord([
            'cliente_id' => $alphaClientId,
            'equipamento_id' => $alphaEquipmentId,
            'status' => 'triagem',
            'estado_fluxo' => 'em_atendimento',
        ]);

        $this->createOrderRecord([
            'cliente_id' => $alphaClientId,
            'equipamento_id' => $alphaEquipmentId,
            'status' => 'aguardando_reparo',
            'estado_fluxo' => 'em_execucao',
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/clients?status=completo&sort=nome_desc&search=Cliente');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.clients.0.nome_razao', 'Cliente Zeta')
            ->assertJsonPath('data.clients.1.nome_contato', 'Contato Alpha')
            ->assertJsonPath('data.clients.1.nome_razao', 'Cliente Alpha')
            ->assertJsonPath('data.clients.1.orders_count', 2)
            ->assertJsonPath('data.clients.1.equipments_count', 1);
    }

    public function test_admin_can_create_and_update_client(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.clients.write@example.com',
            'perfil' => 'admin',
            'grupo_id' => null,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/clients', [
                'tipo_pessoa' => 'juridica',
                'nome_razao' => 'Cliente Operacional',
                'cpf_cnpj' => '11.111.111/0001-11',
                'rg_ie' => 'ISENTO',
                'email' => 'operacional@example.com',
                'telefone1' => '(11) 98888-0000',
                'telefone2' => '(11) 97777-0000',
                'nome_contato' => 'Contato Operacional',
                'telefone_contato' => '(11) 96666-0000',
                'cep' => '01000-000',
                'endereco' => 'Rua Central',
                'numero' => '100',
                'complemento' => 'Sala 12',
                'referencia' => 'Ao lado do mercado',
                'bairro' => 'Centro',
                'cidade' => 'São Paulo',
                'uf' => 'SP',
                'observacoes' => 'Cliente estratégico',
                'status_cadastro' => 'completo',
                'preferencia_contato' => 'WhatsApp',
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.client.nome_razao', 'Cliente Operacional')
            ->assertJsonPath('data.client.status_cadastro', 'completo');

        $clientId = (int) $createResponse->json('data.client.id');

        $this->assertDatabaseHas('clientes', [
            'id' => $clientId,
            'nome_razao' => 'Cliente Operacional',
            'status_cadastro' => 'completo',
            'preferencia_contato' => 'WhatsApp',
        ]);

        $updateResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/v1/clients/' . $clientId, [
                'tipo_pessoa' => 'juridica',
                'nome_razao' => 'Cliente Operacional Atualizado',
                'cpf_cnpj' => '11.111.111/0001-11',
                'rg_ie' => 'ISENTO',
                'email' => 'operacional@example.com',
                'telefone1' => '(11) 98888-0000',
                'telefone2' => '(11) 97777-0000',
                'nome_contato' => 'Contato Operacional',
                'telefone_contato' => '(11) 96666-0000',
                'cep' => '01000-000',
                'endereco' => 'Rua Central',
                'numero' => '100',
                'complemento' => 'Sala 12',
                'referencia' => 'Ao lado do mercado',
                'bairro' => 'Centro',
                'cidade' => 'São Paulo',
                'uf' => 'SP',
                'observacoes' => 'Cliente estratégico atualizado',
                'status_cadastro' => 'ativo',
                'preferencia_contato' => 'Ligação',
            ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.client.nome_razao', 'Cliente Operacional Atualizado')
            ->assertJsonPath('data.client.status_cadastro', 'ativo');

        $this->assertDatabaseHas('clientes', [
            'id' => $clientId,
            'nome_razao' => 'Cliente Operacional Atualizado',
            'status_cadastro' => 'ativo',
            'preferencia_contato' => 'Ligação',
        ]);
    }

    public function test_user_without_write_permissions_receives_403_for_client_mutations(): void
    {
        $viewer = $this->createUserRecord([
            'nome' => 'Somente leitura',
            'email' => 'viewer.clients@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]);

        $clientId = $this->createClientRecord();
        $token = $this->loginAndGetToken($viewer->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/clients', [
                'tipo_pessoa' => 'fisica',
                'nome_razao' => 'Bloqueado',
                'telefone1' => '(11) 90000-0000',
                'status_cadastro' => 'completo',
            ])
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/v1/clients/' . $clientId, [
                'tipo_pessoa' => 'fisica',
                'nome_razao' => 'Bloqueado',
                'telefone1' => '(11) 90000-0000',
                'status_cadastro' => 'completo',
            ])
            ->assertForbidden();
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-clients',
        ]);

        return (string) $response->json('data.access_token');
    }
}
