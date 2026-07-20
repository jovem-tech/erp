<?php

namespace Tests\Feature\Files;

use App\Models\Files\FileScanFinding;
use App\Models\Files\FileScanRun;
use App\Models\Files\ManagedFile;
use App\Models\Files\ManagedFileLink;
use App\Services\Files\AutomaticFileSyncService;
use App\Services\Files\FileManagerConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class AutomaticFileSyncTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        Storage::fake('local');
        Storage::fake('legacy_public');
        config()->set('cache.default', 'array');
        config()->set('file-manager.mode', 'shadow');
        config()->set('file-manager.enabled_categories', ['equipment_photo']);
        config()->set('file-manager.kill_switches.allow_writes', false);
        config()->set('file-manager.kill_switches.allow_scanner', true);
        config()->set('file-manager.kill_switches.allow_mutating_reconcile', true);
        config()->set('file-manager.automatic_sync.enabled', true);
        config()->set('file-manager.automatic_sync.roots', ['equipment_photos']);
        config()->set('file-manager.automatic_sync.scan_limit_per_root', 100);
        config()->set('file-manager.automatic_sync.catalog_limit_per_root', 100);
        config()->set('file-manager.automatic_sync.max_depth', 8);
        config()->set('file-manager.automatic_sync.lock_seconds', 60);
    }

    public function test_it_catalogs_new_files_idempotently_without_moving_the_source(): void
    {
        $path = 'private/equipamentos/novo-equipamento.png';
        $image = UploadedFile::fake()->image('novo-equipamento.png', 80, 60);
        Storage::disk('local')->put($path, $image->getContent());
        $hashBefore = hash_file('sha256', Storage::disk('local')->path($path));

        $first = app(AutomaticFileSyncService::class)->synchronize();
        $second = app(AutomaticFileSyncService::class)->synchronize();

        $this->assertSame('completed', $first['status']);
        $this->assertSame(1, $first['cataloged']);
        $this->assertSame('completed', $second['status']);
        $this->assertSame(0, $second['cataloged']);
        $this->assertSame(1, ManagedFile::query()->count());
        $this->assertSame($path, ManagedFile::query()->sole()->storage_key);
        $this->assertSame($hashBefore, hash_file('sha256', Storage::disk('local')->path($path)));
        Storage::disk('local')->assertExists($path);
        $this->assertSame(2, FileScanRun::query()->where('process_name', 'automatic_sync')->count());
    }

    public function test_it_discovers_order_photos_in_the_private_order_namespace(): void
    {
        config()->set('file-manager.enabled_categories', ['order_photo']);
        config()->set('file-manager.automatic_sync.roots', ['order_photos']);
        $clientId = $this->createClientRecord();
        $equipmentId = $this->createEquipmentRecord($clientId);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
        ]);
        $path = 'private/os/'.$orderId.'/recepcao.jpg';
        $image = UploadedFile::fake()->image('recepcao.jpg', 80, 60);
        Storage::disk('local')->put($path, $image->getContent());
        $photoId = DB::table('os_fotos')->insertGetId([
            'os_id' => $orderId,
            'tipo' => 'recepcao',
            'arquivo' => $path,
            'created_at' => '2026-07-18 10:15:00',
        ]);

        $result = app(AutomaticFileSyncService::class)->synchronize();

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $result['cataloged']);
        $this->assertDatabaseHas('managed_files', [
            'category' => 'order_photo',
            'storage_disk' => 'local',
            'storage_key' => $path,
        ]);
        $file = ManagedFile::query()->where('storage_key', $path)->sole();
        $link = ManagedFileLink::query()->where('file_id', $file->id)->sole();
        $this->assertSame('order', $link->subject_type);
        $this->assertSame($orderId, $link->subject_id);
        $this->assertSame('photo:'.$photoId, $link->relation);
        $this->assertSame('2026-07-18T10:15:00-03:00', $link->metadata_json['source_created_at']);
        $this->assertSame(1, $result['domain_links']['linked_files']);
        Storage::disk('local')->assertExists($path);
    }

    public function test_it_does_not_link_one_file_to_two_different_orders(): void
    {
        config()->set('file-manager.enabled_categories', ['order_photo']);
        config()->set('file-manager.automatic_sync.roots', ['order_photos']);
        $clientId = $this->createClientRecord();
        $equipmentId = $this->createEquipmentRecord($clientId);
        $firstOrderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
        ]);
        $secondOrderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
        ]);
        $path = 'private/os/'.$firstOrderId.'/arquivo-reutilizado.jpg';
        Storage::disk('local')->put($path, UploadedFile::fake()->image('arquivo-reutilizado.jpg', 80, 60)->getContent());

        foreach ([$firstOrderId, $secondOrderId] as $orderId) {
            DB::table('os_fotos')->insert([
                'os_id' => $orderId,
                'tipo' => 'recepcao',
                'arquivo' => $path,
                'created_at' => now(),
            ]);
        }

        $result = app(AutomaticFileSyncService::class)->synchronize();
        $file = ManagedFile::query()->where('storage_key', $path)->sole();

        $this->assertSame(1, $result['domain_links']['ambiguous_files']);
        $this->assertSame(0, ManagedFileLink::query()->where('file_id', $file->id)->count());
    }

    public function test_it_does_not_start_a_second_sync_while_the_global_lock_is_held(): void
    {
        $lock = Cache::lock('file-manager:automatic-sync', 60);
        $this->assertTrue($lock->get());

        try {
            $result = app(AutomaticFileSyncService::class)->synchronize();
        } finally {
            $lock->release();
        }

        $this->assertSame('locked', $result['status']);
        $this->assertSame(0, FileScanRun::query()->where('process_name', 'automatic_sync')->count());
    }

    public function test_policy_rejection_is_acknowledged_and_not_reprocessed_until_the_file_changes(): void
    {
        $path = 'private/equipamentos/arquivo-invalido.php';
        Storage::disk('local')->put($path, '<?php echo "nao permitido";');

        $first = app(AutomaticFileSyncService::class)->synchronize();
        $second = app(AutomaticFileSyncService::class)->synchronize();

        $this->assertSame(1, $first['skipped']);
        $this->assertSame(0, $second['skipped']);
        $this->assertSame(0, ManagedFile::query()->count());
        $this->assertSame(1, FileScanFinding::query()->where('finding_type', 'orphan')->count());
        $this->assertSame('acknowledged', FileScanFinding::query()->sole()->resolution_status);

        Storage::disk('local')->put($path, '<?php echo "conteudo alterado e ainda nao permitido";');
        $third = app(AutomaticFileSyncService::class)->synchronize();

        $this->assertSame(1, $third['skipped']);
        $this->assertSame(2, FileScanFinding::query()->where('finding_type', 'orphan')->count());
    }

    public function test_configuration_rejects_automatic_sync_outside_shadow_or_hybrid_mode(): void
    {
        config()->set('file-manager.mode', 'off');

        $errors = app(FileManagerConfiguration::class)->validate();

        $this->assertContains('automatic_sync_requires_shadow_or_hybrid_mode', $errors);
    }

    public function test_manual_request_is_deduplicated_and_processed_with_actor_audit(): void
    {
        $service = app(AutomaticFileSyncService::class);

        $firstRequest = $service->requestManualSynchronization(42);
        $duplicateRequest = $service->requestManualSynchronization(42);
        $result = $service->synchronizePendingRequest();
        $run = FileScanRun::query()->where('process_name', 'manual_sync')->sole();

        $this->assertTrue($firstRequest['queued']);
        $this->assertFalse($duplicateRequest['queued']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(42, $run->started_by);
        $this->assertSame('completed', $run->status);
        $this->assertSame('no_request', $service->synchronizePendingRequest()['status']);
    }
}
