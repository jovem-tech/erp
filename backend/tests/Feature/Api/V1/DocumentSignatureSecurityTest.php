<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\DispatchDocumentSignatureAssignmentJob;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\User;
use App\Services\Orders\OrderDocumentCenterService;
use App\Services\Integrations\EmailIntegrationSettingsService;
use App\Services\Integrations\IntegrationSettingsService;
use App\Services\Signatures\DocumentSignatureAssignmentNotifier;
use App\Services\Signatures\DocumentSignatureWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class DocumentSignatureSecurityTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        Storage::fake('local');
    }

    public function test_user_enrolls_and_replaces_private_signature_with_password_confirmation(): void
    {
        $user = $this->user('Atendente', 'atendente@example.test', 'SenhaSegura123!');
        Sanctum::actingAs($user);

        $first = $this->post('/api/v1/auth/signature', [
            'current_password' => 'SenhaSegura123!',
            'origin' => 'upload',
            'signature_file' => UploadedFile::fake()->image('assinatura.jpg', 600, 180),
        ]);

        $first->assertOk()->assertJsonPath('data.registered', true);
        $this->assertDatabaseCount('usuario_assinaturas', 1);
        $this->assertDatabaseHas('usuario_assinaturas', [
            'usuario_id' => (int) $user->id,
            'origem' => 'upload',
            'ativa' => true,
        ]);
        $path = (string) $user->activeSignature()->firstOrFail()->arquivo;
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('private/assinaturas/usuarios/', $path);

        $second = $this->post('/api/v1/auth/signature', [
            'current_password' => 'SenhaSegura123!',
            'origin' => 'upload',
            'signature_file' => UploadedFile::fake()->image('nova.png', 500, 160),
        ]);

        $second->assertOk()->assertJsonPath('data.replaced', true);
        $this->assertDatabaseCount('usuario_assinaturas', 2);
        $this->assertSame(1, (int) $user->signatures()->where('ativa', true)->count());
        $this->assertSame(1, (int) $user->signatures()->where('ativa', false)->count());
    }

    public function test_signature_enrollment_rejects_wrong_password_without_persisting_file(): void
    {
        $user = $this->user('Técnico', 'tecnico@example.test', 'SenhaCorreta123!');
        Sanctum::actingAs($user);

        $this->post('/api/v1/auth/signature', [
            'current_password' => 'senha-incorreta',
            'origin' => 'upload',
            'signature_file' => UploadedFile::fake()->image('assinatura.png', 500, 160),
        ])->assertStatus(422)->assertJsonPath('error.code', 'SIGNATURE_INVALID_PASSWORD');

        $this->assertDatabaseCount('usuario_assinaturas', 0);
        Storage::disk('local')->assertDirectoryEmpty('private/assinaturas');
    }

    public function test_reauthentication_attributes_signature_to_target_without_changing_session_actor(): void
    {
        $actor = $this->user('Atendente', 'balcao@example.test', 'SenhaAtendente123!');
        $target = $this->user('Técnico', 'tecnico@example.test', 'SenhaTecnico123!');

        Sanctum::actingAs($target);
        $this->post('/api/v1/auth/signature', [
            'current_password' => 'SenhaTecnico123!',
            'origin' => 'upload',
            'signature_file' => UploadedFile::fake()->image('assinatura.png', 500, 160),
        ])->assertOk();

        $request = Request::create('/api/v1/orders/1/documents/generate', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.10',
        ]);
        $result = app(DocumentSignatureWorkflowService::class)->resolveImmediateSigner($actor, [
            'signature_mode' => 'reauth',
            'signature_user_id' => (int) $target->id,
            'signature_email' => 'tecnico@example.test',
            'signature_password' => 'SenhaTecnico123!',
        ], $request);

        $this->assertSame((int) $target->id, (int) $result['signer']->id);
        $this->assertSame('reautenticacao', $result['method']);
        $this->assertSame((int) $actor->id, (int) $actor->id, 'A sessão/ator original permanece separado do signatário.');
    }

    public function test_pending_assignment_creates_letter_notification_and_audited_external_deliveries(): void
    {
        Queue::fake();
        Mail::fake();

        $actor = $this->user('Atendente', 'atendente@example.test', 'SenhaAtendente123!');
        $responsible = $this->user('Técnico Responsável', 'tecnico@example.test', 'SenhaTecnico123!');
        $responsible->forceFill(['telefone' => '(22) 99999-1234'])->save();

        Sanctum::actingAs($responsible);
        $this->post('/api/v1/auth/signature', [
            'current_password' => 'SenhaTecnico123!',
            'origin' => 'upload',
            'signature_file' => UploadedFile::fake()->image('assinatura.png', 500, 160),
        ])->assertOk();

        $clientId = DB::table('clientes')->insertGetId([
            'nome_razao' => 'Cliente da Designação',
            'telefone1' => '(22) 98888-0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $equipmentId = DB::table('equipamentos')->insertGetId([
            'cliente_id' => $clientId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('os')->insertGetId([
            'numero_os' => 'OS-NOTIFY-1',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'relato_cliente' => 'Teste de aviso de assinatura',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = Order::query()->findOrFail($orderId);

        $pending = app(DocumentSignatureWorkflowService::class)
            ->createPending($order, $actor, $responsible, ['os_laudo_tecnico'])[0];

        $this->assertDatabaseHas('mobile_notifications', [
            'usuario_id' => (int) $responsible->id,
            'tipo_evento' => 'document.signature.requested',
            'rota_destino' => '/os/' . $orderId . '/documentos#assinaturas-pendentes',
        ]);
        $this->assertDatabaseHas('documento_assinatura_notificacoes', [
            'solicitacao_id' => (int) $pending->id,
            'canal' => 'in_app',
            'status' => 'enviada',
        ]);
        $this->assertDatabaseHas('documento_assinatura_notificacoes', [
            'solicitacao_id' => (int) $pending->id,
            'canal' => 'email',
            'status' => 'pendente',
        ]);
        $this->assertDatabaseHas('documento_assinatura_notificacoes', [
            'solicitacao_id' => (int) $pending->id,
            'canal' => 'whatsapp',
            'status' => 'pendente',
        ]);
        Queue::assertPushed(
            DispatchDocumentSignatureAssignmentJob::class,
            static fn (DispatchDocumentSignatureAssignmentJob $job): bool => $job->signatureRequestId === (int) $pending->id
        );

        $this->mock(EmailIntegrationSettingsService::class, function ($mock): void {
            $mock->shouldReceive('operationalMailerAvailable')->once()->andReturn(true);
        });
        $this->mock(IntegrationSettingsService::class, function ($mock): void {
            $mock->shouldReceive('sendDirectMessage')
                ->once()
                ->withArgs(static fn (string $phone, string $message): bool => str_contains($phone, '99999-1234')
                    && str_contains($message, 'OS-NOTIFY-1'))
                ->andReturn([
                    'ok' => true,
                    'provider' => 'provider-test',
                    'reference' => 'wa-123',
                    'message' => 'Enviado.',
                ]);
        });

        app(DocumentSignatureAssignmentNotifier::class)->dispatchExternal((int) $pending->id);

        $this->assertDatabaseHas('documento_assinatura_notificacoes', [
            'solicitacao_id' => (int) $pending->id,
            'canal' => 'email',
            'status' => 'enviada',
            'tentativas' => 1,
        ]);
        $this->assertDatabaseHas('documento_assinatura_notificacoes', [
            'solicitacao_id' => (int) $pending->id,
            'canal' => 'whatsapp',
            'status' => 'enviada',
            'provider' => 'provider-test',
            'referencia' => 'wa-123',
            'tentativas' => 1,
        ]);
        $this->assertDatabaseMissing('documento_assinatura_notificacoes', [
            'destinatario_resumo' => 'tecnico@example.test',
        ]);
    }

    public function test_client_link_uses_one_time_hashed_token_and_records_consent_audit(): void
    {
        $actor = $this->user('Atendente', 'atendente@example.test', 'SenhaAtendente123!');
        Sanctum::actingAs($actor);
        $this->post('/api/v1/auth/signature', [
            'current_password' => 'SenhaAtendente123!',
            'origin' => 'upload',
            'signature_file' => UploadedFile::fake()->image('assinatura.png', 500, 160),
        ])->assertOk();

        $clientId = DB::table('clientes')->insertGetId([
            'nome_razao' => 'Cliente de Teste',
            'telefone1' => '(22) 99999-0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $equipmentId = DB::table('equipamentos')->insertGetId([
            'cliente_id' => $clientId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('os')->insertGetId([
            'numero_os' => 'OS-ASSINATURA-1',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'relato_cliente' => 'Teste de assinatura do cliente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = Order::query()->findOrFail($orderId);

        $workflow = app(DocumentSignatureWorkflowService::class);
        $pending = $workflow->createClientPending($order, $actor, ['abertura'])[0];
        $token = $pending['token'];
        $request = $pending['request'];

        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), (string) $request->token_hash);
        $this->assertDatabaseMissing('documento_solicitacoes_assinatura', ['token_hash' => $token]);
        $this->assertSame((int) $request->id, (int) $workflow->resolvePublic($token)?->id);

        $document = OrderDocument::query()->create([
            'os_id' => $orderId,
            'tipo_documento' => 'abertura',
            'arquivo' => 'private/teste/abertura.pdf',
            'versao' => 1,
            'hash_sha256' => str_repeat('a', 64),
            'gerado_por' => (int) $actor->id,
        ]);
        $workflow->completeCustomer(
            $request,
            $document,
            ['path' => 'private/assinaturas/clientes/teste.png', 'hash_sha256' => str_repeat('b', 64)],
            'Cliente de Teste',
            '192.0.2.10',
            'Test Browser'
        );

        $request->refresh();
        $document->refresh();
        $this->assertSame('assinada', (string) $request->status);
        $this->assertNull($request->token_hash);
        $this->assertSame('cliente_canvas', (string) $request->metodo_assinatura);
        $this->assertSame('cliente_link', (string) $document->metodo_assinatura);
        $this->assertNull($workflow->resolvePublic($token), 'O token deixa de ser utilizável após a assinatura.');
    }

    public function test_staff_signature_is_rejected_until_the_assigned_document_was_reviewed(): void
    {
        Queue::fake();
        $responsible = $this->user('Técnico Revisor', 'revisor@example.test', 'SenhaTecnico123!');
        Sanctum::actingAs($responsible);
        $this->post('/api/v1/auth/signature', [
            'current_password' => 'SenhaTecnico123!',
            'origin' => 'upload',
            'signature_file' => UploadedFile::fake()->image('assinatura.png', 500, 160),
        ])->assertOk();

        $order = $this->signatureReviewOrder('OS-REVIEW-BLOCK');
        $pending = app(DocumentSignatureWorkflowService::class)
            ->createPending($order, $responsible, $responsible, ['abertura'])[0];
        $this->mock(OrderDocumentCenterService::class, function ($mock): void {
            $mock->shouldReceive('pendingSignatureTemplateFingerprint')
                ->once()
                ->andReturn(str_repeat('c', 64));
        });

        $this->postJson('/api/v1/document-signatures/' . (int) $pending->id . '/sign', [
            'review_confirmed' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SIGNATURE_REQUEST_INVALID')
            ->assertJsonPath('error.message', 'Visualize e analise o documento antes de assinar. A revisão é válida por 30 minutos.');
    }

    public function test_preview_records_audited_review_before_enabling_staff_signature(): void
    {
        Queue::fake();
        $responsible = $this->user('Técnico Revisor', 'preview@example.test', 'SenhaTecnico123!');
        Sanctum::actingAs($responsible);
        $this->post('/api/v1/auth/signature', [
            'current_password' => 'SenhaTecnico123!',
            'origin' => 'upload',
            'signature_file' => UploadedFile::fake()->image('assinatura.png', 500, 160),
        ])->assertOk();
        $order = $this->signatureReviewOrder('OS-REVIEW-PREVIEW');
        $pending = app(DocumentSignatureWorkflowService::class)
            ->createPending($order, $responsible, $responsible, ['abertura'])[0];

        $this->mock(OrderDocumentCenterService::class, function ($mock): void {
            $mock->shouldReceive('previewPendingSignature')
                ->once()
                ->andReturn([
                    'result' => 'ok',
                    'bytes' => '%PDF-1.4 reviewed document',
                    'filename' => 'previa-abertura.pdf',
                    'template_fingerprint' => str_repeat('c', 64),
                ]);
        });
        $response = $this->get('/api/v1/document-signatures/' . (int) $pending->id . '/preview', [
            'User-Agent' => 'Review Test Browser',
        ]);
        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertSee('%PDF-1.4 reviewed document', false);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $pending->refresh();
        $this->assertSame((int) $responsible->id, (int) $pending->revisada_por);
        $this->assertSame((string) $pending->snapshot_os_hash, (string) $pending->revisao_snapshot_hash);
        $this->assertSame(str_repeat('c', 64), (string) $pending->revisao_template_hash);
        $this->assertNotNull($pending->revisada_em);
        $this->assertNotNull($pending->revisao_ip_hash);
        $this->assertNotNull($pending->revisao_user_agent_hash);
        $this->assertNull($pending->revisao_confirmada_em);
    }

    private function signatureReviewOrder(string $number): Order
    {
        $clientId = DB::table('clientes')->insertGetId([
            'nome_razao' => 'Cliente da Revisão',
            'telefone1' => '(22) 98888-0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $equipmentId = DB::table('equipamentos')->insertGetId([
            'cliente_id' => $clientId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('os')->insertGetId([
            'numero_os' => $number,
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'relato_cliente' => 'Documento sujeito à revisão obrigatória',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::query()->findOrFail($orderId);
    }

    private function user(string $name, string $email, string $password): User
    {
        return User::query()->create([
            'nome' => $name,
            'email' => $email,
            'senha' => Hash::make($password),
            'perfil' => 'Administrador',
            'grupo_id' => 1,
            'ativo' => true,
        ]);
    }
}
