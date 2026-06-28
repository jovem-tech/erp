<?php

namespace Tests\Feature\Chat;

use App\Models\Chat\Account;
use App\Models\Chat\Channel\Whatsapp;
use App\Models\Chat\Conversation;
use App\Models\Chat\Inbox;
use App\Models\Chat\Message;
use App\Models\Chat\MessageAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class WhatsappWebhookTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

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
    }

    public function test_self_check_nao_cria_conversa(): void
    {
        $response = $this->postJson('/webhooks/whatsapp', [
            'self_check' => true,
            'source' => 'erp_direct_self_check',
        ]);

        $response->assertOk()->assertJsonPath('data.self_check', true);
        $this->assertSame(0, Message::query()->count());
    }

    public function test_webhook_accepta_formatos_comuns_de_token(): void
    {
        DB::table('configuracoes')->upsert([
            [
                'chave' => 'whatsapp_webhook_token',
                'valor' => 'token-webhook-123',
                'tipo' => 'texto',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        ], ['chave'], ['valor', 'tipo', 'updated_at']);

        $headersList = [
            ['Authorization' => 'Bearer token-webhook-123'],
            ['X-Api-Token' => 'token-webhook-123'],
            ['X-Api-Key' => 'token-webhook-123'],
            ['apikey' => 'token-webhook-123'],
        ];

        foreach ($headersList as $index => $headers) {
            $response = $this->postJson('/webhooks/whatsapp', [
                'data' => [
                    'key' => ['remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => 'false', 'id' => 'MSG-BEARER-' . $index],
                    'pushName' => 'Cliente Teste',
                    'message' => ['conversation' => 'Mensagem inbound via token comum'],
                ],
            ], $headers);

            $response->assertOk();

            $this->assertDatabaseHas('mensagens', [
                'source_id' => 'MSG-BEARER-' . $index,
                'conteudo' => 'Mensagem inbound via token comum',
            ], 'chat');
        }
    }

    public function test_payload_real_cria_contato_conversa_e_mensagem(): void
    {
        $response = $this->postJson('/webhooks/whatsapp', [
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
        $response = $this->postJson('/webhooks/whatsapp', [
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
        Http::fake([
            'https://provider.test/*' => Http::response('image-binary', 200, ['Content-Type' => 'image/png']),
        ]);

        $response = $this->postJson('/webhooks/whatsapp', [
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-IMG'],
                'pushName' => 'Cliente Teste',
                'message' => [
                    'imageMessage' => [
                        'mimetype' => 'image/png',
                        'fileName' => 'foto.png',
                        'mediaUrl' => 'https://provider.test/foto.png',
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
    }

    public function test_falha_no_download_da_midia_registra_placeholder_para_auditoria(): void
    {
        Http::fake([
            'https://provider.test/*' => Http::response('', 500),
        ]);

        $this->postJson('/webhooks/whatsapp', [
            'data' => [
                'key' => ['remoteJid' => '5511997776666@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-DOC'],
                'message' => [
                    'documentMessage' => [
                        'mimetype' => 'application/pdf',
                        'fileName' => 'laudo.pdf',
                        'mediaUrl' => 'https://provider.test/laudo.pdf',
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

        $this->postJson('/webhooks/whatsapp', $payload)->assertOk();
        $this->postJson('/webhooks/whatsapp', $payload)->assertOk();

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

        $this->postJson('/webhooks/whatsapp', $primeira)->assertOk();
        $this->postJson('/webhooks/whatsapp', $segunda)->assertOk();

        $this->assertSame(1, Conversation::query()->count());
        $this->assertSame(2, Message::query()->count());
    }

    public function test_mensagem_de_grupo_e_ignorada(): void
    {
        $this->postJson('/webhooks/whatsapp', [
            'data' => [
                'key' => ['remoteJid' => '123456789-987654321@g.us', 'fromMe' => false, 'id' => 'MSG-GROUP'],
                'message' => ['conversation' => 'Mensagem de grupo'],
            ],
        ])->assertOk();

        $this->assertSame(0, Message::query()->count());
    }

    public function test_mensagem_enviada_pelo_proprio_numero_e_ignorada(): void
    {
        $this->postJson('/webhooks/whatsapp', [
            'data' => [
                'key' => ['remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => true, 'id' => 'MSG-FROM-ME'],
                'message' => ['conversation' => 'Mensagem enviada do proprio telefone'],
            ],
        ])->assertOk();

        $this->assertSame(0, Message::query()->count());
    }
}
