<?php

namespace Tests\Feature\Chat;

use App\Enums\Files\FileCategory;
use App\Enums\Files\FileOrigin;
use App\Models\Chat\Account;
use App\Models\Chat\Channel\Whatsapp;
use App\Models\Chat\Conversation;
use App\Models\Chat\Inbox;
use App\Models\Chat\Message;
use App\Models\Chat\MessageAttachment;
use App\Models\Files\ManagedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class WhatsappWebhookTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private const WEBHOOK_TOKEN = 'token-webhook-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();

        $account = Account::create(['nome' => 'Conta Teste', 'proximo_display_id' => 1]);
        $channel = Whatsapp::create(['conta_id' => $account->id, 'nome' => 'WhatsApp', 'provider' => 'evolution']);
        Inbox::create([
            'conta_id' => $account->id,
            'nome' => 'WhatsApp',
            'channel_type' => 'whatsapp',
            'channel_id' => $channel->id,
        ]);

        $this->seedWebhookSecuritySettings();
    }

    public function test_self_check_nao_cria_conversa(): void
    {
        $response = $this->postWebhook([
            'self_check' => true,
            'source' => 'erp_direct_self_check',
        ]);

        $response->assertOk()->assertJsonPath('data.self_check', true);
        $this->assertSame(0, Message::query()->count());
    }

    public function test_webhook_rejeita_requisicoes_quando_o_token_nao_esta_configurado(): void
    {
        DB::table('configuracoes')
            ->where('chave', 'whatsapp_webhook_token')
            ->update(['valor' => '']);

        $this->postJson('/webhooks/whatsapp', [
            'data' => [
                'key' => ['remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-WITHOUT-CONFIG'],
                'message' => ['conversation' => 'Mensagem sem token configurado'],
            ],
        ], [
            'X-Webhook-Token' => self::WEBHOOK_TOKEN,
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'WHATSAPP_WEBHOOK_FORBIDDEN');
    }

    public function test_webhook_accepta_formatos_comuns_de_token(): void
    {
        $headersList = [
            ['Authorization' => 'Bearer '.self::WEBHOOK_TOKEN],
            ['X-Api-Token' => self::WEBHOOK_TOKEN],
            ['X-Api-Key' => self::WEBHOOK_TOKEN],
            ['apikey' => self::WEBHOOK_TOKEN],
        ];

        foreach ($headersList as $index => $headers) {
            $response = $this->postJson('/webhooks/whatsapp', [
                'data' => [
                    'key' => ['remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => 'false', 'id' => 'MSG-BEARER-'.$index],
                    'pushName' => 'Cliente Teste',
                    'message' => ['conversation' => 'Mensagem inbound via token comum'],
                ],
            ], $headers);

            $response->assertOk();

            $this->assertDatabaseHas('mensagens', [
                'source_id' => 'MSG-BEARER-'.$index,
                'conteudo' => 'Mensagem inbound via token comum',
            ], 'chat');
        }
    }

    public function test_payload_real_cria_contato_conversa_e_mensagem(): void
    {
        $response = $this->postWebhook([
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-1'],
                'pushName' => 'Cliente Teste',
                'message' => ['conversation' => 'Olá, preciso de ajuda'],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('contatos', ['telefone' => '+5511988887777', 'nome' => 'Cliente Teste'], 'chat');
        $this->assertDatabaseHas('conversas', ['display_id' => 1, 'status' => 'open'], 'chat');
        $this->assertDatabaseHas('mensagens', ['source_id' => 'MSG-1', 'conteudo' => 'Olá, preciso de ajuda'], 'chat');
    }

    public function test_payload_real_com_fromme_textual_false_nao_e_ignorado(): void
    {
        $response = $this->postWebhook([
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => '5511991112222@s.whatsapp.net', 'fromMe' => 'false', 'id' => 'MSG-TEXT-FALSE'],
                'pushName' => 'Cliente Texto',
                'message' => ['conversation' => 'Mensagem recebida com fromMe textual'],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('mensagens', [
            'source_id' => 'MSG-TEXT-FALSE',
            'conteudo' => 'Mensagem recebida com fromMe textual',
        ], 'chat');
    }

    public function test_payload_real_com_midia_cria_anexo_mesmo_sem_texto(): void
    {
        Storage::fake('local');
        config()->set('file-manager.mode', 'shadow');
        config()->set('file-manager.enabled_categories', ['chat_attachment']);
        $pngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL5WQAAAABJRU5ErkJggg==',
            true
        );
        $this->assertIsString($pngBytes);

        Http::fake([
            'https://example.com/*' => Http::response($pngBytes, 200, ['Content-Type' => 'image/png']),
        ]);

        $response = $this->postWebhook([
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-IMG'],
                'pushName' => 'Cliente Teste',
                'message' => [
                    'imageMessage' => [
                        'mimetype' => 'image/png',
                        'fileName' => 'foto.png',
                        'mediaUrl' => 'https://example.com/foto.png',
                    ],
                ],
            ],
        ]);

        $response->assertOk();

        $message = Message::query()->where('source_id', 'MSG-IMG')->firstOrFail();
        $attachment = MessageAttachment::query()->where('mensagem_id', $message->id)->firstOrFail();

        $this->assertSame('image', $message->content_type);
        $this->assertSame('available', $attachment->transfer_status);
        Storage::disk('local')->assertExists((string) $attachment->storage_path);

        $managed = ManagedFile::query()->where('category', FileCategory::ChatAttachment->value)->firstOrFail();
        $this->assertSame(FileOrigin::Integration, $managed->origin);
        $this->assertSame($managed->uuid, $attachment->fresh()->managed_file_uuid);
        $this->assertSame('linked', $attachment->fresh()->metadata['file_manager_state'] ?? null);
    }

    public function test_midia_inbound_com_conteudo_html_disfarcado_de_png_e_bloqueada(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.com/*' => Http::response(
                '<html><script>alert(1)</script></html>',
                200,
                ['Content-Type' => 'image/png']
            ),
        ]);

        $this->postWebhook([
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-HTML-AS-PNG'],
                'message' => [
                    'imageMessage' => [
                        'mimetype' => 'image/png',
                        'fileName' => 'foto.png',
                        'mediaUrl' => 'https://example.com/foto.png',
                    ],
                ],
            ],
        ])->assertOk();

        $message = Message::query()->where('source_id', 'MSG-HTML-AS-PNG')->firstOrFail();
        $attachment = MessageAttachment::query()->where('mensagem_id', $message->id)->firstOrFail();

        $this->assertSame('failed', $attachment->transfer_status);
        $this->assertNull($attachment->storage_path);
        $this->assertTrue((bool) data_get($attachment->metadata, 'rejected_by_policy'));
    }

    public function test_midia_inbound_recusa_redirect_mesmo_em_origem_confiavel(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/segredo']),
        ]);

        $this->postWebhook([
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-REDIRECT'],
                'message' => [
                    'imageMessage' => [
                        'mimetype' => 'image/png',
                        'fileName' => 'foto.png',
                        'mediaUrl' => 'https://example.com/redirect.png',
                    ],
                ],
            ],
        ])->assertOk();

        $attachment = MessageAttachment::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $attachment->transfer_status);
        $this->assertNull($attachment->storage_path);
    }

    public function test_midia_inbound_recusa_ip_privado_sem_allowlist_explicita(): void
    {
        Storage::fake('local');
        DB::table('configuracoes')->where('chave', 'whatsapp_evolution_url')->update([
            'valor' => 'http://127.0.0.1:8080',
            'updated_at' => now(),
        ]);
        Http::fake();

        $this->postWebhook([
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-PRIVATE-IP'],
                'message' => [
                    'imageMessage' => [
                        'mimetype' => 'image/png',
                        'fileName' => 'foto.png',
                        'mediaUrl' => 'http://127.0.0.1:8080/foto.png',
                    ],
                ],
            ],
        ])->assertOk();

        Http::assertNothingSent();
        $attachment = MessageAttachment::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $attachment->transfer_status);
        $this->assertNull($attachment->storage_path);
    }

    public function test_url_de_midia_nao_confiavel_gera_placeholder_sem_download(): void
    {
        Http::fake([
            'https://nao-confiavel.example.net/*' => Http::response('blocked', 200),
        ]);

        $this->postWebhook([
            'data' => [
                'key' => ['remoteJid' => '5511997776666@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-BLOCKED'],
                'message' => [
                    'documentMessage' => [
                        'mimetype' => 'application/pdf',
                        'fileName' => 'laudo.pdf',
                        'mediaUrl' => 'https://nao-confiavel.example.net/laudo.pdf',
                    ],
                ],
            ],
        ])->assertOk();

        $message = Message::query()->where('source_id', 'MSG-BLOCKED')->firstOrFail();
        $attachment = MessageAttachment::query()->where('mensagem_id', $message->id)->firstOrFail();

        $this->assertSame('document', $message->content_type);
        $this->assertSame('failed', $attachment->transfer_status);
        $this->assertNull($attachment->storage_path);
    }

    public function test_falha_no_download_da_midia_registra_placeholder_para_auditoria(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response('', 500),
        ]);

        $this->postWebhook([
            'data' => [
                'key' => ['remoteJid' => '5511997776666@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-DOC'],
                'message' => [
                    'documentMessage' => [
                        'mimetype' => 'application/pdf',
                        'fileName' => 'laudo.pdf',
                        'mediaUrl' => 'https://example.com/laudo.pdf',
                    ],
                ],
            ],
        ])->assertOk();

        $message = Message::query()->where('source_id', 'MSG-DOC')->firstOrFail();
        $attachment = MessageAttachment::query()->where('mensagem_id', $message->id)->firstOrFail();

        $this->assertSame('document', $message->content_type);
        $this->assertSame('failed', $attachment->transfer_status);
        $this->assertNull($attachment->storage_path);
    }

    public function test_payload_duplicado_nao_duplica_mensagem(): void
    {
        $payload = [
            'data' => [
                'key' => ['remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-DUP'],
                'pushName' => 'Cliente Teste',
                'message' => ['conversation' => 'Mensagem repetida'],
            ],
        ];

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertSame(1, Message::query()->where('source_id', 'MSG-DUP')->count());
    }

    public function test_segunda_mensagem_do_mesmo_contato_entra_na_mesma_conversa(): void
    {
        $primeira = [
            'data' => [
                'key' => ['remoteJid' => '5511977776666@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-A'],
                'message' => ['conversation' => 'Primeira mensagem'],
            ],
        ];
        $segunda = [
            'data' => [
                'key' => ['remoteJid' => '5511977776666@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-B'],
                'message' => ['conversation' => 'Segunda mensagem'],
            ],
        ];

        $this->postWebhook($primeira)->assertOk();
        $this->postWebhook($segunda)->assertOk();

        $this->assertSame(1, Conversation::query()->count());
        $this->assertSame(2, Message::query()->count());
    }

    public function test_mensagem_de_grupo_e_ignorada(): void
    {
        $this->postWebhook([
            'data' => [
                'key' => ['remoteJid' => '123456789-987654321@g.us', 'fromMe' => false, 'id' => 'MSG-GROUP'],
                'message' => ['conversation' => 'Mensagem de grupo'],
            ],
        ])->assertOk();

        $this->assertSame(0, Message::query()->count());
    }

    public function test_mensagem_enviada_pelo_proprio_numero_e_ignorada(): void
    {
        $this->postWebhook([
            'data' => [
                'key' => ['remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => true, 'id' => 'MSG-FROM-ME'],
                'message' => ['conversation' => 'Mensagem enviada do proprio telefone'],
            ],
        ])->assertOk();

        $this->assertSame(0, Message::query()->count());
    }

    private function postWebhook(array $payload, array $headers = []): TestResponse
    {
        return $this->postJson('/webhooks/whatsapp', $payload, array_merge([
            'X-Webhook-Token' => self::WEBHOOK_TOKEN,
        ], $headers));
    }

    private function seedWebhookSecuritySettings(): void
    {
        DB::table('configuracoes')->upsert([
            [
                'chave' => 'whatsapp_webhook_token',
                'valor' => self::WEBHOOK_TOKEN,
                'tipo' => 'texto',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'chave' => 'whatsapp_evolution_url',
                'valor' => 'https://example.com',
                'tipo' => 'texto',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'chave' => 'whatsapp_evolution_apikey',
                'valor' => 'api-key-example',
                'tipo' => 'texto',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'chave' => 'whatsapp_evolution_instance',
                'valor' => 'central-erp',
                'tipo' => 'texto',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        ], ['chave'], ['valor', 'tipo', 'updated_at']);
    }
}
