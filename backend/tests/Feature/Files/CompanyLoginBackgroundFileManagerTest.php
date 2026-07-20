<?php

namespace Tests\Feature\Files;

use App\Contracts\Files\FileCatalog;
use App\Enums\Files\FileCategory;
use App\Models\Files\ManagedFile;
use App\Models\Files\ManagedFileLegacyAlias;
use App\Models\Files\ManagedFileLink;
use App\Services\Files\CompanyFileManagerAdapter;
use App\Services\Files\FileManagerConfiguration;
use App\Services\Files\FileManagerMetrics;
use App\Services\Files\FilePolicyRegistry;
use App\Services\Files\LegacyCompatibleFileAdapter;
use App\Services\Files\LegacyFileObservationService;
use App\Services\Files\LegacyFileResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class CompanyLoginBackgroundFileManagerTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();
        $this->seedOrderNumberConfiguration();
        $this->grantGroupPermissions(1, ['configuracoes' => ['visualizar', 'editar']]);
        Storage::fake('local');
        config()->set('cache.default', 'array');
        config()->set('file-manager.mode', 'hybrid');
        config()->set('file-manager.enabled_categories', ['company_login_background']);
        config()->set('file-manager.kill_switches.allow_writes', true);

        Sanctum::actingAs($this->createUserRecord([
            'email' => 'file-manager-pilot@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]), ['*']);
    }

    public function test_hybrid_pilot_catalogs_legacy_compatible_path_and_survives_mode_off(): void
    {
        $this->patch('/api/v1/configuracoes/empresa', [
            'login_background_image' => UploadedFile::fake()->image('fundo.png', 1200, 800),
        ])->assertOk();

        $configuredPath = $this->configuredBackgroundPath();
        $file = ManagedFile::query()->where('category', FileCategory::CompanyLoginBackground->value)->firstOrFail();

        $this->assertStringStartsWith('private/empresa/login/', $configuredPath);
        $this->assertSame($configuredPath, $file->storage_key);
        Storage::disk('local')->assertExists($configuredPath);
        $this->assertSame(1, ManagedFileLegacyAlias::query()->where('file_id', $file->id)->count());
        $this->assertSame(1, ManagedFileLink::query()->where('file_id', $file->id)->where('is_current', true)->count());

        config()->set('file-manager.mode', 'off');

        $this->get('/api/v1/configuracoes/empresa/login-background-publico')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->assertGreaterThanOrEqual(
            1,
            app(FileManagerMetrics::class)->snapshot(FileCategory::CompanyLoginBackground)['central_read']
        );
    }

    public function test_hybrid_pilot_retains_previous_version_for_rollback(): void
    {
        $this->patch('/api/v1/configuracoes/empresa', [
            'login_background_image' => UploadedFile::fake()->image('primeiro.png', 800, 600),
        ])->assertOk();
        $firstPath = $this->configuredBackgroundPath();

        $this->patch('/api/v1/configuracoes/empresa', [
            'login_background_image' => UploadedFile::fake()->image('segundo.png', 1024, 768),
        ])->assertOk();
        $secondPath = $this->configuredBackgroundPath();

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertExists($firstPath);
        Storage::disk('local')->assertExists($secondPath);
        $this->assertSame(2, ManagedFile::query()->where('category', FileCategory::CompanyLoginBackground->value)->count());
        $this->assertSame(1, ManagedFileLink::query()->where('relation', 'login_background_image')->where('is_current', true)->count());
    }

    public function test_hybrid_catalog_failure_restores_previous_configuration_and_file(): void
    {
        $oldPath = UploadedFile::fake()->image('anterior.jpg', 100, 100)
            ->storeAs('private/empresa/login', 'anterior.jpg', 'local');
        $this->assertIsString($oldPath);
        DB::table('configuracoes')->updateOrInsert(
            ['chave' => 'login_background_image'],
            ['valor' => $oldPath, 'tipo' => 'texto', 'created_at' => now(), 'updated_at' => now()]
        );

        $adapter = \Mockery::mock(CompanyFileManagerAdapter::class);
        $adapter->shouldReceive('resolveCompatiblePath')
            ->andReturnUsing(static fn (FileCategory $category, string $disk, ?string $legacyPath): ?string => $legacyPath);
        $adapter->shouldReceive('synchronize')->once()->andThrow(new \RuntimeException('catalog unavailable'));
        $this->app->instance(CompanyFileManagerAdapter::class, $adapter);

        $this->patch('/api/v1/configuracoes/empresa', [
            'login_background_image' => UploadedFile::fake()->image('novo.png', 200, 200),
        ], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertSame($oldPath, $this->configuredBackgroundPath());
        Storage::disk('local')->assertExists($oldPath);
        $this->assertCount(1, Storage::disk('local')->allFiles('private/empresa/login'));
    }

    public function test_shadow_catalog_failure_is_fail_open(): void
    {
        config()->set('file-manager.mode', 'shadow');
        config()->set('file-manager.kill_switches.allow_writes', false);
        $path = UploadedFile::fake()->image('shadow.png', 100, 100)
            ->storeAs('private/empresa/login', 'shadow.png', 'local');
        $this->assertIsString($path);
        $catalog = \Mockery::mock(FileCatalog::class);
        $catalog->shouldReceive('register')->once()->andThrow(new \RuntimeException('catalog unavailable'));
        $genericAdapter = new LegacyCompatibleFileAdapter(
            app(FileManagerConfiguration::class),
            app(FilePolicyRegistry::class),
            $catalog,
            app(LegacyFileResolver::class),
            app(LegacyFileObservationService::class),
            app(FileManagerMetrics::class)
        );
        $adapter = new CompanyFileManagerAdapter(
            app(FileManagerConfiguration::class),
            $genericAdapter,
            app(LegacyFileResolver::class),
            app(FileManagerMetrics::class)
        );

        $adapter->synchronize(
            FileCategory::CompanyLoginBackground,
            'local',
            $path,
            'login_background_image'
        );

        Storage::disk('local')->assertExists($path);
        $this->assertSame(0, ManagedFile::query()->count());
    }

    public function test_hybrid_read_falls_back_to_compatible_legacy_path_and_counts_metric(): void
    {
        $path = UploadedFile::fake()->image('legacy.jpg', 100, 100)
            ->storeAs('private/empresa/login', 'legacy.jpg', 'local');
        $this->assertIsString($path);
        DB::table('configuracoes')->updateOrInsert(
            ['chave' => 'login_background_image'],
            ['valor' => $path, 'tipo' => 'texto', 'created_at' => now(), 'updated_at' => now()]
        );

        $this->get('/api/v1/configuracoes/empresa/login-background-publico')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $metrics = app(FileManagerMetrics::class)->snapshot(FileCategory::CompanyLoginBackground);
        $this->assertSame(1, $metrics['legacy_fallback']);
    }

    public function test_company_logo_uses_same_compatible_adapter_and_survives_mode_off(): void
    {
        config()->set('file-manager.enabled_categories', ['company_logo']);

        $this->patch('/api/v1/configuracoes/empresa', [
            'empresa_logo' => UploadedFile::fake()->image('marca.png', 320, 120),
        ])->assertOk();

        $configuredPath = (string) DB::table('configuracoes')
            ->where('chave', 'empresa_logo')
            ->value('valor');
        $file = ManagedFile::query()->where('category', FileCategory::CompanyLogo->value)->firstOrFail();

        $this->assertStringStartsWith('private/empresa/logo_', $configuredPath);
        $this->assertSame($configuredPath, $file->storage_key);
        $this->assertSame(1, ManagedFileLink::query()->where('relation', 'empresa_logo')->where('is_current', true)->count());
        $this->assertSame(1, ManagedFileLegacyAlias::query()->where('file_id', $file->id)->count());

        config()->set('file-manager.mode', 'off');
        $this->get('/api/v1/configuracoes/empresa/logo-publica')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    private function configuredBackgroundPath(): string
    {
        return (string) DB::table('configuracoes')
            ->where('chave', 'login_background_image')
            ->value('valor');
    }
}
