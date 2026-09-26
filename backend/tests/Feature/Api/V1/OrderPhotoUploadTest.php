<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * POST /api/v1/orders/{order}/photos — fotos anexadas direto da visualizacao
 * da OS, sem a edicao completa (specs/048).
 */
class OrderPhotoUploadTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private string $photoTempDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();
        $this->seedOrderNumberConfiguration();

        $this->grantGroupPermissions(1, [
            'os' => ['visualizar', 'criar', 'editar', 'excluir'],
        ]);
        $this->grantGroupPermissions(2, [
            'os' => ['visualizar', 'editar'],
        ]);
        $this->grantGroupPermissions(3, [
            'os' => ['visualizar'],
        ]);

        // O diretorio padrao do otimizador e' 0700 do www-data no servidor;
        // quem roda a suite (outro usuario) nao grava la.
        $this->photoTempDirectory = storage_path('framework/testing/order-photo-tmp-'.str_replace('.', '-', uniqid('', true)));
        config(['operational-photos.temporary_directory' => $this->photoTempDirectory]);

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->photoTempDirectory);

        parent::tearDown();
    }

    public function test_admin_attaches_diagnosis_photos_without_editing_the_order(): void
    {
        [$manager, , $orderId] = $this->seedOrderContext();
        $token = $this->loginAndGetToken($manager->email);
        $before = (array) DB::table('os')->where('id', $orderId)->first();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/v1/orders/{$orderId}/photos", [
                'tipo' => 'diagnostico',
                'fotos' => [
                    UploadedFile::fake()->image('placa-1.jpg', 640, 480),
                    UploadedFile::fake()->image('print-da-tela.png', 320, 240),
                ],
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data.foto_ids')
            ->assertJsonCount(2, 'data.fotos')
            ->assertJsonPath('data.fotos.0.tipo', 'diagnostico')
            ->assertJsonPath('data.fotos.0.tipo_label', 'Diagnóstico')
            ->assertJsonPath('data.fotos.1.tipo', 'diagnostico');

        $photoIds = array_map('intval', (array) $response->json('data.foto_ids'));
        $this->assertSame($photoIds, array_map('intval', array_column((array) $response->json('data.fotos'), 'id')));

        $rows = DB::table('os_fotos')->where('os_id', $orderId)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('diagnostico', $row->tipo);
            $this->assertStringStartsWith("private/os/{$orderId}/", (string) $row->arquivo);
            Storage::disk('local')->assertExists((string) $row->arquivo);
        }

        $event = DB::table('os_eventos')
            ->where('os_id', $orderId)
            ->where('tipo', 'fotos_adicionadas')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('registro', $event->categoria);
        $this->assertSame((int) $manager->id, (int) $event->usuario_id);
        $this->assertSame('2 foto(s) de diagnóstico anexada(s) à OS.', $event->descricao);
        $dados = json_decode((string) $event->dados, true);
        $this->assertSame('diagnostico', $dados['tipo'] ?? null);
        $this->assertSame($photoIds, array_map('intval', (array) ($dados['foto_ids'] ?? [])));

        // Anexar foto nao e' editar a OS: nenhum campo do cadastro muda.
        $after = (array) DB::table('os')->where('id', $orderId)->first();
        $this->assertSame($before, $after);
    }

    public function test_category_defaults_to_reception_and_response_carries_the_whole_gallery(): void
    {
        [$manager, , $orderId] = $this->seedOrderContext();
        $existingId = (int) DB::table('os_fotos')->insertGetId([
            'os_id' => $orderId,
            'tipo' => 'recepcao',
            'arquivo' => 'os0001_recepcao_1.jpg',
            'created_at' => now(),
        ]);
        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/v1/orders/{$orderId}/photos", [
                'fotos' => [UploadedFile::fake()->image('entrada.jpg')],
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonCount(1, 'data.foto_ids')
            ->assertJsonCount(2, 'data.fotos')
            ->assertJsonPath('data.fotos.0.id', $existingId)
            ->assertJsonPath('data.fotos.1.tipo', 'recepcao')
            ->assertJsonPath('data.fotos.1.tipo_label', 'Recepção');

        $this->assertDatabaseHas('os_eventos', [
            'os_id' => $orderId,
            'tipo' => 'fotos_adicionadas',
            'descricao' => '1 foto(s) de recepção anexada(s) à OS.',
        ]);
    }

    public function test_rejects_unknown_category_empty_batch_more_than_four_photos_and_non_images(): void
    {
        [$manager, , $orderId] = $this->seedOrderContext();
        $token = $this->loginAndGetToken($manager->email);

        $cases = [
            'categoria fora do enum' => [
                'tipo' => 'reparo',
                'fotos' => [UploadedFile::fake()->image('a.jpg')],
            ],
            'sem fotos' => [
                'tipo' => 'diagnostico',
            ],
            'cinco fotos' => [
                'fotos' => array_map(
                    static fn (int $i): UploadedFile => UploadedFile::fake()->image("foto-{$i}.jpg"),
                    range(1, 5)
                ),
            ],
            'pdf com extensao de foto' => [
                'fotos' => [UploadedFile::fake()->createWithContent('laudo.jpg', "%PDF-1.4\n%fake\n")],
            ],
        ];

        foreach ($cases as $label => $payload) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->post("/api/v1/orders/{$orderId}/photos", $payload, ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_ERROR', $label);
        }

        $this->assertSame(0, DB::table('os_fotos')->where('os_id', $orderId)->count());
        $this->assertSame(0, DB::table('os_eventos')->where('os_id', $orderId)->where('tipo', 'fotos_adicionadas')->count());
    }

    public function test_technician_attaches_photos_only_to_the_order_assigned_to_them(): void
    {
        [, $technician, $assignedOrderId, $clientId, $equipmentId] = $this->seedOrderContext();
        $otherOrderId = $this->createOrderRecord([
            'numero_os' => 'OS26090077',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'tecnico_id' => null,
            'status' => 'diagnostico',
        ]);
        $token = $this->loginAndGetToken($technician->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/v1/orders/{$otherOrderId}/photos", [
                'fotos' => [UploadedFile::fake()->image('alheia.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ORDER_FORBIDDEN');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/v1/orders/{$assignedOrderId}/photos", [
                'tipo' => 'entrega',
                'fotos' => [UploadedFile::fake()->image('entrega.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.fotos.0.tipo', 'entrega');

        $this->assertSame(0, DB::table('os_fotos')->where('os_id', $otherOrderId)->count());
        $this->assertSame(1, DB::table('os_fotos')->where('os_id', $assignedOrderId)->count());
    }

    public function test_view_only_user_cannot_attach_photos(): void
    {
        [, , $orderId] = $this->seedOrderContext();
        $viewer = $this->createUserRecord([
            'nome' => 'Atendente',
            'email' => 'atendente@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->loginAndGetToken($viewer->email))
            ->post("/api/v1/orders/{$orderId}/photos", [
                'fotos' => [UploadedFile::fake()->image('a.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertSame(0, DB::table('os_fotos')->count());
    }

    public function test_unknown_order_returns_404(): void
    {
        [$manager] = $this->seedOrderContext();

        $this->withHeader('Authorization', 'Bearer '.$this->loginAndGetToken($manager->email))
            ->post('/api/v1/orders/999999/photos', [
                'fotos' => [UploadedFile::fake()->image('a.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

        $this->assertSame(0, DB::table('os_fotos')->count());
    }

    public function test_route_shares_the_operational_photo_upload_throttle(): void
    {
        [$manager, , $orderId] = $this->seedOrderContext();
        $token = $this->loginAndGetToken($manager->email);

        // Categoria invalida falha rapido na validacao, depois do throttle —
        // cada tentativa conta sem precisar processar imagem de verdade.
        $attempt = fn () => $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/v1/orders/{$orderId}/photos", [
                'tipo' => 'invalida',
                'fotos' => [UploadedFile::fake()->image('a.jpg')],
            ], ['Accept' => 'application/json']);

        for ($i = 1; $i <= 8; $i++) {
            $attempt()->assertStatus(422);
        }

        $attempt()
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'PHOTO_RATE_LIMITED');
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int}
     */
    private function seedOrderContext(): array
    {
        $manager = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $technician = $this->createUserRecord([
            'nome' => 'Técnico Bancada',
            'email' => 'tecnico.bancada@example.com',
            'perfil' => 'tecnico',
            'grupo_id' => 2,
        ]);

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Fotos',
            'cpf_cnpj' => '44.444.444/0001-44',
        ]);
        $equipmentId = $this->createEquipmentRecord($clientId, [
            'resumo_tecnico' => 'Notebook Dell Inspiron',
        ]);

        $orderId = $this->createOrderRecord([
            'numero_os' => 'OS26090042',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'tecnico_id' => $technician->id,
            'status' => 'diagnostico',
            'relato_cliente' => 'Não liga depois de queda.',
        ]);

        return [$manager, $technician, $orderId, $clientId, $equipmentId];
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop',
        ]);

        return (string) $response->json('data.access_token');
    }
}
