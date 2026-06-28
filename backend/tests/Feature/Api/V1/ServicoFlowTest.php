<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class ServicoFlowTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, [
            'servicos' => ['visualizar', 'criar', 'editar', 'excluir', 'encerrar', 'exportar', 'importar'],
        ]);
        $this->grantGroupPermissions(3, [
            'servicos' => ['visualizar'],
        ]);
    }

    public function test_servicos_index_supports_search(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.servicos@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $this->createServiceRecord([
            'nome' => 'Limpeza Interna',
            'descricao' => 'Desmontagem e higienização',
            'tipo_equipamento' => 'Notebook',
        ]);

        $this->createServiceRecord([
            'nome' => 'Troca de Tela',
            'descricao' => 'Substituição de display',
            'tipo_equipamento' => 'Smartphone',
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/servicos?search=Limpeza&per_page=10');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.servicos.0.nome', 'Limpeza Interna')
            ->assertJsonPath('data.servicos.0.tipo_equipamento', 'Notebook');
    }

    public function test_admin_can_crud_close_export_and_import_services(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.servicos.write@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/servicos', [
                'nome' => 'Serviço Operacional',
                'descricao' => 'Serviço criado pelo teste',
                'tipo_equipamento' => 'Notebook',
                'valor' => 150,
                'tempo_padrao_horas' => 2,
                'custo_direto_padrao' => 45,
                'status' => 'ativo',
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.servico.nome', 'Serviço Operacional')
            ->assertJsonPath('data.servico.status', 'ativo');

        $serviceId = (int) $createResponse->json('data.servico.id');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/v1/servicos/' . $serviceId, [
                'nome' => 'Serviço Operacional Atualizado',
                'descricao' => 'Atualizado pelo teste',
                'tipo_equipamento' => 'Notebook',
                'valor' => 175,
                'tempo_padrao_horas' => 2.5,
                'custo_direto_padrao' => 55,
                'status' => 'ativo',
            ])
            ->assertOk()
            ->assertJsonPath('data.servico.nome', 'Serviço Operacional Atualizado');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/v1/servicos/' . $serviceId . '/encerrar')
            ->assertOk()
            ->assertJsonPath('data.servico.status', 'encerrado');

        $this->assertDatabaseHas('servicos', [
            'id' => $serviceId,
            'status' => 'encerrado',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/servicos/exportar-csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = UploadedFile::fake()->createWithContent('servicos.csv', implode("\n", [
            'nome;descricao;tipo_equipamento;valor;tempo_padrao_horas;custo_direto_padrao;status',
            'Serviço CSV;Importado;Notebook;200,00;2,50;50,00;ativo',
        ]));

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post('/api/v1/servicos/importar-lote', [
                'arquivo' => $csv,
            ])
            ->assertOk()
            ->assertJsonPath('data.imported', 1);

        $this->assertDatabaseHas('servicos', [
            'nome' => 'Serviço CSV',
            'status' => 'ativo',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/servicos/' . $serviceId)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('servicos', [
            'id' => $serviceId,
        ]);
    }

    public function test_user_without_service_permissions_receives_403(): void
    {
        $viewer = $this->createUserRecord([
            'nome' => 'Leitura',
            'email' => 'viewer.servicos@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]);

        $token = $this->loginAndGetToken($viewer->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/servicos', [
                'nome' => 'Bloqueado',
                'valor' => 10,
            ])
            ->assertForbidden();
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-servicos',
        ]);

        return (string) $response->json('data.access_token');
    }
}
