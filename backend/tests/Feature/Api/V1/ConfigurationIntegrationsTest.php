<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class ConfigurationIntegrationsTest extends TestCase
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
            'configuracoes' => ['visualizar', 'editar'],
        ]);
    }

    public function test_integrations_payload_can_be_loaded_and_saved(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador Integrações',
            'email' => 'integracoes.admin@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/v1/configuracoes/integracoes');

        $response->assertOk()
            ->assertJsonPath('data.integration.provider_options.direct.0.value', 'api_whats_local')
            ->assertJsonPath('data.integration.summary.provider_label', 'API local');

        $update = $this->putJson('/api/v1/configuracoes/integracoes', [
            'whatsapp_enabled' => true,
            'whatsapp_direct_provider' => 'api_whats_local',
            'whatsapp_bulk_provider' => 'meta_oficial',
            'whatsapp_test_phone' => '(22) 99999-9999',
            'whatsapp_webhook_token' => 'token-webhook-123',
            'whatsapp_local_node_url' => 'http://127.0.0.1:3001',
            'whatsapp_local_node_token' => 'token-local',
            'whatsapp_local_node_origin' => 'http://127.0.0.1:8080',
            'whatsapp_local_node_timeout' => 20,
            'whatsapp_linux_node_url' => 'http://127.0.0.1:3002',
            'whatsapp_linux_node_token' => 'token-linux',
            'whatsapp_linux_node_origin' => 'http://127.0.0.1:8080',
            'whatsapp_linux_node_timeout' => 20,
            'whatsapp_webhook_url' => 'http://127.0.0.1:8001/webhook',
            'whatsapp_webhook_method' => 'POST',
            'whatsapp_webhook_headers' => '{"X-Test":"1"}',
            'whatsapp_webhook_payload' => '{"to":"{{phone}}","message":"{{message}}"}',
            'pagamentos_mercadopago_enabled' => true,
            'pagamentos_mercadopago_access_token' => 'APP_USR-token',
            'pagamentos_mercadopago_public_key' => 'APP_USR-public',
            'pagamentos_asaas_enabled' => true,
            'pagamentos_asaas_base_url' => 'https://api-sandbox.asaas.com/v3',
            'pagamentos_asaas_api_key' => 'asaas-key',
            'pagamentos_asaas_billing_type_default' => 'PIX',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_crypto' => 'tls',
            'smtp_timeout' => 20,
            'smtp_user' => 'user@example.com',
            'smtp_pass' => 'secret',
            'smtp_from_email' => 'orcamentos@example.com',
            'smtp_from_name' => 'Assistência Técnica',
            'portal_google_client_id' => 'client-id.apps.googleusercontent.com',
            'portal_google_client_secret' => 'GOCSPX-secret',
        ]);

        $update->assertOk()
            ->assertJsonPath('data.integration.settings.whatsapp_enabled', '1')
            ->assertJsonPath('data.integration.settings.whatsapp_direct_provider', 'api_whats_local')
            ->assertJsonPath('data.integration.settings.whatsapp_test_phone', '(22) 99999-9999')
            ->assertJsonPath('data.integration.settings.whatsapp_webhook_token', '')
            ->assertJsonPath('data.integration.secret_status.whatsapp_local_node_token.configured', true)
            ->assertJsonPath('data.integration.secret_status.whatsapp_webhook_token.configured', true)
            ->assertJsonPath('data.integration.payments.settings.pagamentos_mercadopago_access_token', '')
            ->assertJsonPath('data.integration.payments.secret_status.pagamentos_mercadopago_access_token.configured', true)
            ->assertJsonPath('data.integration.payments.summary.mercado_pago.ready', true)
            ->assertJsonPath('data.integration.payments.summary.asaas.ready', true)
            ->assertJsonPath('data.integration.email.settings.smtp_host', 'smtp.example.com')
            ->assertJsonPath('data.integration.email.settings.smtp_pass', '')
            ->assertJsonPath('data.integration.email.secret_status.smtp_pass.configured', true)
            ->assertJsonPath('data.integration.email.summary.configured', true)
            ->assertJsonPath('data.integration.google.settings.portal_google_client_id', 'client-id.apps.googleusercontent.com')
            ->assertJsonPath('data.integration.google.settings.portal_google_client_secret', '')
            ->assertJsonPath('data.integration.google.secret_status.portal_google_client_secret.configured', true)
            ->assertJsonPath('data.integration.google.summary.configured', true);

        $update->assertJsonMissingPath('data.integration.gateway.local.token');

        $this->assertDatabaseHas('configuracoes', [
            'chave' => 'whatsapp_enabled',
            'valor' => '1',
        ]);

        $this->assertDatabaseHas('configuracoes', [
            'chave' => 'whatsapp_local_node_url',
            'valor' => 'http://127.0.0.1:3001',
        ]);

        $this->assertDatabaseHas('configuracoes', [
            'chave' => 'whatsapp_webhook_payload',
            'valor' => '{"to":"{{phone}}","message":"{{message}}"}',
        ]);

        $this->assertDatabaseHas('configuracoes', [
            'chave' => 'whatsapp_webhook_token',
            'valor' => 'token-webhook-123',
        ]);

        $this->assertDatabaseHas('configuracoes', [
            'chave' => 'pagamentos_mercadopago_access_token',
            'valor' => 'APP_USR-token',
        ]);

        $this->assertDatabaseHas('configuracoes', [
            'chave' => 'smtp_host',
            'valor' => 'smtp.example.com',
        ]);

        $this->assertDatabaseHas('configuracoes', [
            'chave' => 'portal_google_client_secret',
            'valor' => 'GOCSPX-secret',
        ]);

        $blankSecretUpdate = $this->putJson('/api/v1/configuracoes/integracoes', [
            'whatsapp_direct_provider' => 'api_whats_local',
            'whatsapp_bulk_provider' => 'meta_oficial',
            'whatsapp_local_node_url' => 'http://127.0.0.1:3001',
            'whatsapp_local_node_token' => '',
            'pagamentos_mercadopago_access_token' => '',
            'smtp_pass' => '',
            'portal_google_client_secret' => '',
        ]);

        $blankSecretUpdate->assertOk()
            ->assertJsonPath('data.integration.secret_status.whatsapp_local_node_token.configured', true)
            ->assertJsonPath('data.integration.payments.secret_status.pagamentos_mercadopago_access_token.configured', true)
            ->assertJsonPath('data.integration.email.secret_status.smtp_pass.configured', true)
            ->assertJsonPath('data.integration.google.secret_status.portal_google_client_secret.configured', true);

        $this->assertDatabaseHas('configuracoes', [
            'chave' => 'whatsapp_local_node_token',
            'valor' => 'token-local',
        ]);

        $this->assertDatabaseHas('configuracoes', [
            'chave' => 'pagamentos_mercadopago_access_token',
            'valor' => 'APP_USR-token',
        ]);

        $this->assertDatabaseHas('configuracoes', [
            'chave' => 'smtp_pass',
            'valor' => 'secret',
        ]);

        $this->assertDatabaseHas('configuracoes', [
            'chave' => 'portal_google_client_secret',
            'valor' => 'GOCSPX-secret',
        ]);
    }

    public function test_payment_test_connection_endpoint_validates_mercado_pago_and_asaas(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador Pagamentos',
            'email' => 'pagamentos.admin@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        Sanctum::actingAs($admin, ['*']);

        Http::fake([
            'api.mercadopago.com/users/me' => Http::response([
                'id' => 123,
                'nickname' => 'JOVEMTECH',
                'email' => 'financeiro@example.com',
            ], 200),
            'api-sandbox.asaas.com/v3/myAccount' => Http::response([
                'name' => 'Jovem Tech',
                'email' => 'financeiro@example.com',
                'walletId' => 'wallet-123',
            ], 200),
        ]);

        $mercadoPago = $this->postJson('/api/v1/configuracoes/integracoes/pagamentos/testar-conexao', [
            'provider' => 'mercado_pago',
            'pagamentos_mercadopago_access_token' => 'APP_USR-token',
            'pagamentos_mercadopago_public_key' => 'APP_USR-public',
        ]);

        $mercadoPago->assertOk()
            ->assertJsonPath('data.result.ok', true)
            ->assertJsonPath('data.result.details.nickname', 'JOVEMTECH');

        $asaas = $this->postJson('/api/v1/configuracoes/integracoes/pagamentos/testar-conexao', [
            'provider' => 'asaas',
            'pagamentos_asaas_base_url' => 'https://api-sandbox.asaas.com/v3',
            'pagamentos_asaas_api_key' => 'asaas-key',
        ]);

        $asaas->assertOk()
            ->assertJsonPath('data.result.ok', true)
            ->assertJsonPath('data.result.details.walletId', 'wallet-123');
    }

    public function test_email_send_test_endpoint_sends_via_dynamic_smtp(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador E-mail',
            'email' => 'email.admin@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        Sanctum::actingAs($admin, ['*']);

        Mail::fake();

        $response = $this->postJson('/api/v1/configuracoes/integracoes/email/enviar-teste', [
            'email' => 'destino@example.com',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_crypto' => 'tls',
            'smtp_user' => 'user@example.com',
            'smtp_pass' => 'secret',
            'smtp_from_email' => 'orcamentos@example.com',
            'smtp_from_name' => 'Assistência Técnica',
        ]);

        $response->assertOk()->assertJsonPath('data.result.ok', true);

        Mail::assertSent(\App\Mail\IntegrationTestMail::class, function ($mail) {
            return $mail->hasTo('destino@example.com');
        });
    }

    public function test_test_connection_endpoint_uses_local_gateway_settings(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador Gateway',
            'email' => 'gateway.admin@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $this->seedGatewaySettings();

        Sanctum::actingAs($admin, ['*']);

        Http::fake([
            'http://127.0.0.1:3001/create-message' => Http::response([
                'success' => true,
                'message' => 'Mensagem enviada com sucesso.',
                'data' => [
                    'message_id' => 'msg-123',
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/configuracoes/integracoes/testar-conexao', [
            'whatsapp_direct_provider' => 'api_whats_local',
            'whatsapp_test_phone' => '(22) 99999-9999',
            'whatsapp_local_node_url' => 'http://127.0.0.1:3001',
            'whatsapp_local_node_token' => 'token-local',
            'whatsapp_local_node_origin' => 'http://127.0.0.1:8080',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.result.ok', true)
            ->assertJsonPath('data.result.provider', 'api_whats_local')
            ->assertJsonPath('data.result.response.data.message_id', 'msg-123');
    }

    public function test_self_check_inbound_and_webhook_route_are_wired(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador Webhook',
            'email' => 'webhook.admin@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $this->seedGatewaySettings();
        DB::table('configuracoes')->upsert([
            [
                'chave' => 'whatsapp_webhook_token',
                'valor' => 'token-webhook-123',
                'tipo' => 'texto',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        ], ['chave'], ['valor', 'tipo', 'updated_at']);

        Sanctum::actingAs($admin, ['*']);

        Http::fake([
            'http://127.0.0.1:3001/status' => Http::response([
                'success' => true,
                'message' => 'Gateway ativo.',
                'data' => [
                    'account' => [
                        'pushname' => 'Central do ERP',
                        'number' => '5522999999999',
                        'platform' => 'whatsapp',
                    ],
                ],
            ], 200),
            'http://127.0.0.1:3001/self-check-inbound' => Http::response([
                'success' => true,
                'message' => 'Self-check inbound validado com sucesso.',
                'data' => [
                    'received' => true,
                ],
            ], 200),
            'http://127.0.0.1:8000/webhooks/whatsapp' => Http::response([
                'status' => 'success',
                'data' => [
                    'received' => true,
                    'self_check' => true,
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $selfCheck = $this->postJson('/api/v1/configuracoes/integracoes/self-check-inbound', [
            'whatsapp_direct_provider' => 'api_whats_local',
            'whatsapp_local_node_url' => 'http://127.0.0.1:3001',
            'whatsapp_local_node_token' => 'token-local',
            'whatsapp_local_node_origin' => 'http://127.0.0.1:8000',
            'whatsapp_webhook_token' => 'token-webhook-123',
        ]);

        $selfCheck->assertOk()
            ->assertJsonPath('data.result.ok', true)
            ->assertJsonPath('data.result.checks.gateway_status.ok', true)
            ->assertJsonPath('data.result.checks.gateway_forward.ok', true)
            ->assertJsonPath('data.result.checks.webhook_direct.ok', true)
            ->assertJsonPath('data.result.checks.origin_alignment.ok', true);

        $webhook = $this->postJson('/webhooks/whatsapp', [
            'source' => 'teste',
            'message' => 'ping',
        ], [
            'X-Webhook-Token' => 'token-webhook-123',
        ]);

        $webhook->assertOk()
            ->assertJsonPath('data.received', true)
            ->assertJsonPath('data.payload.source', 'teste');
    }

    private function seedGatewaySettings(): void
    {
        DB::table('configuracoes')->upsert([
            [
                'chave' => 'whatsapp_enabled',
                'valor' => '1',
                'tipo' => 'booleano',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'chave' => 'whatsapp_direct_provider',
                'valor' => 'api_whats_local',
                'tipo' => 'texto',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'chave' => 'whatsapp_bulk_provider',
                'valor' => 'meta_oficial',
                'tipo' => 'texto',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'chave' => 'whatsapp_test_phone',
                'valor' => '(22) 99999-9999',
                'tipo' => 'texto',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'chave' => 'whatsapp_local_node_url',
                'valor' => 'http://127.0.0.1:3001',
                'tipo' => 'texto',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'chave' => 'whatsapp_local_node_token',
                'valor' => 'token-local',
                'tipo' => 'texto',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'chave' => 'whatsapp_local_node_origin',
                'valor' => 'http://127.0.0.1:8080',
                'tipo' => 'texto',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['chave'], ['valor', 'tipo', 'updated_at']);
    }
}
