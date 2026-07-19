<?php

namespace Tests\Feature\Api\V1;

use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\User;
use App\Services\Signatures\DocumentSignatureWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
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
