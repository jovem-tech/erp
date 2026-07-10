<?php

namespace Tests\Feature\Api\V1;

use App\Services\Auth\RbacAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class RbacAdministrationTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();
        $this->seedOrderNumberConfiguration();

        $this->grantGroupPermissions(1, [
            'usuarios' => ['visualizar', 'criar', 'editar', 'excluir'],
            'grupos' => ['visualizar', 'criar', 'editar', 'excluir'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
            'os' => ['visualizar', 'criar', 'editar'],
        ]);
        $this->grantGroupPermissions(3, [
            'clientes' => ['visualizar'],
        ]);
    }

    public function test_legacy_admin_fallback_is_logged_and_returns_effective_permissions(): void
    {
        Log::spy();

        $legacyAdmin = $this->createUserRecord([
            'nome' => 'Admin Legado',
            'email' => 'admin.legado@example.com',
            'perfil' => 'admin',
            'grupo_id' => null,
        ]);

        $token = $this->loginAndGetToken($legacyAdmin->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.group', null);

        $modules = $response->json('data.modules', []);
        $permissions = $response->json('data.permissions', []);

        $this->assertContains('os', $modules);
        $this->assertContains('usuarios', $modules);
        $this->assertContains('editar', $permissions['os']);
        $this->assertContains('excluir', $permissions['grupos']);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Fallback legado perfil=admin') && (int) ($context['user_id'] ?? 0) === $legacyAdmin->id);
    }

    public function test_clients_and_equipments_endpoints_return_searchable_results(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $alphaClient = $this->createClientRecord([
            'nome_razao' => 'Cliente Alpha',
            'cpf_cnpj' => '11.111.111/0001-11',
            'email' => 'alpha@example.com',
        ]);
        $betaClient = $this->createClientRecord([
            'nome_razao' => 'Cliente Beta',
            'cpf_cnpj' => '22.222.222/0001-22',
            'email' => 'beta@example.com',
            'nome_contato' => 'Contato Secundario',
        ]);

        $alphaEquipment = $this->createEquipmentRecord($alphaClient, [
            'resumo_tecnico' => 'MacBook Pro 14',
            'numero_serie' => 'MAC-001',
        ]);
        $this->createEquipmentRecord($betaClient, [
            'resumo_tecnico' => 'All-in-one Office',
            'numero_serie' => 'DESK-002',
            'desktop_modalidade' => 'mobile',
        ]);

        $this->createOrderRecord([
            'cliente_id' => $alphaClient,
            'equipamento_id' => $alphaEquipment,
            'numero_os' => 'OS26010021',
            'status' => 'triagem',
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/clients?search=Alpha')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.clients.0.id', $alphaClient);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/clients?search=Contato Principal')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.clients.0.id', $alphaClient);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/clients/' . $betaClient)
            ->assertOk()
            ->assertJsonPath('data.client.id', $betaClient)
            ->assertJsonPath('data.client.nome_razao', 'Cliente Beta');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/equipments?search=MacBook')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.equipments.0.id', $alphaEquipment)
            ->assertJsonPath('data.equipments.0.orders_count', 1);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/equipments?search=desktop')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.equipments.0.id', $alphaEquipment);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/equipments?client_id=' . $alphaClient)
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.equipments.0.id', $alphaEquipment);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/equipments/' . $alphaEquipment)
            ->assertOk()
            ->assertJsonPath('data.equipment.id', $alphaEquipment)
            ->assertJsonPath('data.equipment.client.id', $alphaClient)
            ->assertJsonPath('data.equipment.orders_count', 1);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orders?equipment_id=' . $alphaEquipment)
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.orders.0.equipamento_id', $alphaEquipment);
    }

    public function test_user_management_supports_create_update_and_active_toggle(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.users@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/users', [
                'nome' => 'TÉCNICO NOVO DE TAL',
                'email' => 'tecnico.novo@example.com',
                'password' => 'Senha@123',
                'perfil' => 'admin',
                'grupo_id' => 2,
                'telefone' => '(11) 98888-7777',
                'ativo' => true,
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.user.nome', 'Técnico Novo de Tal')
            ->assertJsonPath('data.user.email', 'tecnico.novo@example.com')
            ->assertJsonPath('data.user.perfil', 'tecnico')
            ->assertJsonPath('data.user.group.id', 2);

        $createdId = (int) $createResponse->json('data.user.id');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/v1/users/' . $createdId, [
                'password' => 'NovaSenha@123',
                'password_confirmation' => 'SenhaDiferente@123',
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/v1/users/' . $createdId, [
                'nome' => 'TÉCNICO ATUALIZADO DE TAL',
                'grupo_id' => 3,
                'perfil' => 'admin',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.nome', 'Técnico Atualizado de Tal')
            ->assertJsonPath('data.user.perfil', 'atendente')
            ->assertJsonPath('data.user.group.id', 3);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/v1/users/' . $createdId . '/active', [
                'active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.user.ativo', false);

        $this->assertDatabaseHas('usuarios', [
            'id' => $createdId,
            'nome' => 'Técnico Atualizado de Tal',
            'grupo_id' => 3,
            'perfil' => 'atendente',
            'ativo' => 0,
        ]);
    }

    public function test_group_rename_keeps_linked_users_legacy_profile_compatible(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.rename-group@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $linkedUser = $this->createUserRecord([
            'nome' => 'Atendente Grupo',
            'email' => 'atendente.grupo@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/v1/groups/3', [
                'nome' => 'Suporte Técnico',
                'descricao' => 'Equipe de suporte',
            ])
            ->assertOk()
            ->assertJsonPath('data.group.nome', 'Suporte Técnico');

        $this->assertDatabaseHas('usuarios', [
            'id' => $linkedUser->id,
            'grupo_id' => 3,
            'perfil' => 'atendente',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/v1/groups/3', [
                'nome' => 'Administrador Customizado',
                'descricao' => 'Nome customizado sem privilégio legado',
            ])
            ->assertOk()
            ->assertJsonPath('data.group.nome', 'Administrador Customizado');

        $this->assertDatabaseHas('usuarios', [
            'id' => $linkedUser->id,
            'grupo_id' => 3,
            'perfil' => 'atendente',
        ]);
    }

    public function test_group_permissions_update_invalidates_cached_permissions_for_affected_users(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.groups@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $groupUser = $this->createUserRecord([
            'nome' => 'Operador Grupo 3',
            'email' => 'grupo3@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]);

        $userToken = $this->loginAndGetToken($groupUser->email);

        $this->withHeader('Authorization', 'Bearer ' . $userToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.permissions.clientes.0', 'visualizar');

        $cacheKey = 'rbac_user_' . $groupUser->id;
        $cachedBefore = Cache::get($cacheKey);
        $this->assertIsArray($cachedBefore);

        $this->flushHeaders();
        Sanctum::actingAs($admin, ['*']);

        $response = $this->putJson('/api/v1/groups/3/permissions', [
            'permissions' => [
                'os' => ['visualizar'],
                'equipamentos' => ['visualizar'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.group.id', 3);

        $this->assertNull(Cache::get($cacheKey));

        $resolved = app(RbacAuthorizationService::class)->resolveForUser($groupUser->fresh());

        $this->assertSame(['visualizar'], $resolved['permissions']['os']);
        $this->assertSame(['visualizar'], $resolved['permissions']['equipamentos']);
    }

    public function test_groups_catalog_and_permissions_endpoints_expose_management_data(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.catalog@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/groups')
            ->assertOk()
            ->assertJsonPath('data.groups.0.id', 1);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/modules')
            ->assertOk()
            ->assertJsonPath('data.modules.0.slug', 'dashboard');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/permissions')
            ->assertOk()
            ->assertJsonPath('data.permissions.0.slug', 'visualizar');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/groups/3/permissions')
            ->assertOk()
            ->assertJsonPath('data.group.id', 3)
            ->assertJsonPath('data.permissions.clientes.0', 'visualizar');
    }

    public function test_system_groups_reject_update_permission_change_and_delete(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.immutable@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/v1/groups/1', [
                'nome' => 'Administrador Renomeado',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'GROUP_SYSTEM_IMMUTABLE');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/groups/1/permissions', [
                'permissions' => [
                    'clientes' => ['visualizar'],
                ],
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'GROUP_SYSTEM_IMMUTABLE');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/groups/1')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'GROUP_SYSTEM_IMMUTABLE');
    }

    public function test_user_without_management_permissions_receives_403(): void
    {
        $viewer = $this->createUserRecord([
            'nome' => 'Somente leitura',
            'email' => 'viewer@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 4,
        ]);

        $token = $this->loginAndGetToken($viewer->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/users')
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/groups')
            ->assertForbidden();
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-admin',
        ]);

        return (string) $response->json('data.access_token');
    }
}
