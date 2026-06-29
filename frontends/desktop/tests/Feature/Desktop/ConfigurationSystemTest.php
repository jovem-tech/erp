<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConfigurationSystemTest extends TestCase
{
    public function test_system_settings_page_renders_separated_sections(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'configuracoes' => ['visualizar'],
            ]))
            ->get('/configuracoes/sistema');

        $response
            ->assertOk()
            ->assertSee('Configurações do Sistema')
            ->assertSee('Aparência')
            ->assertSee('Dados da Empresa')
            ->assertSee('Sessão e Segurança')
            ->assertDontSee('Configurações WhatsApp')
            ->assertDontSee('Salvar integrações');
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
                'user' => $this->fakeUser([
                    'permissions' => $permissions,
                    'modules' => array_keys($permissions),
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationsPayload(): array
    {
        return [
            'status' => 'success',
            'data' => [
                'items' => [],
                'unread_count' => 0,
            ],
            'error' => null,
            'meta' => [
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => 6,
                    'total' => 0,
                    'last_page' => 1,
                    'from' => 0,
                    'to' => 0,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function fakeUser(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 99,
            'nome' => 'Usuário de Teste',
            'email' => 'usuario@teste.local',
            'perfil' => 'admin',
            'group' => [
                'id' => 1,
                'nome' => 'Administrador',
                'descricao' => 'Grupo completo',
                'sistema' => true,
            ],
            'modules' => [],
            'permissions' => [],
            'foto' => '',
            'ativo' => true,
        ], $overrides);
    }
}
