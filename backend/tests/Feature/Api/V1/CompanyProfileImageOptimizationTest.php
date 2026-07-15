<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class CompanyProfileImageOptimizationTest extends TestCase
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

    public function test_login_background_upload_is_reencoded_as_compressed_jpeg(): void
    {
        Storage::fake('local');

        $admin = $this->createUserRecord([
            'nome' => 'Administrador Branding',
            'email' => 'branding.admin@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        Sanctum::actingAs($admin, ['*']);

        // Simula um upload real: PNG grande (sem necessidade de transparência),
        // do jeito que costuma vir de um banco de imagens de estoque.
        $png = UploadedFile::fake()->image('fundo-login.png', 2400, 1600);

        $response = $this->patch('/api/v1/configuracoes/empresa', [
            'login_background_image' => $png,
        ]);

        $response->assertOk();

        $storedPath = DB::table('configuracoes')->where('chave', 'login_background_image')->value('valor');

        $this->assertNotNull($storedPath);
        $this->assertStringEndsWith('.jpg', $storedPath, 'PNG sem transparência deve ser reencodado como JPEG.');
        Storage::disk('local')->assertExists($storedPath);

        $absolutePath = Storage::disk('local')->path($storedPath);
        [$width, $height] = getimagesize($absolutePath);

        $this->assertLessThanOrEqual(1920, max($width, $height), 'Fundo de login não deve exceder o limite de dimensão.');
        $this->assertSame(IMAGETYPE_JPEG, exif_imagetype($absolutePath));
    }

    public function test_oversized_logo_upload_is_capped_but_keeps_png_transparency(): void
    {
        Storage::fake('local');

        $admin = $this->createUserRecord([
            'nome' => 'Administrador Branding',
            'email' => 'branding.admin2@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $png = UploadedFile::fake()->image('logo.png', 3000, 3000);

        $response = $this->patch('/api/v1/configuracoes/empresa', [
            'empresa_logo' => $png,
        ]);

        $response->assertOk();

        $storedPath = DB::table('configuracoes')->where('chave', 'empresa_logo')->value('valor');

        $this->assertNotNull($storedPath);
        $this->assertStringEndsWith('.png', $storedPath, 'Logo deve permanecer em PNG (suporte a transparência).');
        Storage::disk('local')->assertExists($storedPath);

        $absolutePath = Storage::disk('local')->path($storedPath);
        [$width, $height] = getimagesize($absolutePath);

        $this->assertLessThanOrEqual(800, max($width, $height), 'Logo não deve exceder o limite de dimensão.');
    }
}
