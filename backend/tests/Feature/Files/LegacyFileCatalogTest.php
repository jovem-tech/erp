<?php

namespace Tests\Feature\Files;

use App\Models\Files\FileScanFinding;
use App\Models\Files\FileScanRun;
use App\Models\Files\ManagedFile;
use App\Services\Files\LegacyFileCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class LegacyFileCatalogTest extends TestCase
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
        config()->set('file-manager.mode', 'off');
        config()->set('file-manager.kill_switches.allow_scanner', true);
        config()->set('file-manager.kill_switches.allow_mutating_reconcile', false);
    }

    public function test_dry_run_and_apply_catalog_legacy_file_without_moving_it(): void
    {
        $path = 'private/equipamentos/equipamento-1.png';
        $image = UploadedFile::fake()->image('equipamento-1.png', 80, 60);
        Storage::disk('local')->put($path, $image->getContent());
        $hashBefore = hash_file('sha256', Storage::disk('local')->path($path));
        $finding = $this->orphanFinding('equipment_photos', $path);
        $service = app(LegacyFileCatalogService::class);

        $dryRun = $service->catalog(false, 50, 'equipment_photos');

        $this->assertSame(1, $dryRun['candidates']);
        $this->assertSame(0, ManagedFile::query()->count());
        $this->assertSame('open', $finding->fresh()->resolution_status);
        Storage::disk('local')->assertExists($path);

        config()->set('file-manager.kill_switches.allow_mutating_reconcile', true);
        $applied = $service->catalog(true, 50, 'equipment_photos');
        $file = ManagedFile::query()->sole();

        $this->assertSame(1, $applied['cataloged']);
        $this->assertSame('equipment_photo', $file->category);
        $this->assertSame('cataloged', $file->migration_status->value);
        $this->assertSame($path, $file->storage_key);
        $this->assertSame($hashBefore, $file->sha256);
        $this->assertSame($hashBefore, hash_file('sha256', Storage::disk('local')->path($path)));
        $this->assertDatabaseHas('managed_file_legacy_aliases', [
            'file_id' => $file->id,
            'legacy_disk' => 'local',
            'legacy_path' => $path,
        ]);
        $this->assertSame('resolved', $finding->fresh()->resolution_status);
        Storage::disk('local')->assertExists($path);
    }

    public function test_apply_is_idempotent_for_duplicate_open_finding(): void
    {
        $path = 'private/equipamentos/equipamento-2.png';
        $image = UploadedFile::fake()->image('equipamento-2.png', 80, 60);
        Storage::disk('local')->put($path, $image->getContent());
        $this->orphanFinding('equipment_photos', $path);
        config()->set('file-manager.kill_switches.allow_mutating_reconcile', true);
        $service = app(LegacyFileCatalogService::class);

        $service->catalog(true, 50, 'equipment_photos');
        $this->orphanFinding('equipment_photos', $path);
        $second = $service->catalog(true, 50, 'equipment_photos');

        $this->assertSame(1, $second['already_cataloged']);
        $this->assertSame(1, ManagedFile::query()->count());
        $this->assertSame(1, ManagedFile::query()->sole()->legacyAliases()->count());
    }

    public function test_catalogs_allowlisted_legacy_public_root(): void
    {
        $path = 'uploads/equipamentos_perfil/equipamento-3/perfil.jpg';
        $image = UploadedFile::fake()->image('perfil.jpg', 80, 60);
        Storage::disk('legacy_public')->put($path, $image->getContent());
        $this->orphanFinding('legacy_equipment_profiles', $path);
        config()->set('file-manager.kill_switches.allow_mutating_reconcile', true);

        $result = app(LegacyFileCatalogService::class)->catalog(true, 50, 'legacy_equipment_profiles');
        $file = ManagedFile::query()->sole();

        $this->assertSame(1, $result['cataloged']);
        $this->assertSame('legacy_public', $file->storage_disk);
        $this->assertSame('equipment_photo', $file->category);
        $this->assertSame($path, $file->storage_key);
        Storage::disk('legacy_public')->assertExists($path);
    }

    private function orphanFinding(string $rootAlias, string $path): FileScanFinding
    {
        $root = config('file-manager.scanner.roots.'.$rootAlias);
        $run = FileScanRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'process_name' => 'scan',
            'mode' => 'dry_run',
            'roots_fingerprint' => hash('sha256', $root['disk']."\0".$root['path']),
            'status' => 'completed',
            'started_at' => now(),
            'heartbeat_at' => now(),
            'completed_at' => now(),
        ]);

        return FileScanFinding::query()->create([
            'scan_run_id' => $run->id,
            'finding_type' => 'orphan',
            'severity' => 'low',
            'path_hash' => hash('sha256', $path),
            'restricted_path' => $path,
            'resolution_status' => 'open',
        ]);
    }
}
