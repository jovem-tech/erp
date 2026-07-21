<?php

namespace Tests\Feature\Files;

use App\Contracts\Files\FileCatalog;
use App\Contracts\Files\FileStorage;
use App\DTO\Files\FileContext;
use App\DTO\Files\FileDescriptor;
use App\Enums\Files\FileCategory;
use App\Enums\Files\FileIntegrityStatus;
use App\Enums\Files\FileLifecycleStatus;
use App\Enums\Files\FileOrigin;
use App\Enums\Files\ManagedFileAction;
use App\Models\Files\FileScanFinding;
use App\Models\Files\ManagedFile;
use App\Models\Files\ManagedFileEvent;
use App\Models\Files\ManagedFileLink;
use App\Models\OrderDocument;
use App\Models\OrderDocumentFile;
use App\Services\Files\Authorizers\UserProfilePhotoFileAuthorizer;
use App\Services\Files\Authorizers\UserSignatureFileAuthorizer;
use App\Services\Files\FileAuthorizationRegistry;
use App\Services\Files\FileManagerConfiguration;
use App\Services\Files\FileManagerFacade;
use App\Services\Files\FilePolicyRegistry;
use App\Services\Files\FileReconciliationService;
use App\Services\Files\FileScanService;
use App\Services\Files\FileStateMachine;
use App\Services\Files\LegacyCompatibleFileAdapter;
use App\Services\Files\LegacyFileObservationService;
use App\Services\Files\LegacyFileResolver;
use App\Services\Files\ManagedFileEventRecorder;
use App\Services\Profile\ProfilePhotoImageService;
use App\Services\Signatures\SignatureImageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class FileManagerCoreTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        Storage::fake('local');
        config()->set('cache.default', 'array');
        config()->set('file-manager.mode', 'hybrid');
        config()->set('file-manager.enabled_categories', ['company_logo']);
        config()->set('file-manager.kill_switches.allow_writes', true);
        config()->set('file-manager.kill_switches.allow_scanner', true);
    }

    public function test_stores_immutable_file_with_hash_link_and_events(): void
    {
        [$upload, $descriptor] = $this->logoDescriptor();
        $context = $this->context('logo:create:1');

        $file = app(FileManagerFacade::class)->store($descriptor, $context);

        $this->assertSame('company_logo', $file->category);
        $this->assertSame(hash_file('sha256', $upload->getPathname()), $file->sha256);
        Storage::disk('local')->assertExists($file->storage_key);
        $this->assertStringStartsWith('managed-files/company_logo/', $file->storage_key);
        $this->assertSame(1, ManagedFileLink::query()->where('file_id', $file->id)->where('is_current', true)->count());
        $this->assertSame(2, $file->events()->count());
    }

    public function test_retry_with_same_operation_key_is_idempotent(): void
    {
        [$upload, $descriptor] = $this->logoDescriptor();
        $context = $this->context('logo:idempotent:1');
        $facade = app(FileManagerFacade::class);

        $first = $facade->store($descriptor, $context);
        $second = $facade->store($descriptor, $context);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ManagedFile::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('managed-files'));
    }

    public function test_replacement_changes_current_link_without_overwriting_old_blob(): void
    {
        [$firstUpload, $firstDescriptor] = $this->logoDescriptor('primeira.png', 20, 20);
        [$secondUpload, $secondDescriptor] = $this->logoDescriptor('segunda.png', 30, 30);
        $facade = app(FileManagerFacade::class);

        $first = $facade->store($firstDescriptor, $this->context('logo:replace:1'));
        $second = $facade->store($secondDescriptor, $this->context('logo:replace:2'));

        $this->assertNotSame($first->storage_key, $second->storage_key);
        Storage::disk('local')->assertExists($first->storage_key);
        Storage::disk('local')->assertExists($second->storage_key);
        $this->assertFalse((bool) $first->links()->firstOrFail()->fresh()->is_current);
        $this->assertTrue((bool) $second->links()->firstOrFail()->is_current);
    }

    public function test_spoofed_content_is_rejected_without_catalog_or_blob(): void
    {
        $upload = UploadedFile::fake()->createWithContent('logo.png', '<html>not an image</html>');
        $descriptor = new FileDescriptor($upload->getPathname(), 'logo.png', 'image/png');

        try {
            app(FileManagerFacade::class)->store($descriptor, $this->context('logo:spoofed:1'));
            $this->fail('Conteudo disfarçado deveria ser rejeitado.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, ManagedFile::query()->count());
            $this->assertCount(0, Storage::disk('local')->allFiles('managed-files'));
        }
    }

    public function test_observe_mode_cannot_write(): void
    {
        config()->set('file-manager.mode', 'observe');
        [$upload, $descriptor] = $this->logoDescriptor();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Escrita central desabilitada');

        app(FileManagerFacade::class)->store($descriptor, $this->context('logo:observe:1'));
    }

    public function test_hybrid_rejects_category_without_completed_rollout_gate(): void
    {
        config()->set('file-manager.enabled_categories', ['equipment_photo']);

        $this->assertContains(
            'category_not_approved_for_hybrid:equipment_photo',
            app(FileManagerConfiguration::class)->validate()
        );
    }

    public function test_user_signature_is_cataloged_in_shadow_without_changing_legacy_path(): void
    {
        config()->set('file-manager.mode', 'shadow');
        config()->set('file-manager.enabled_categories', ['user_signature']);
        $user = $this->createUserRecord(['grupo_id' => null]);

        $result = app(SignatureImageService::class)->enroll(
            $user,
            UploadedFile::fake()->image('assinatura.png', 120, 60),
            'upload',
            $user,
            '127.0.0.1'
        );

        $signature = $result['signature'];
        $managed = ManagedFile::query()->where('category', 'user_signature')->firstOrFail();

        $this->assertSame((string) $signature->arquivo, (string) $managed->storage_key);
        $this->assertSame('cataloged', $managed->migration_status->value);
        $this->assertDatabaseHas('managed_file_links', [
            'file_id' => $managed->id,
            'subject_type' => 'user_signature',
            'subject_id' => $signature->id,
            'relation' => 'signature_image',
        ]);
        $this->assertDatabaseHas('managed_file_legacy_aliases', [
            'file_id' => $managed->id,
            'legacy_disk' => 'local',
            'source_table' => 'usuario_assinaturas',
            'source_record_id' => (string) $signature->id,
        ]);
        Storage::disk('local')->assertExists((string) $signature->arquivo);

        DB::table('grupos')->insert([
            'id' => 1,
            'nome' => 'Administrador',
            'descricao' => 'Grupo administrativo',
            'sistema' => 1,
            'created_at' => now(),
        ]);
        DB::table('modulos')->insert([
            'id' => 20,
            'nome' => 'Arquivos',
            'slug' => 'arquivos',
            'icone' => 'bi-folder2-open',
            'ordem_menu' => 78,
            'ativo' => 1,
        ]);
        DB::table('permissoes')->insert([
            'id' => 20,
            'nome' => 'Administrar',
            'slug' => 'administrar',
        ]);
        DB::table('grupo_permissoes')->insert([
            'grupo_id' => 1,
            'modulo_id' => 20,
            'permissao_id' => 20,
        ]);
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $authorizer = app(UserSignatureFileAuthorizer::class);

        $this->assertFalse($authorizer->allows($user, $managed, 'archive'));
        $this->assertTrue($authorizer->allows($admin, $managed, 'archive'));
    }

    public function test_user_profile_photo_is_cataloged_in_shadow_and_linked_to_owner(): void
    {
        config()->set('file-manager.mode', 'shadow');
        config()->set('file-manager.enabled_categories', ['user_profile_photo']);
        $user = $this->createUserRecord(['grupo_id' => null]);

        $path = app(ProfilePhotoImageService::class)->update(
            $user,
            UploadedFile::fake()->image('foto.jpg', 400, 400),
            $user
        );

        $managed = ManagedFile::query()->where('category', 'user_profile_photo')->firstOrFail();

        $this->assertSame($path, (string) $managed->storage_key);
        $this->assertSame('cataloged', $managed->migration_status->value);
        $this->assertMatchesRegularExpression('/^foto-perfil-[A-Za-z0-9]{10}\.jpg$/', (string) $managed->original_name);
        $this->assertNotEmpty(
            app(\App\Services\Files\ManagedFileDeliveryService::class)->open($managed, true)
        );
        $this->assertDatabaseHas('managed_file_links', [
            'file_id' => $managed->id,
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'relation' => 'profile_photo',
        ]);
        $this->assertDatabaseHas('managed_file_legacy_aliases', [
            'file_id' => $managed->id,
            'legacy_disk' => 'local',
            'source_table' => 'usuarios',
            'source_record_id' => (string) $user->id,
        ]);
        Storage::disk('local')->assertExists($path);

        DB::table('grupos')->insert([
            'id' => 1,
            'nome' => 'Administrador',
            'descricao' => 'Grupo administrativo',
            'sistema' => 1,
            'created_at' => now(),
        ]);
        DB::table('modulos')->insert([
            'id' => 20,
            'nome' => 'Arquivos',
            'slug' => 'arquivos',
            'icone' => 'bi-folder2-open',
            'ordem_menu' => 78,
            'ativo' => 1,
        ]);
        DB::table('permissoes')->insert([
            'id' => 20,
            'nome' => 'Administrar',
            'slug' => 'administrar',
        ]);
        DB::table('grupo_permissoes')->insert([
            'grupo_id' => 1,
            'modulo_id' => 20,
            'permissao_id' => 20,
        ]);
        $otherUser = $this->createUserRecord(['grupo_id' => null]);
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $authorizer = app(UserProfilePhotoFileAuthorizer::class);

        $this->assertTrue($authorizer->allows($user, $managed, 'download'));
        $this->assertFalse($authorizer->allows($otherUser, $managed, 'download'));
        $this->assertTrue($authorizer->allows($admin, $managed, 'download'));
        $this->assertFalse($authorizer->allows($otherUser, $managed, 'archive'));
        $this->assertTrue($authorizer->allows($admin, $managed, 'archive'));
    }

    public function test_replacing_profile_photo_trashes_the_superseded_catalog_entry(): void
    {
        config()->set('file-manager.mode', 'shadow');
        config()->set('file-manager.enabled_categories', ['user_profile_photo']);
        $user = $this->createUserRecord(['grupo_id' => null]);
        $service = app(ProfilePhotoImageService::class);

        $firstPath = $service->update($user, UploadedFile::fake()->image('primeira.jpg', 400, 400), $user);
        $secondPath = $service->update($user->fresh(), UploadedFile::fake()->image('segunda.jpg', 400, 400), $user);

        $first = ManagedFile::query()->where('storage_key', $firstPath)->firstOrFail();
        $second = ManagedFile::query()->where('storage_key', $secondPath)->firstOrFail();

        $this->assertSame('trashed', $first->lifecycle_status->value);
        $this->assertSame('active', $second->lifecycle_status->value);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);
    }

    public function test_removing_profile_photo_trashes_the_catalog_entry(): void
    {
        config()->set('file-manager.mode', 'shadow');
        config()->set('file-manager.enabled_categories', ['user_profile_photo']);
        $user = $this->createUserRecord(['grupo_id' => null]);
        $service = app(ProfilePhotoImageService::class);

        $path = $service->update($user, UploadedFile::fake()->image('foto.jpg', 400, 400), $user);
        $service->remove($user->fresh(), $user);

        $managed = ManagedFile::query()->where('storage_key', $path)->firstOrFail();
        $this->assertSame('trashed', $managed->lifecycle_status->value);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_catalog_failure_compensates_promoted_blob(): void
    {
        [$upload, $descriptor] = $this->logoDescriptor();
        $catalog = \Mockery::mock(FileCatalog::class);
        $catalog->shouldReceive('findByOperationKey')->times(3)->andReturnNull();
        $catalog->shouldReceive('register')->once()->andThrow(new \RuntimeException('database unavailable'));
        $facade = new FileManagerFacade(
            app(FileManagerConfiguration::class),
            app(FilePolicyRegistry::class),
            app(FileStorage::class),
            $catalog
        );

        try {
            $facade->store($descriptor, $this->context('logo:db-failure:1'));
            $this->fail('Falha de catalogo deveria ser propagada.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('database unavailable', $exception->getMessage());
            $this->assertCount(0, Storage::disk('local')->allFiles('managed-files'));
            $this->assertCount(0, Storage::disk('local')->allFiles('managed-files-staging'));
        }
    }

    public function test_ambiguous_catalog_failure_preserves_blob_for_reconciliation(): void
    {
        [$upload, $descriptor] = $this->logoDescriptor();
        $catalog = \Mockery::mock(FileCatalog::class);
        $catalog->shouldReceive('findByOperationKey')->twice()->andReturnNull()->ordered();
        $catalog->shouldReceive('register')->once()->andThrow(new \RuntimeException('commit result unknown'))->ordered();
        $catalog->shouldReceive('findByOperationKey')->once()->andThrow(new \RuntimeException('database still unavailable'))->ordered();
        $facade = new FileManagerFacade(
            app(FileManagerConfiguration::class),
            app(FilePolicyRegistry::class),
            app(FileStorage::class),
            $catalog
        );

        try {
            $facade->store($descriptor, $this->context('logo:ambiguous-db-failure:1'));
            $this->fail('Falha ambigua deveria ser propagada.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('commit result unknown', $exception->getMessage());
            $this->assertCount(1, Storage::disk('local')->allFiles('managed-files'));
            $this->assertCount(0, Storage::disk('local')->allFiles('managed-files-staging'));
        }
    }

    public function test_state_machine_rejects_duplicate_archive_transition(): void
    {
        [$upload, $descriptor] = $this->logoDescriptor();
        $file = app(FileManagerFacade::class)->store($descriptor, $this->context('logo:state:1'));
        $archived = app(FileStateMachine::class)->archive($file);

        $this->assertSame(FileLifecycleStatus::Archived, $archived->lifecycle_status);
        $this->expectException(\DomainException::class);
        app(FileStateMachine::class)->archive($archived);
    }

    public function test_scanner_dry_run_records_orphan_without_modifying_file(): void
    {
        Storage::disk('local')->put('managed-files/orphan.txt', 'conteudo imutavel');
        Storage::disk('local')->put('outside-scan.txt', 'nao seguir');
        $absolutePath = Storage::disk('local')->path('managed-files/orphan.txt');
        $linkPath = Storage::disk('local')->path('managed-files/outside-link.txt');
        $this->assertTrue(symlink(Storage::disk('local')->path('outside-scan.txt'), $linkPath));
        $hashBefore = hash_file('sha256', $absolutePath);
        $mtimeBefore = filemtime($absolutePath);

        $run = app(FileScanService::class)->scan('managed', 100, 4);

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, FileScanFinding::query()->where('finding_type', 'orphan')->count());
        $this->assertSame(1, FileScanFinding::query()->where('finding_type', 'symlink')->count());
        $this->assertSame($hashBefore, hash_file('sha256', $absolutePath));
        $this->assertSame($mtimeBefore, filemtime($absolutePath));
    }

    public function test_scanner_refuses_unconfigured_root(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(FileScanService::class)->scan('etc');
    }

    public function test_scanner_reports_broken_legacy_alias_without_modifying_catalog(): void
    {
        [$upload, $descriptor] = $this->logoDescriptor();
        $file = app(FileManagerFacade::class)->store($descriptor, $this->context('logo:broken-alias:1'));
        app(LegacyFileResolver::class)->addAlias(
            $file,
            'local',
            'managed-files/company_logo/missing-alias.png'
        );

        app(FileScanService::class)->scan('managed', 100, 8);

        $this->assertSame(1, FileScanFinding::query()->where('finding_type', 'broken_reference')->count());
        $this->assertSame(FileIntegrityStatus::Valid, $file->fresh()->integrity_status);
    }

    public function test_reconciliation_is_dry_run_by_default_and_apply_is_kill_switched(): void
    {
        [$upload, $descriptor] = $this->logoDescriptor();
        $file = app(FileManagerFacade::class)->store($descriptor, $this->context('logo:reconcile:1'));
        Storage::disk('local')->delete($file->storage_key);
        $service = app(FileReconciliationService::class);

        $dryRun = $service->reconcileMissingBlobs();
        $this->assertSame(1, $dryRun['missing']);
        $this->assertSame(FileIntegrityStatus::Valid, $file->fresh()->integrity_status);

        $this->expectException(\RuntimeException::class);
        $service->reconcileMissingBlobs(true);
    }

    public function test_mutating_reconciliation_marks_missing_when_explicitly_enabled(): void
    {
        [$upload, $descriptor] = $this->logoDescriptor();
        $file = app(FileManagerFacade::class)->store($descriptor, $this->context('logo:reconcile:2'));
        Storage::disk('local')->delete($file->storage_key);
        config()->set('file-manager.kill_switches.allow_mutating_reconcile', true);

        $result = app(FileReconciliationService::class)->reconcileMissingBlobs(true);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(FileIntegrityStatus::Missing, $file->fresh()->integrity_status);
    }

    public function test_reconciliation_detects_and_repairs_duplicate_current_links_only_when_enabled(): void
    {
        [$firstUpload, $firstDescriptor] = $this->logoDescriptor('link-1.png', 20, 20);
        [$secondUpload, $secondDescriptor] = $this->logoDescriptor('link-2.png', 30, 30);
        $facade = app(FileManagerFacade::class);
        $first = $facade->store($firstDescriptor, $this->context('logo:link-conflict:1'));
        $facade->store($secondDescriptor, $this->context('logo:link-conflict:2'));
        $first->links()->update(['is_current' => true, 'unlinked_at' => null]);

        $dryRun = app(FileReconciliationService::class)->reconcile(false);
        $this->assertSame(1, $dryRun['link_conflicts']);
        $this->assertSame(0, $dryRun['links_updated']);
        $this->assertSame(2, ManagedFileLink::query()->where('is_current', true)->count());

        config()->set('file-manager.kill_switches.allow_mutating_reconcile', true);
        $applied = app(FileReconciliationService::class)->reconcile(true);
        $this->assertSame(1, $applied['link_conflicts']);
        $this->assertSame(1, $applied['links_updated']);
        $this->assertSame(1, ManagedFileLink::query()->where('is_current', true)->count());
    }

    public function test_diagnose_command_reports_ready_after_migrations(): void
    {
        config()->set('file-manager.mode', 'off');

        $this->artisan('file-manager:diagnose', ['--json' => true])
            ->expectsOutputToContain('"ok":true')
            ->assertSuccessful();
    }

    public function test_local_provider_works_on_real_linux_filesystem(): void
    {
        $root = storage_path('framework/testing/file-manager-real-'.Str::uuid()->toString());
        File::ensureDirectoryExists($root);
        config()->set('filesystems.disks.file_manager_real_test', [
            'driver' => 'local',
            'root' => $root,
            'throw' => true,
        ]);
        config()->set('file-manager.storage.disk', 'file_manager_real_test');
        config()->set('file-manager.storage.allowed_disks', ['local', 'legacy_public', 'file_manager_real_test']);
        [$upload, $descriptor] = $this->logoDescriptor('linux.png', 16, 16);

        try {
            $file = app(FileManagerFacade::class)->store($descriptor, $this->context('logo:linux-real:1'));
            $absoluteStoredPath = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file->storage_key);

            $this->assertTrue(is_file($absoluteStoredPath));
            $this->assertSame(hash_file('sha256', $upload->getPathname()), $file->sha256);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_observer_records_only_hashed_path_metadata(): void
    {
        config()->set('file-manager.mode', 'observe');
        Storage::disk('local')->put('private/empresa/logo.png', 'legacy-bytes');

        app(LegacyFileObservationService::class)->observeStored(
            FileCategory::CompanyLogo,
            'local',
            'private/empresa/logo.png'
        );

        $event = ManagedFileEvent::query()->where('action', ManagedFileAction::LegacyObserved->value)->firstOrFail();
        $this->assertSame(hash('sha256', "local\0private/empresa/logo.png"), $event->context_json['path_hash']);
        $this->assertStringNotContainsString('private/empresa/logo.png', json_encode($event->context_json));
    }

    public function test_observer_failure_never_interrupts_legacy_flow(): void
    {
        config()->set('file-manager.mode', 'observe');
        Storage::disk('local')->put('private/empresa/logo.png', 'legacy-bytes');
        Log::spy();
        $events = \Mockery::mock(ManagedFileEventRecorder::class);
        $events->shouldReceive('record')->once()->andThrow(new \RuntimeException('database unavailable'));
        $observer = new LegacyFileObservationService(app(FileManagerConfiguration::class), $events);

        $observer->observeStored(FileCategory::CompanyLogo, 'local', 'private/empresa/logo.png');

        Log::shouldHaveReceived('warning')->once();
        $this->assertTrue(Storage::disk('local')->exists('private/empresa/logo.png'));
    }

    public function test_equipment_authorizer_requires_domain_permission_and_existing_subject(): void
    {
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, ['equipamentos' => ['visualizar']]);
        $actor = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'gerente']);
        $clientId = $this->createClientRecord();
        $equipmentId = $this->createEquipmentRecord($clientId);
        config()->set('file-manager.mode', 'shadow');
        config()->set('file-manager.enabled_categories', ['equipment_photo']);
        $path = UploadedFile::fake()->image('equipamento.png')->storeAs(
            'private/equipamentos/'.$equipmentId,
            'equipamento.png',
            'local'
        );
        $this->assertIsString($path);

        $file = app(LegacyCompatibleFileAdapter::class)->synchronizeExisting(
            new FileContext(
                category: FileCategory::EquipmentPhoto,
                origin: FileOrigin::Upload,
                operationKey: 'equipment-authorizer:1',
                subjectType: 'equipment',
                subjectId: $equipmentId,
                relation: 'photo:1'
            ),
            'local',
            $path
        );

        $this->assertInstanceOf(ManagedFile::class, $file);
        $this->assertTrue(app(FileAuthorizationRegistry::class)->allows($actor, $file, 'download'));
        $this->assertFalse(app(FileAuthorizationRegistry::class)->allows($actor, $file, 'archive'));

        DB::table('equipamentos')->where('id', $equipmentId)->delete();
        $this->assertFalse(app(FileAuthorizationRegistry::class)->allows($actor, $file, 'download'));
    }

    public function test_order_authorizer_preserves_technician_assignment_scope(): void
    {
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(2, ['os' => ['visualizar']]);
        $assigned = $this->createUserRecord(['grupo_id' => 2, 'perfil' => 'tecnico']);
        $other = $this->createUserRecord(['grupo_id' => 2, 'perfil' => 'tecnico']);
        $clientId = $this->createClientRecord();
        $equipmentId = $this->createEquipmentRecord($clientId);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'tecnico_id' => $assigned->id,
        ]);
        config()->set('file-manager.mode', 'shadow');
        config()->set('file-manager.enabled_categories', ['order_photo']);
        $path = UploadedFile::fake()->image('ordem.jpg')->storeAs(
            'private/os/'.$orderId,
            'ordem.jpg',
            'local'
        );
        $this->assertIsString($path);

        $file = app(LegacyCompatibleFileAdapter::class)->synchronizeExisting(
            new FileContext(
                category: FileCategory::OrderPhoto,
                origin: FileOrigin::Upload,
                operationKey: 'order-authorizer:1',
                subjectType: 'order',
                subjectId: $orderId,
                relation: 'photo:1'
            ),
            'local',
            $path
        );

        $this->assertInstanceOf(ManagedFile::class, $file);
        $registry = app(FileAuthorizationRegistry::class);
        $this->assertTrue($registry->allows($assigned, $file, 'download'));
        $this->assertFalse($registry->allows($other, $file, 'download'));
        $this->assertFalse($registry->allows($assigned, $file, 'quarantine'));
    }

    public function test_order_document_observer_catalogs_compatible_pdf_and_preserves_hash_divergence(): void
    {
        Schema::table('os_documento_arquivos', function (Blueprint $table): void {
            $table->uuid('managed_file_uuid')->nullable();
        });
        $clientId = $this->createClientRecord();
        $equipmentId = $this->createEquipmentRecord($clientId);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
        ]);
        config()->set('file-manager.mode', 'shadow');
        config()->set('file-manager.enabled_categories', ['order_pdf']);
        $path = 'private/os/'.$orderId.'/documentos/abertura-a4.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\nconteudo-controlado\n%%EOF");
        $document = OrderDocument::query()->create([
            'os_id' => $orderId,
            'tipo_documento' => 'abertura',
            'arquivo' => $path,
            'versao' => 1,
        ]);

        $documentFile = OrderDocumentFile::query()->create([
            'documento_id' => $document->id,
            'formato' => 'a4',
            'arquivo' => $path,
            'mime' => 'application/pdf',
            'tamanho_bytes' => Storage::disk('local')->size($path),
            'hash_sha256' => str_repeat('0', 64),
        ])->fresh();

        $managed = ManagedFile::query()->where('category', FileCategory::OrderPdf->value)->firstOrFail();
        $this->assertSame($managed->uuid, $documentFile?->managed_file_uuid);
        $this->assertSame(FileIntegrityStatus::Corrupted, $managed->integrity_status);
        $this->assertSame(str_repeat('0', 64), $documentFile?->hash_sha256);
        $this->assertSame('cataloged', $managed->migration_status->value);
        Storage::disk('local')->assertExists($path);
    }

    /**
     * @return array{0: UploadedFile, 1: FileDescriptor}
     */
    private function logoDescriptor(string $name = 'logo.png', int $width = 40, int $height = 40): array
    {
        $upload = UploadedFile::fake()->image($name, $width, $height);

        return [
            $upload,
            new FileDescriptor($upload->getPathname(), $name, 'image/png'),
        ];
    }

    private function context(string $operationKey): FileContext
    {
        return new FileContext(
            category: FileCategory::CompanyLogo,
            origin: FileOrigin::Upload,
            operationKey: $operationKey,
            subjectType: 'configuration',
            subjectId: 1,
            relation: 'company_logo'
        );
    }
}
