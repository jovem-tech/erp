<?php

namespace Tests\Feature\Files;

use App\Contracts\Files\PdfThumbnailRenderer;
use App\DTO\Files\FileContext;
use App\DTO\Files\FileDescriptor;
use App\Enums\Files\FileCategory;
use App\Enums\Files\FileIntegrityStatus;
use App\Enums\Files\FileLifecycleStatus;
use App\Enums\Files\FileOrigin;
use App\Models\Files\ManagedFile;
use App\Models\Files\ManagedFileEvent;
use App\Services\Auth\RbacAuthorizationService;
use App\Services\Files\FileManagerFacade;
use App\Services\Files\LegacyCompatibleFileAdapter;
use App\Services\Files\ManagedFilePurgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class FileManagerApiTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedFileManagerRbac();
        Storage::fake('local');
        config()->set('cache.default', 'array');
        config()->set('file-manager.mode', 'hybrid');
        config()->set('file-manager.enabled_categories', ['company_logo']);
        config()->set('file-manager.kill_switches.allow_writes', true);
        config()->set('file-manager.kill_switches.allow_admin_state_mutations', true);
        config()->set('file-manager.kill_switches.allow_permanent_deletion', true);
    }

    public function test_catalog_dashboard_and_detail_never_expose_storage_path(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['listar', 'metadados'],
        ]);
        $actor = $this->createUserRecord(['grupo_id' => 1]);
        $file = $this->createManagedLogo($actor->id);
        Sanctum::actingAs($actor, ['*']);

        $dashboard = $this->getJson('/api/v1/file-manager/dashboard');
        $dashboard->assertOk()
            ->assertJsonPath('data.totals.files', 1)
            ->assertJsonPath('data.operation.mode', 'hybrid')
            ->assertJsonPath('data.operation.cataloging_new_files', true)
            ->assertJsonPath('data.operation.central_writes_enabled', true)
            ->assertJsonPath('data.operation.enabled_categories.0', 'company_logo')
            ->assertJsonPath('data.by_category.0.category', 'company_logo');

        $index = $this->getJson('/api/v1/files?category=company_logo&per_page=10');
        $index->assertOk()
            ->assertJsonPath('data.0.uuid', $file->uuid)
            ->assertJsonPath('meta.pagination.total', 1);

        $detail = $this->getJson('/api/v1/files/'.$file->uuid);
        $detail->assertOk()
            ->assertJsonPath('data.links.0.subject_type', 'configuration');

        foreach ([$dashboard->getContent(), $index->getContent(), $detail->getContent()] as $payload) {
            $this->assertStringNotContainsString('storage_key', $payload);
            $this->assertStringNotContainsString('managed-files/company_logo', $payload);
            $this->assertStringNotContainsString(Storage::disk('local')->path(''), $payload);
        }
    }

    public function test_missing_trashed_records_are_partitioned_into_the_audit_collection(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['listar', 'metadados', 'administrar'],
            'configuracoes' => ['visualizar'],
        ]);
        $actor = $this->createUserRecord(['grupo_id' => 1]);
        $present = $this->createManagedLogo($actor->id, 'trash-present');
        $missing = $this->createManagedLogo($actor->id, 'trash-audit-missing');
        $present->forceFill([
            'lifecycle_status' => FileLifecycleStatus::Trashed,
            'trashed_at' => now()->subDay(),
        ])->save();
        $missing->forceFill([
            'lifecycle_status' => FileLifecycleStatus::Trashed,
            'integrity_status' => FileIntegrityStatus::Missing,
            'trashed_at' => now()->subDay(),
        ])->save();
        Storage::disk('local')->delete((string) $missing->storage_key);
        Sanctum::actingAs($actor, ['*']);

        $this->getJson('/api/v1/file-manager/dashboard')
            ->assertOk()
            ->assertJsonPath('data.totals.files', 1)
            ->assertJsonPath('data.totals.trashed', 1)
            ->assertJsonPath('data.totals.audit_records', 1)
            ->assertJsonPath('data.totals.integrity_issues', 1)
            ->assertJsonPath('data.by_category.0.file_count', 1);

        $this->getJson('/api/v1/files?lifecycle_status=trashed')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.uuid', $present->uuid);

        $this->getJson('/api/v1/files?audit_only=1')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.integrity_status', 'missing');
    }

    public function test_download_requires_panel_and_linked_domain_authorization(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['baixar'],
            'configuracoes' => ['visualizar'],
        ]);
        $this->grantGroupPermissions(3, [
            'arquivos' => ['baixar'],
        ]);
        $allowed = $this->createUserRecord(['grupo_id' => 1]);
        $denied = $this->createUserRecord(['grupo_id' => 3]);
        $file = $this->createManagedLogo($allowed->id);

        Sanctum::actingAs($allowed, ['*']);
        $download = $this->get('/api/v1/files/'.$file->uuid.'/download');
        $download->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('attachment;', (string) $download->headers->get('Content-Disposition'));
        $this->assertNotSame('', $download->streamedContent());

        app(RbacAuthorizationService::class)->forgetUser((int) $denied->id);
        Sanctum::actingAs($denied, ['*']);
        $this->get('/api/v1/files/'.$file->uuid.'/download')->assertNotFound();
    }

    public function test_catalog_exposes_linked_client_only_when_actor_can_view_the_order(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['listar'],
            'os' => ['visualizar'],
        ]);
        $this->grantGroupPermissions(3, [
            'arquivos' => ['listar'],
        ]);

        $allowed = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'gerente']);
        $denied = $this->createUserRecord(['grupo_id' => 3, 'perfil' => 'atendente']);
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Vinculado ao Card']);
        $equipmentId = $this->createEquipmentRecord($clientId);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
        ]);
        config()->set('file-manager.mode', 'shadow');
        config()->set('file-manager.enabled_categories', ['order_photo']);

        $path = 'private/os/'.$orderId.'/card-cliente.jpg';
        $image = UploadedFile::fake()->image('card-cliente.jpg', 80, 60);
        Storage::disk('local')->put($path, $image->getContent());
        $file = app(LegacyCompatibleFileAdapter::class)->synchronizeExisting(
            new FileContext(
                category: FileCategory::OrderPhoto,
                origin: FileOrigin::Upload,
                operationKey: 'order-photo-card-client:'.$orderId,
                subjectType: 'order',
                subjectId: $orderId,
                relation: 'photo:1'
            ),
            'local',
            $path
        );

        $this->assertInstanceOf(ManagedFile::class, $file);
        $file->forceFill(['created_at' => '2026-07-20 14:35:00'])->saveQuietly();

        Sanctum::actingAs($allowed, ['*']);
        $this->getJson('/api/v1/files?category=order_photo&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $file->uuid)
            ->assertJsonPath('data.0.created_at', '2026-07-20T14:35:00-03:00')
            ->assertJsonPath('data.0.document_created_at', '2026-07-20T14:35:00-03:00')
            ->assertJsonPath('data.0.linked_client.id', $clientId)
            ->assertJsonPath('data.0.linked_client.name', 'Cliente Vinculado ao Card');

        Sanctum::actingAs($denied, ['*']);
        $this->getJson('/api/v1/files?category=order_photo&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $file->uuid)
            ->assertJsonPath('data.0.linked_client', null);
    }

    public function test_order_photo_preview_is_delivered_from_the_private_order_namespace(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['baixar'],
            'os' => ['visualizar'],
        ]);
        $actor = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'gerente']);
        $clientId = $this->createClientRecord();
        $equipmentId = $this->createEquipmentRecord($clientId);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
        ]);
        config()->set('file-manager.mode', 'shadow');
        config()->set('file-manager.enabled_categories', ['order_photo']);

        $path = 'private/os/'.$orderId.'/recepcao.jpg';
        $image = UploadedFile::fake()->image('recepcao.jpg', 80, 60);
        Storage::disk('local')->put($path, $image->getContent());
        $file = app(LegacyCompatibleFileAdapter::class)->synchronizeExisting(
            new FileContext(
                category: FileCategory::OrderPhoto,
                origin: FileOrigin::Upload,
                operationKey: 'order-photo-preview:'.$orderId,
                subjectType: 'order',
                subjectId: $orderId,
                relation: 'photo:1'
            ),
            'local',
            $path
        );

        $this->assertInstanceOf(ManagedFile::class, $file);
        Sanctum::actingAs($actor, ['*']);

        $preview = $this->get('/api/v1/files/'.$file->uuid.'/preview');

        $preview->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($image->getContent(), $preview->streamedContent());
    }

    public function test_pdf_thumbnail_is_authorized_rendered_once_and_reused_from_cache(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['baixar'],
            'os' => ['visualizar'],
        ]);
        $actor = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'gerente']);
        $clientId = $this->createClientRecord();
        $equipmentId = $this->createEquipmentRecord($clientId);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
        ]);
        config()->set('file-manager.mode', 'shadow');
        config()->set('file-manager.enabled_categories', ['order_pdf']);
        config()->set('file-manager.pdf_thumbnails.enabled', true);

        $path = 'private/os/'.$orderId.'/documentos/abertura.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\nconteudo-controlado\n%%EOF");
        $file = app(LegacyCompatibleFileAdapter::class)->synchronizeExisting(
            new FileContext(
                category: FileCategory::OrderPdf,
                origin: FileOrigin::Upload,
                operationKey: 'order-pdf-thumbnail:'.$orderId,
                subjectType: 'order',
                subjectId: $orderId,
                relation: 'document:1'
            ),
            'local',
            $path
        );

        $thumbnail = UploadedFile::fake()->image('pagina-1.png', 4, 4)->getContent();
        $renderer = new class($thumbnail) implements PdfThumbnailRenderer
        {
            public int $calls = 0;

            public function __construct(private readonly string $payload) {}

            public function render(
                string $sourcePath,
                string $targetPath,
                int $maxDimension,
                int $timeoutSeconds
            ): void {
                $this->calls++;
                file_put_contents($targetPath, $this->payload);
            }
        };
        $this->app->instance(PdfThumbnailRenderer::class, $renderer);

        $this->assertInstanceOf(ManagedFile::class, $file);
        Sanctum::actingAs($actor, ['*']);

        $first = $this->get('/api/v1/files/'.$file->uuid.'/thumbnail');
        $second = $this->get('/api/v1/files/'.$file->uuid.'/thumbnail');

        $first->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $cacheControl = (string) $first->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=86400', $cacheControl);
        $this->assertStringStartsWith('"pdf-p1-', (string) $first->headers->get('ETag'));
        $this->assertSame(
            $thumbnail,
            file_get_contents($first->baseResponse->getFile()->getPathname())
        );
        $second->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(1, $renderer->calls);
    }

    public function test_batch_download_builds_a_bounded_zip_without_exposing_storage_paths(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['baixar', 'administrar'],
            'configuracoes' => ['visualizar'],
        ]);
        $this->grantGroupPermissions(3, [
            'arquivos' => ['baixar'],
        ]);
        $actor = $this->createUserRecord(['grupo_id' => 1]);
        $nonAdmin = $this->createUserRecord(['grupo_id' => 3]);
        $first = $this->createManagedLogo($actor->id, 'first');
        $second = $this->createManagedLogo($actor->id, 'second');
        $first->forceFill(['origin' => FileOrigin::Legacy])->save();
        Sanctum::actingAs($actor, ['*']);

        $response = $this->post('/api/v1/files/download-batch', [
            'file_uuids' => [$first->uuid, $second->uuid],
        ]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('arquivos-selecionados-', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('managed-files/', (string) $response->headers->get('Content-Disposition'));

        app(RbacAuthorizationService::class)->forgetUser((int) $nonAdmin->id);
        Sanctum::actingAs($nonAdmin, ['*']);
        $this->post('/api/v1/files/download-batch', [
            'file_uuids' => [$first->uuid, $second->uuid],
        ])->assertNotFound();
    }

    public function test_batch_trash_is_recoverable_requires_step_up_and_preserves_binary(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['excluir', 'administrar'],
            'configuracoes' => ['editar'],
        ]);
        $actor = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'atendente']);
        $admin = $this->createUserRecord([
            'grupo_id' => 1,
            'perfil' => 'atendente',
            'email' => 'supervisor.lixeira@example.com',
        ]);
        $file = $this->createManagedLogo($actor->id, 'trash');
        $storageKey = (string) $file->storage_key;
        Sanctum::actingAs($actor, ['*']);

        $this->postJson('/api/v1/files/trash-batch', [
            'file_uuids' => [$file->uuid],
            'reason' => 'Arquivo substituído por uma versão revisada.',
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ])->assertOk()
            ->assertJsonPath('data.trashed_count', 1)
            ->assertJsonPath('data.file_uuids.0', $file->uuid);

        $this->assertSame('trashed', $file->fresh()->lifecycle_status->value);
        Storage::disk('local')->assertExists($storageKey);
        $this->assertDatabaseHas('managed_file_events', [
            'file_id' => $file->id,
            'action' => 'TRASHED',
            'actor_id' => $actor->id,
        ]);
    }

    public function test_trashed_file_without_active_link_can_be_previewed_and_restored_by_administrator(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['baixar', 'restaurar', 'administrar'],
            'configuracoes' => ['visualizar', 'editar'],
        ]);
        $this->grantGroupPermissions(3, [
            'arquivos' => ['baixar', 'restaurar'],
        ]);
        $actor = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'atendente']);
        $nonAdmin = $this->createUserRecord(['grupo_id' => 3, 'perfil' => 'atendente']);
        $admin = $this->createUserRecord([
            'grupo_id' => 1,
            'perfil' => 'atendente',
            'email' => 'supervisor.restore@example.com',
        ]);
        $file = $this->createManagedLogo($actor->id, 'restore-batch');
        $file->forceFill([
            'lifecycle_status' => FileLifecycleStatus::Trashed,
            'trashed_at' => now(),
        ])->save();
        $file->links()->update([
            'is_current' => false,
            'unlinked_at' => now(),
        ]);
        $file->unsetRelation('links');

        Sanctum::actingAs($nonAdmin, ['*']);
        $this->get('/api/v1/files/'.$file->uuid.'/preview')->assertNotFound();
        $this->postJson('/api/v1/files/restore-batch', [
            'file_uuids' => [$file->uuid],
            'reason' => 'Tentativa sem permissão administrativa do módulo.',
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ])->assertNotFound();

        Sanctum::actingAs($actor, ['*']);

        $preview = $this->get('/api/v1/files/'.$file->uuid.'/preview');
        $preview->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertNotSame('', $preview->streamedContent());
        $this->get('/api/v1/files/'.$file->uuid.'/download')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FILE_DELIVERY_BLOCKED');

        $this->postJson('/api/v1/files/restore-batch', [
            'file_uuids' => [$file->uuid],
            'reason' => 'Documento recuperado após conferência administrativa.',
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ])->assertOk()
            ->assertJsonPath('data.restored_count', 1)
            ->assertJsonPath('data.file_uuids.0', $file->uuid);

        $this->assertSame(FileLifecycleStatus::Active, $file->fresh()->lifecycle_status);
        $this->assertNull($file->fresh()->trashed_at);
    }

    public function test_trashed_file_without_binary_is_not_restored_to_active_lifecycle(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['restaurar', 'administrar'],
            'configuracoes' => ['visualizar', 'editar'],
        ]);
        $actor = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'atendente']);
        $admin = $this->createUserRecord([
            'grupo_id' => 1,
            'perfil' => 'atendente',
            'email' => 'supervisor.restore-missing@example.com',
        ]);
        $file = $this->createManagedLogo($actor->id, 'restore-missing');
        Storage::disk('local')->delete((string) $file->storage_key);
        $file->forceFill([
            'lifecycle_status' => FileLifecycleStatus::Trashed,
            'trashed_at' => now(),
        ])->save();
        $file->links()->update([
            'is_current' => false,
            'unlinked_at' => now(),
        ]);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson('/api/v1/files/restore-batch', [
            'file_uuids' => [$file->uuid],
            'reason' => 'Tentativa controlada de restaurar documento sem binário.',
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ])->assertStatus(409)
            ->assertJsonPath('error.code', 'FILE_RESTORE_SOURCE_UNAVAILABLE');

        $this->assertSame(FileLifecycleStatus::Trashed, $file->fresh()->lifecycle_status);
    }

    public function test_permanent_deletion_removes_binary_and_keeps_an_auditable_tombstone(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['listar', 'excluir', 'administrar'],
            'configuracoes' => ['editar'],
        ]);
        $actor = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'atendente']);
        $admin = $this->createUserRecord([
            'grupo_id' => 1,
            'perfil' => 'atendente',
            'email' => 'supervisor.purge@example.com',
        ]);
        $file = $this->createManagedLogo($actor->id, 'permanent-delete');
        $storageKey = (string) $file->storage_key;
        $file->forceFill([
            'lifecycle_status' => FileLifecycleStatus::Trashed,
            'trashed_at' => now()->subDays(2),
        ])->save();
        Sanctum::actingAs($actor, ['*']);

        $this->postJson('/api/v1/files/purge-batch', [
            'file_uuids' => [$file->uuid],
            'reason' => 'Exclusão definitiva aprovada após conferência do documento.',
            'confirmation' => 'EXCLUIR',
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ])->assertOk()
            ->assertJsonPath('data.purged_count', 1)
            ->assertJsonPath('data.failed_count', 0);

        Storage::disk('local')->assertMissing($storageKey);
        $tombstone = $file->fresh();
        $this->assertSame(FileLifecycleStatus::Purged, $tombstone->lifecycle_status);
        $this->assertNotNull($tombstone->purged_at);
        $this->assertDatabaseHas('managed_file_events', [
            'file_id' => $file->id,
            'action' => 'PURGED',
            'actor_id' => $actor->id,
        ]);
        $this->getJson('/api/v1/file-manager/dashboard')
            ->assertOk()
            ->assertJsonPath('data.totals.files', 0)
            ->assertJsonPath('data.totals.bytes', 0);
    }

    public function test_permanent_deletion_refuses_outside_namespace_and_legal_hold(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['listar', 'excluir', 'administrar'],
            'configuracoes' => ['editar'],
        ]);
        $actor = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'atendente']);
        $admin = $this->createUserRecord([
            'grupo_id' => 1,
            'perfil' => 'atendente',
            'email' => 'supervisor.purge-guard@example.com',
        ]);
        $outside = $this->createManagedLogo($actor->id, 'outside-namespace');
        $legalHold = $this->createManagedLogo($actor->id, 'legal-hold');
        $outsideOriginalKey = (string) $outside->storage_key;
        $outside->forceFill([
            'storage_key' => 'outside-allowlist/document.png',
            'lifecycle_status' => FileLifecycleStatus::Trashed,
            'trashed_at' => now()->subDays(90),
        ])->save();
        $legalHold->forceFill([
            'lifecycle_status' => FileLifecycleStatus::Trashed,
            'trashed_at' => now()->subDays(90),
            'metadata_json' => array_merge((array) $legalHold->metadata_json, ['legal_hold' => true]),
        ])->save();

        try {
            $this->app->make(ManagedFilePurgeService::class)->purge(
                $outside,
                (int) $actor->id,
                'Teste direto da contenção do namespace autorizado.',
                (int) $admin->id,
                'test'
            );
            $this->fail('A purga fora do namespace autorizado deveria ser recusada.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('namespaces autorizados', $exception->getMessage());
        }

        Sanctum::actingAs($actor, ['*']);

        $this->postJson('/api/v1/files/purge-batch', [
            'file_uuids' => [$legalHold->uuid],
            'reason' => 'Teste dos limites de contenção e retenção legal.',
            'confirmation' => 'EXCLUIR',
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ])->assertOk()
            ->assertJsonPath('data.purged_count', 0)
            ->assertJsonPath('data.failed_count', 1);

        $this->assertSame(FileLifecycleStatus::Trashed, $outside->fresh()->lifecycle_status);
        $this->assertSame(FileLifecycleStatus::Trashed, $legalHold->fresh()->lifecycle_status);
        Storage::disk('local')->assertExists($outsideOriginalKey);
        Storage::disk('local')->assertExists((string) $legalHold->storage_key);
    }

    public function test_retention_policy_is_step_up_protected_and_scheduled_purge_respects_cutoff(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['administrar'],
            'configuracoes' => ['editar'],
        ]);
        $actor = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'atendente']);
        $admin = $this->createUserRecord([
            'grupo_id' => 1,
            'perfil' => 'atendente',
            'email' => 'supervisor.retention@example.com',
        ]);
        $expired = $this->createManagedLogo($actor->id, 'retention-expired');
        $recent = $this->createManagedLogo($actor->id, 'retention-recent');
        $auditRecord = $this->createManagedLogo($actor->id, 'retention-audit-record');
        $expired->forceFill([
            'lifecycle_status' => FileLifecycleStatus::Trashed,
            'trashed_at' => now()->subDays(8),
        ])->save();
        $recent->forceFill([
            'lifecycle_status' => FileLifecycleStatus::Trashed,
            'trashed_at' => now()->subDays(6),
        ])->save();
        $auditRecord->forceFill([
            'lifecycle_status' => FileLifecycleStatus::Trashed,
            'integrity_status' => FileIntegrityStatus::Missing,
            'trashed_at' => now()->subDays(90),
        ])->save();
        Storage::disk('local')->delete((string) $auditRecord->storage_key);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson('/api/v1/file-manager/trash-retention', [
            'days' => 7,
            'reason' => 'Padronização do prazo operacional da lixeira.',
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ])->assertOk()
            ->assertJsonPath('data.days', 7)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.configured_by', $admin->id);

        $this->artisan('file-manager:purge-trash')->assertSuccessful();

        $this->assertSame(FileLifecycleStatus::Purged, $expired->fresh()->lifecycle_status);
        $this->assertSame(FileLifecycleStatus::Trashed, $recent->fresh()->lifecycle_status);
        $this->assertSame(FileLifecycleStatus::Trashed, $auditRecord->fresh()->lifecycle_status);
        Storage::disk('local')->assertMissing((string) $expired->storage_key);
        Storage::disk('local')->assertExists((string) $recent->storage_key);
        Storage::disk('local')->assertMissing((string) $auditRecord->storage_key);
    }

    public function test_permanent_deletion_kill_switch_blocks_before_step_up(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['excluir', 'administrar'],
            'configuracoes' => ['editar'],
        ]);
        $actor = $this->createUserRecord(['grupo_id' => 1]);
        $file = $this->createManagedLogo($actor->id, 'purge-disabled');
        $file->forceFill([
            'lifecycle_status' => FileLifecycleStatus::Trashed,
            'trashed_at' => now()->subDays(100),
        ])->save();
        config()->set('file-manager.kill_switches.allow_permanent_deletion', false);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson('/api/v1/files/purge-batch', [
            'file_uuids' => [$file->uuid],
            'reason' => 'Tentativa bloqueada pelo controle operacional.',
            'confirmation' => 'EXCLUIR',
            'admin_email' => 'nobody@example.com',
            'admin_password' => 'qualquer-coisa',
        ])->assertStatus(409)->assertJsonPath('error.code', 'FILE_PERMANENT_DELETION_DISABLED');

        Storage::disk('local')->assertExists((string) $file->storage_key);
        $this->assertSame(FileLifecycleStatus::Trashed, $file->fresh()->lifecycle_status);
    }

    public function test_batch_trash_rejects_legacy_profile_without_file_admin_permission_even_if_logging_fails(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['excluir', 'administrar'],
            'configuracoes' => ['editar'],
        ]);
        $actor = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'atendente']);
        $unauthorizedStepUp = $this->createUserRecord([
            'grupo_id' => 3,
            'perfil' => 'admin',
            'email' => 'legacy-profile-without-file-admin@example.com',
        ]);
        $file = $this->createManagedLogo($actor->id, 'denied-trash');
        Sanctum::actingAs($actor, ['*']);

        Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException('log unavailable'));

        $this->postJson('/api/v1/files/trash-batch', [
            'file_uuids' => [$file->uuid],
            'reason' => 'Tentativa sem autorização administrativa efetiva.',
            'admin_email' => $unauthorizedStepUp->email,
            'admin_password' => 'Senha@123',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'FILE_ADMIN_AUTH_INVALID');

        $this->assertSame('active', $file->fresh()->lifecycle_status->value);
    }

    public function test_sensitive_state_actions_require_admin_step_up_reason_and_audit_both_actors(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['metadados', 'administrar', 'restaurar', 'quarentenar'],
            'configuracoes' => ['visualizar', 'editar'],
        ]);
        $actor = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'atendente']);
        $admin = $this->createUserRecord([
            'grupo_id' => 1,
            'perfil' => 'atendente',
            'email' => 'supervisor.arquivos@example.com',
        ]);
        $file = $this->createManagedLogo($actor->id);
        Sanctum::actingAs($actor, ['*']);

        $invalid = $this->postJson('/api/v1/files/'.$file->uuid.'/archive', [
            'reason' => 'Solicitação operacional aprovada.',
            'admin_email' => $admin->email,
            'admin_password' => 'senha-incorreta',
        ]);
        $invalid->assertStatus(422)->assertJsonPath('error.code', 'FILE_ADMIN_AUTH_INVALID');
        $this->assertSame('active', $file->fresh()->lifecycle_status->value);

        $this->postAction($file, 'archive', $admin)->assertOk()
            ->assertJsonPath('data.lifecycle_status', 'archived');
        $this->postAction($file, 'restore', $admin)->assertOk()
            ->assertJsonPath('data.lifecycle_status', 'active');
        $this->postAction($file, 'quarantine', $admin)->assertOk()
            ->assertJsonPath('data.security_status', 'quarantined');
        $this->postAction($file, 'release-quarantine', $admin, [
            'validation_reference' => 'SCAN-2026-0001',
        ])->assertOk()->assertJsonPath('data.security_status', 'clean');

        $events = ManagedFileEvent::query()
            ->where('file_id', $file->id)
            ->whereIn('action', ['ARCHIVED', 'RESTORED', 'QUARANTINED', 'RELEASED_FROM_QUARANTINE'])
            ->get();
        $this->assertCount(4, $events);
        foreach ($events as $event) {
            $this->assertSame($actor->id, $event->actor_id);
            $this->assertSame($admin->id, $event->context_json['authorized_by'] ?? null);
            $this->assertSame('Solicitação operacional aprovada.', $event->context_json['reason'] ?? null);
        }
    }

    public function test_state_mutation_kill_switch_blocks_before_credentials_are_checked(): void
    {
        $this->grantGroupPermissions(1, [
            'arquivos' => ['administrar'],
            'configuracoes' => ['editar'],
        ]);
        $actor = $this->createUserRecord(['grupo_id' => 1]);
        $file = $this->createManagedLogo($actor->id);
        config()->set('file-manager.kill_switches.allow_admin_state_mutations', false);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson('/api/v1/files/'.$file->uuid.'/archive', [
            'reason' => 'Solicitação operacional aprovada.',
            'admin_email' => 'nobody@example.com',
            'admin_password' => 'qualquer-coisa',
        ])->assertStatus(409)->assertJsonPath('error.code', 'FILE_STATE_MUTATIONS_DISABLED');
    }

    public function test_administrator_can_queue_one_deduplicated_manual_synchronization(): void
    {
        config()->set('file-manager.kill_switches.allow_scanner', true);
        config()->set('file-manager.kill_switches.allow_mutating_reconcile', true);
        config()->set('file-manager.automatic_sync.enabled', true);
        config()->set('file-manager.automatic_sync.roots', ['equipment_photos']);
        $this->grantGroupPermissions(1, ['arquivos' => ['administrar']]);
        $actor = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson('/api/v1/file-manager/sync')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.queued', true);
        $this->postJson('/api/v1/file-manager/sync')
            ->assertOk()
            ->assertJsonPath('data.status', 'already_queued')
            ->assertJsonPath('data.queued', false);

        $denied = $this->createUserRecord(['grupo_id' => 3]);
        Sanctum::actingAs($denied, ['*']);
        $this->postJson('/api/v1/file-manager/sync')->assertForbidden();
    }

    private function seedFileManagerRbac(): void
    {
        DB::table('modulos')->insert([
            'id' => 20,
            'nome' => 'Arquivos',
            'slug' => 'arquivos',
            'icone' => 'bi-folder2-open',
            'ordem_menu' => 78,
            'ativo' => 1,
        ]);
        foreach (['listar', 'metadados', 'baixar', 'quarentenar', 'restaurar', 'administrar'] as $index => $slug) {
            DB::table('permissoes')->insert([
                'id' => 20 + $index,
                'nome' => ucfirst($slug),
                'slug' => $slug,
            ]);
        }
        Cache::flush();
    }

    private function createManagedLogo(int $actorId, string $operationSuffix = 'default'): ManagedFile
    {
        $upload = UploadedFile::fake()->image('logo.png', 32, 32);

        return app(FileManagerFacade::class)->store(
            new FileDescriptor($upload->getPathname(), 'logo.png', 'image/png'),
            new FileContext(
                category: FileCategory::CompanyLogo,
                origin: FileOrigin::Upload,
                operationKey: 'file-manager-api-logo:'.$actorId.':'.$operationSuffix,
                subjectType: 'configuration',
                subjectId: 1,
                relation: 'company_logo',
                createdBy: $actorId
            )
        );
    }

    /** @param array<string, string> $extra */
    private function postAction(ManagedFile $file, string $action, $admin, array $extra = [])
    {
        return $this->postJson('/api/v1/files/'.$file->uuid.'/'.$action, array_merge([
            'reason' => 'Solicitação operacional aprovada.',
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ], $extra));
    }
}
