<?php

namespace Tests\Feature\Chat;

use App\Models\Chat\Account;
use App\Models\Chat\Channel\Whatsapp;
use App\Models\Chat\Contact;
use App\Models\Chat\ContactInbox;
use App\Models\Chat\Conversation;
use App\Models\Chat\Inbox;
use App\Models\Chat\Message;
use App\Models\Chat\MessageAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class ConversationFlowTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private Account $account;

    private Inbox $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(3, [
            'atendimento_whatsapp' => ['visualizar', 'criar'],
        ]);
        $this->grantGroupPermissions(4, [
            'atendimento_whatsapp' => ['visualizar'],
        ]);

        $this->account = Account::create(['nome' => 'Conta Teste', 'proximo_display_id' => 1]);
        $channel = Whatsapp::create(['conta_id' => $this->account->id, 'nome' => 'WhatsApp', 'provider' => 'evolution']);
        $this->inbox = Inbox::create([
            'conta_id' => $this->account->id,
            'nome' => 'WhatsApp',
            'channel_type' => 'whatsapp',
            'channel_id' => $channel->id,
        ]);
    }

    private function createConversationWithMessage(?int $clienteId = null): Conversation
    {
        $contact = Contact::create([
            'conta_id' => $this->account->id,
            'nome' => 'Cliente Teste',
            'telefone' => '+5511999998888',
            'cliente_id' => $clienteId,
        ]);

        $contactInbox = ContactInbox::create([
            'conta_id' => $this->account->id,
            'contato_id' => $contact->id,
            'caixa_entrada_id' => $this->inbox->id,
            'source_id' => '+5511999998888',
        ]);

        $conversation = Conversation::create([
            'conta_id' => $this->account->id,
            'caixa_entrada_id' => $this->inbox->id,
            'contato_id' => $contact->id,
            'contato_caixa_entrada_id' => $contactInbox->id,
            'display_id' => $this->account->reserveNextDisplayId(),
            'status' => 'open',
            'last_activity_at' => now(),
        ]);

        Message::create([
            'conta_id' => $this->account->id,
            'conversa_id' => $conversation->id,
            'caixa_entrada_id' => $this->inbox->id,
            'message_type' => 'incoming',
            'sender_type' => 'contato',
            'sender_id' => $contact->id,
            'conteudo' => 'Mensagem inicial',
            'content_type' => 'text',
            'status' => 'sent',
        ]);

        return $conversation;
    }

    public function test_lista_conversas_da_conta(): void
    {
        Sanctum::actingAs($this->createUserRecord());

        $this->createConversationWithMessage();

        $this->getJson('/api/v1/conversas')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.status', 'open')
            ->assertJsonPath('data.items.0.unread', true)
            ->assertJsonPath('data.items.0.unread_count', 1)
            ->assertJsonPath('data.items.0.last_message.preview', 'Mensagem inicial');
    }

    public function test_lista_conversas_falha_fechado_quando_existem_multiplas_contas_sem_contexto_explicito(): void
    {
        Sanctum::actingAs($this->createUserRecord());

        $this->createConversationWithMessage();
        Account::create(['nome' => 'Conta Secundaria', 'proximo_display_id' => 1]);

        $this->getJson('/api/v1/conversas')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'CHAT_ACCOUNT_CONTEXT_UNAVAILABLE');
    }

    public function test_detalhe_marca_conversa_como_lida(): void
    {
        Sanctum::actingAs($this->createUserRecord());

        $conversation = $this->createConversationWithMessage();

        $this->getJson("/api/v1/conversas/{$conversation->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.conversation.messages');

        $conversation->refresh();
        $this->assertNotNull($conversation->lida_em);
    }

    public function test_vincula_contato_ao_cliente_do_erp_por_telefone(): void
    {
        $clienteId = $this->createClientRecord(['telefone1' => '11999998888']);

        Sanctum::actingAs($this->createUserRecord());

        $conversation = $this->createConversationWithMessage($clienteId);

        $this->getJson("/api/v1/conversas/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.conversation.contact.cliente_id', $clienteId);
    }

    public function test_busca_clientes_do_chat_sem_exigir_modulo_amplo_de_clientes(): void
    {
        $clienteId = $this->createClientRecord([
            'nome_razao' => 'Cliente Busca WhatsApp',
            'telefone1' => '(11) 95555-4444',
            'cidade' => 'São Paulo',
        ]);

        Sanctum::actingAs($this->createUserRecord());

        $this->getJson('/api/v1/chat/clientes/search?q=busca')
            ->assertOk()
            ->assertJsonPath('data.clients.0.id', $clienteId)
            ->assertJsonPath('data.clients.0.nome_razao', 'Cliente Busca WhatsApp')
            ->assertJsonPath('data.clients.0.can_start_conversation', true);
    }

    public function test_busca_clientes_do_chat_exige_permissao_do_modulo_atendimento_whatsapp(): void
    {
        $this->createClientRecord(['nome_razao' => 'Cliente Sem Permissao']);

        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 2]));

        $this->getJson('/api/v1/chat/clientes/search?q=cliente')
            ->assertForbidden();
    }

    public function test_lista_conversas_exige_permissao_do_modulo_atendimento_whatsapp(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 2]));

        $this->getJson('/api/v1/conversas')
            ->assertForbidden();
    }

    public function test_detalhe_de_conversa_exige_permissao_do_modulo_atendimento_whatsapp(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 2]));

        $conversation = $this->createConversationWithMessage();

        $this->getJson("/api/v1/conversas/{$conversation->id}")
            ->assertForbidden();
    }

    public function test_envia_mensagem_em_uma_conversa(): void
    {
        Http::fake();

        Sanctum::actingAs($this->createUserRecord());

        $conversation = $this->createConversationWithMessage();

        $this->postJson("/api/v1/conversas/{$conversation->id}/mensagens", [
            'conteudo' => 'Resposta do atendente',
        ])
            ->assertCreated()
            ->assertJsonPath('data.message.message_type', 'outgoing')
            ->assertJsonPath('data.message.conteudo', 'Resposta do atendente');

        $this->assertDatabaseHas('mensagens', [
            'conversa_id' => $conversation->id,
            'message_type' => 'outgoing',
            'conteudo' => 'Resposta do atendente',
        ], 'chat');
    }

    public function test_envia_mensagem_com_anexo(): void
    {
        Storage::fake('local');
        Http::fake();

        Sanctum::actingAs($this->createUserRecord());

        $conversation = $this->createConversationWithMessage();

        $response = $this->post("/api/v1/conversas/{$conversation->id}/mensagens", [
            'conteudo' => 'Segue a foto',
            'attachments' => [
                UploadedFile::fake()->image('foto-cliente.png', 100, 100),
            ],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.message.content_type', 'mixed')
            ->assertJsonPath('data.message.attachments.0.attachment_type', 'image');

        $attachment = MessageAttachment::query()->first();
        $this->assertNotNull($attachment);
        Storage::disk('local')->assertExists((string) $attachment?->storage_path);
    }

    public function test_download_de_anexo_exige_autenticacao(): void
    {
        Storage::fake('local');

        $conversation = $this->createConversationWithMessage();
        $message = Message::query()->where('conversa_id', $conversation->id)->firstOrFail();
        Storage::disk('local')->put('chat-media/teste/documento.pdf', 'pdf');

        $attachment = MessageAttachment::query()->create([
            'mensagem_id' => $message->id,
            'attachment_type' => 'document',
            'transfer_status' => 'available',
            'disk' => 'local',
            'storage_path' => 'chat-media/teste/documento.pdf',
            'original_name' => 'documento.pdf',
            'stored_name' => 'documento.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => 3,
        ]);

        $this->get('/api/v1/chat/anexos/' . $attachment->id)->assertStatus(401);
    }

    public function test_download_de_anexo_exige_permissao_do_modulo_atendimento_whatsapp(): void
    {
        Storage::fake('local');

        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 2]));

        $conversation = $this->createConversationWithMessage();
        $message = Message::query()->where('conversa_id', $conversation->id)->firstOrFail();
        Storage::disk('local')->put('chat-media/teste/documento.pdf', 'pdf');

        $attachment = MessageAttachment::query()->create([
            'mensagem_id' => $message->id,
            'attachment_type' => 'document',
            'transfer_status' => 'available',
            'disk' => 'local',
            'storage_path' => 'chat-media/teste/documento.pdf',
            'original_name' => 'documento.pdf',
            'stored_name' => 'documento.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => 3,
        ]);

        $this->get('/api/v1/chat/anexos/' . $attachment->id)->assertForbidden();
    }

    public function test_autorizacao_do_canal_da_conversa_depende_de_rbac_e_acesso(): void
    {
        $conversation = $this->createConversationWithMessage();
        $allowedUser = $this->createUserRecord();
        $blockedUser = $this->createUserRecord(['grupo_id' => 2]);

        $rbac = app('App\Services\Auth\RbacAuthorizationService');
        $access = app('App\Services\Chat\ConversationAccessService');

        $this->assertTrue($rbac->allows($allowedUser, 'atendimento_whatsapp', 'visualizar'));
        $this->assertFalse($rbac->allows($blockedUser, 'atendimento_whatsapp', 'visualizar'));
        $this->assertTrue($access->canAccessConversation($allowedUser, $conversation));
        $this->assertTrue($access->canAccessConversation($blockedUser, $conversation));
    }

    public function test_nao_envia_mensagem_vazia(): void
    {
        Sanctum::actingAs($this->createUserRecord());

        $conversation = $this->createConversationWithMessage();

        $this->postJson("/api/v1/conversas/{$conversation->id}/mensagens", [
            'conteudo' => '',
        ])->assertStatus(422);
    }

    public function test_requer_autenticacao(): void
    {
        $this->getJson('/api/v1/conversas')->assertStatus(401);
    }

    public function test_inicia_conversa_por_telefone_cria_contato_e_conversa(): void
    {
        Http::fake();

        Sanctum::actingAs($this->createUserRecord());

        $response = $this->postJson('/api/v1/conversas', [
            'telefone' => '11977776666',
            'nome' => 'Novo Contato',
            'mensagem' => 'Ola, tudo bem?',
        ])->assertCreated();

        $response->assertJsonPath('data.conversation.contact.telefone', '+5511977776666')
            ->assertJsonPath('data.conversation.contact.nome', 'Novo Contato')
            ->assertJsonCount(1, 'data.conversation.messages')
            ->assertJsonPath('data.conversation.messages.0.message_type', 'outgoing')
            ->assertJsonPath('data.conversation.messages.0.conteudo', 'Ola, tudo bem?');

        $this->assertDatabaseHas('contatos', ['telefone' => '+5511977776666'], 'chat');
        $this->assertDatabaseHas('mensagens', [
            'conteudo' => 'Ola, tudo bem?',
            'message_type' => 'outgoing',
        ], 'chat');
    }

    public function test_inicia_conversa_por_cliente_do_sistema_hml(): void
    {
        Http::fake();

        $clienteId = $this->createClientRecord([
            'nome_razao' => 'Cliente ERP',
            'telefone1' => '(11) 98888-7777',
        ]);

        Sanctum::actingAs($this->createUserRecord());

        $this->postJson('/api/v1/conversas', [
            'client_id' => $clienteId,
            'mensagem' => 'Mensagem inicial pelo cliente do ERP',
        ])->assertCreated()
            ->assertJsonPath('data.conversation.contact.cliente_id', $clienteId)
            ->assertJsonPath('data.conversation.contact.telefone', '+5511988887777')
            ->assertJsonPath('data.conversation.messages.0.conteudo', 'Mensagem inicial pelo cliente do ERP');
    }

    public function test_inicia_conversa_reaproveita_conversa_existente_do_mesmo_telefone(): void
    {
        Http::fake();

        Sanctum::actingAs($this->createUserRecord());

        $primeira = $this->postJson('/api/v1/conversas', [
            'telefone' => '11988887777',
            'mensagem' => 'Primeira mensagem',
        ])->assertCreated();

        $segunda = $this->postJson('/api/v1/conversas', [
            'telefone' => '11988887777',
            'mensagem' => 'Segunda mensagem',
        ])->assertCreated();

        $this->assertSame(
            $primeira->json('data.conversation.id'),
            $segunda->json('data.conversation.id')
        );

        $this->assertDatabaseCount('contatos', 1, 'chat');
    }

    public function test_inicia_conversa_com_telefone_invalido(): void
    {
        Sanctum::actingAs($this->createUserRecord());

        $this->postJson('/api/v1/conversas', [
            'telefone' => '123',
            'mensagem' => 'Mensagem',
        ])->assertStatus(422);
    }

    public function test_inicia_conversa_sem_mensagem_cria_thread_vazia(): void
    {
        Sanctum::actingAs($this->createUserRecord());

        $this->postJson('/api/v1/conversas', [
            'telefone' => '11977776666',
            'nome' => 'Thread vazia',
        ])
            ->assertCreated()
            ->assertJsonPath('data.conversation.contact.nome', 'Thread vazia')
            ->assertJsonCount(0, 'data.conversation.messages');
    }

    public function test_inicia_conversa_exige_permissao_de_criar_no_modulo_atendimento_whatsapp(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 4]));

        $this->postJson('/api/v1/conversas', [
            'telefone' => '11977776666',
            'mensagem' => 'Mensagem',
        ])->assertForbidden();
    }

    public function test_inicia_conversa_requer_autenticacao(): void
    {
        $this->postJson('/api/v1/conversas', [
            'telefone' => '11977776666',
            'mensagem' => 'Mensagem',
        ])->assertStatus(401);
    }
}
