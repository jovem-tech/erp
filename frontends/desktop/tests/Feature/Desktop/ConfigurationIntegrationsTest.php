<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConfigurationIntegrationsTest extends TestCase
{
    public function test_integrations_page_renders_operational_shell_and_current_state(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ], 200),
            'http://127.0.0.1:8000/api/v1/configuracoes/integracoes' => Http::response([
                'status' => 'success',
                'data' => [
                    'integration' => $this->fakeIntegrationPayload(),
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'configuracoes' => ['visualizar', 'editar'],
            ]))
            ->get('/configuracoes/integracoes');

        $response
            ->assertOk()
            ->assertSee('Configurações WhatsApp')
            ->assertSee('Conectado')
            ->assertSee(route('configurations.integrations.help'), false)
            ->assertSee('Salvar integrações')
            ->assertSee('Mercado Pago')
            ->assertSee('Asaas')
            ->assertSee('Host SMTP')
            ->assertSee('Google Client ID');

        Http::assertSentCount(2);
    }

    public function test_payment_test_connection_route_returns_json_result(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [], 'unread_count' => 0],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 6, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0]],
            ], 200),
            'http://127.0.0.1:8000/api/v1/configuracoes/integracoes/pagamentos/testar-conexao' => Http::response([
                'status' => 'success',
                'data' => [
                    'result' => [
                        'ok' => true,
                        'provider' => 'mercado_pago',
                        'message' => 'Conexão com o Mercado Pago validada com sucesso.',
                        'details' => ['nickname' => 'JOVEMTECH'],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'configuracoes' => ['visualizar', 'editar'],
            ]))
            ->post('/configuracoes/integracoes/pagamentos/testar-conexao', [
                'provider' => 'mercado_pago',
                'pagamentos_mercadopago_access_token' => 'APP_USR-token',
                'pagamentos_mercadopago_public_key' => 'APP_USR-public',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('result.ok', true)
            ->assertJsonPath('result.details.nickname', 'JOVEMTECH');
    }

    public function test_email_send_test_route_returns_json_result(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [], 'unread_count' => 0],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 6, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0]],
            ], 200),
            'http://127.0.0.1:8000/api/v1/configuracoes/integracoes/email/enviar-teste' => Http::response([
                'status' => 'success',
                'data' => [
                    'result' => [
                        'ok' => true,
                        'provider' => 'smtp',
                        'message' => 'E-mail de teste enviado com sucesso.',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'configuracoes' => ['visualizar', 'editar'],
            ]))
            ->post('/configuracoes/integracoes/email/enviar-teste', [
                'email' => 'destino@example.com',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('result.ok', true)
            ->assertJsonPath('result.message', 'E-mail de teste enviado com sucesso.');
    }

    public function test_test_connection_route_returns_json_result(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ], 200),
            'http://127.0.0.1:8000/api/v1/configuracoes/integracoes/testar-conexao' => Http::response([
                'status' => 'success',
                'data' => [
                    'result' => [
                        'ok' => true,
                        'provider' => 'api_whats_local',
                        'message' => 'Conexão validada com sucesso.',
                        'response' => [
                            'data' => [
                                'message_id' => 'msg-789',
                            ],
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'configuracoes' => ['visualizar', 'editar'],
            ]))
            ->post('/configuracoes/integracoes/testar-conexao', [
                'whatsapp_direct_provider' => 'api_whats_local',
                'whatsapp_test_phone' => '(22) 99999-9999',
                'whatsapp_local_node_url' => 'http://127.0.0.1:3001',
                'whatsapp_local_node_token' => 'token-local',
                'whatsapp_local_node_origin' => 'http://127.0.0.1:8000',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('result.ok', true)
            ->assertJsonPath('result.provider', 'api_whats_local')
            ->assertJsonPath('result.response.data.message_id', 'msg-789');

        Http::assertSentCount(1);
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

    /**
     * @return array<string, mixed>
     */
    private function fakeIntegrationPayload(): array
    {
        return [
            'settings' => [
                'whatsapp_enabled' => '1',
                'whatsapp_direct_provider' => 'api_whats_local',
                'whatsapp_bulk_provider' => 'meta_oficial',
                'whatsapp_test_phone' => '(22) 99999-9999',
                'whatsapp_menuia_url' => 'https://chatbot.menuia.com/api',
                'whatsapp_menuia_appkey' => '',
                'whatsapp_menuia_authkey' => '',
                'whatsapp_webhook_token' => 'token-webhook-123',
                'whatsapp_evolution_url' => 'http://127.0.0.1:8080',
                'whatsapp_evolution_apikey' => 'api-key',
                'whatsapp_evolution_instance' => 'chatwot',
                'whatsapp_evolution_timeout' => '20',
                'whatsapp_evolution_sync_avatar' => '1',
                'whatsapp_local_node_url' => 'http://127.0.0.1:3001',
                'whatsapp_local_node_token' => 'token-local',
                'whatsapp_local_node_origin' => 'http://127.0.0.1:8000',
                'whatsapp_local_node_timeout' => '20',
                'whatsapp_linux_node_url' => 'http://127.0.0.1:3001',
                'whatsapp_linux_node_token' => 'token-linux',
                'whatsapp_linux_node_origin' => 'http://127.0.0.1:8000',
                'whatsapp_linux_node_timeout' => '20',
                'whatsapp_webhook_url' => 'http://127.0.0.1:8000/webhooks/whatsapp',
                'whatsapp_webhook_method' => 'POST',
                'whatsapp_webhook_headers' => '{"X-Test":"1"}',
                'whatsapp_webhook_payload' => '{"to":"{{phone}}","message":"{{message}}"}',
                'whatsapp_last_check_provider' => 'api_whats_local',
                'whatsapp_last_check_status' => 'success',
                'whatsapp_last_check_message' => 'Conexão validada com sucesso.',
                'whatsapp_last_check_at' => '26/06/2026 20:42',
                'whatsapp_last_check_signature' => 'signature-123',
            ],
            'summary' => [
                'enabled' => true,
                'provider' => 'api_whats_local',
                'provider_label' => 'API local',
                'bulk_provider' => 'meta_oficial',
                'bulk_provider_label' => 'Meta Oficial (futuro)',
                'ready' => true,
                'status' => 'success',
                'status_label' => 'Conectado',
                'status_message' => 'Gateway local pronto para uso.',
                'last_check_provider' => 'api_whats_local',
                'last_check_status' => 'success',
                'last_check_message' => 'Conexão validada com sucesso.',
                'last_check_at' => '26/06/2026 20:42',
                'last_check_matches_current_credentials' => true,
                'signature' => 'signature-123',
            ],
            'provider_options' => [
                'direct' => [
                    ['value' => 'api_whats_local', 'label' => 'API local'],
                    ['value' => 'api_whats_linux', 'label' => 'API Linux'],
                    ['value' => 'menuia', 'label' => 'Menuia'],
                    ['value' => 'evolution', 'label' => 'Evolution API'],
                    ['value' => 'webhook', 'label' => 'Webhook'],
                ],
                'bulk' => [
                    ['value' => 'meta_oficial', 'label' => 'Meta Oficial (futuro)'],
                    ['value' => 'menuia', 'label' => 'Menuia'],
                    ['value' => 'evolution', 'label' => 'Evolution API'],
                    ['value' => 'api_whats_local', 'label' => 'API local'],
                    ['value' => 'api_whats_linux', 'label' => 'API Linux'],
                    ['value' => 'webhook', 'label' => 'Webhook'],
                ],
                'webhook_method' => [
                    ['value' => 'GET', 'label' => 'GET'],
                    ['value' => 'POST', 'label' => 'POST'],
                ],
            ],
            'gateway' => [
                'local' => [
                    'provider' => 'api_whats_local',
                    'url' => 'http://127.0.0.1:3001',
                    'token' => 'token-local',
                    'origin' => 'http://127.0.0.1:8000',
                    'timeout' => 20,
                    'status' => 'connected',
                    'message' => 'Gateway local pronto.',
                    'response' => [
                        'status' => 'connected',
                        'data' => [
                            'account' => [
                                'pushname' => 'Central do ERP',
                                'number' => '5522999999999',
                                'platform' => 'whatsapp',
                            ],
                        ],
                    ],
                ],
                'linux' => [
                    'provider' => 'api_whats_linux',
                    'url' => 'http://127.0.0.1:3001',
                    'token' => 'token-linux',
                    'origin' => 'http://127.0.0.1:8000',
                    'timeout' => 20,
                    'status' => 'disconnected',
                    'message' => 'Gateway Linux indisponível.',
                    'response' => null,
                ],
            ],
            'payments' => [
                'settings' => [
                    'pagamentos_mercadopago_enabled' => '0',
                    'pagamentos_mercadopago_access_token' => '',
                    'pagamentos_mercadopago_public_key' => '',
                    'pagamentos_asaas_enabled' => '0',
                    'pagamentos_asaas_base_url' => 'https://api-sandbox.asaas.com/v3',
                    'pagamentos_asaas_api_key' => '',
                    'pagamentos_asaas_billing_type_default' => 'PIX',
                ],
                'summary' => [
                    'mercado_pago' => ['enabled' => false, 'ready' => false, 'status' => 'secondary', 'status_label' => 'Aguardando configuração'],
                    'asaas' => ['enabled' => false, 'ready' => false, 'status' => 'secondary', 'status_label' => 'Aguardando configuração'],
                ],
            ],
            'email' => [
                'settings' => [
                    'smtp_host' => '',
                    'smtp_port' => '587',
                    'smtp_crypto' => 'auto',
                    'smtp_timeout' => '20',
                    'smtp_user' => '',
                    'smtp_pass' => '',
                    'smtp_from_email' => '',
                    'smtp_from_name' => '',
                ],
                'summary' => ['configured' => false, 'status' => 'secondary', 'status_label' => 'Aguardando configuração'],
            ],
            'google' => [
                'settings' => [
                    'portal_google_client_id' => '',
                    'portal_google_client_secret' => '',
                ],
                'summary' => ['configured' => false, 'status' => 'secondary', 'status_label' => 'Aguardando configuração'],
            ],
        ];
    }
}
