<?php

namespace Tests\Feature\Api\V1;

use App\Services\Company\CompanyProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class CompanyProfileImageSecurityTest extends TestCase
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
        $this->grantGroupPermissions(1, [
            'configuracoes' => ['visualizar', 'editar'],
        ]);
    }

    public function test_svg_logo_is_rejected_and_previous_file_is_preserved(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->createUserRecord([
            'email' => 'branding.svg@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]), ['*']);

        $oldPath = $this->seedLogo('logo-antiga.png');
        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->patch('/api/v1/configuracoes/empresa', ['empresa_logo' => $svg])
            ->assertUnprocessable();

        $this->assertSame($oldPath, $this->configuredLogoPath());
        Storage::disk('local')->assertExists($oldPath);
    }

    public function test_logo_replacement_publishes_new_file_before_removing_previous_one(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->createUserRecord([
            'email' => 'branding.swap@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]), ['*']);

        $oldPath = $this->seedLogo('logo-antiga.png');

        $this->patch('/api/v1/configuracoes/empresa', [
            'empresa_logo' => UploadedFile::fake()->image('logo-nova.png', 80, 80),
        ])->assertOk();

        $newPath = $this->configuredLogoPath();
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('local')->assertExists($newPath);
        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_storage_failure_keeps_previous_logo_and_configuration(): void
    {
        Storage::fake('local');
        $realDisk = Storage::disk('local');
        $oldPath = $this->seedLogo('logo-antiga.png');

        $failingDisk = \Mockery::mock($realDisk)->makePartial();
        $failingDisk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($failingDisk);

        try {
            app(CompanyProfileService::class)->storeLogo(
                UploadedFile::fake()->image('logo-nova.png', 80, 80)
            );
            $this->fail('A falha de escrita deveria interromper a substituicao.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('persistir', $exception->getMessage());
        }

        $this->assertSame($oldPath, $this->configuredLogoPath());
        $this->assertTrue($realDisk->exists($oldPath));
    }

    public function test_public_logo_response_has_safe_headers_and_legacy_svg_is_not_served(): void
    {
        Storage::fake('local');
        $this->seedLogo('logo-segura.png');

        $this->get('/api/v1/configuracoes/empresa/logo-publica')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');

        $svgPath = 'private/empresa/logo-legada.svg';
        Storage::disk('local')->put(
            $svgPath,
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );
        DB::table('configuracoes')->where('chave', 'empresa_logo')->update([
            'valor' => $svgPath,
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/configuracoes/empresa/logo-publica')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'COMPANY_LOGO_NOT_FOUND');
    }

    private function seedLogo(string $filename): string
    {
        $path = UploadedFile::fake()->image($filename, 40, 40)
            ->storeAs('private/empresa', $filename, 'local');
        $this->assertIsString($path);

        DB::table('configuracoes')->updateOrInsert(
            ['chave' => 'empresa_logo'],
            ['valor' => $path, 'tipo' => 'texto', 'created_at' => now(), 'updated_at' => now()]
        );

        return $path;
    }

    private function configuredLogoPath(): string
    {
        return (string) DB::table('configuracoes')
            ->where('chave', 'empresa_logo')
            ->value('valor');
    }
}
