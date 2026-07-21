<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class UserPhotoControllerTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rebuildLegacySchema();
        Storage::fake('local');
    }

    public function test_user_uploads_profile_photo_and_it_is_persisted_and_normalized(): void
    {
        $user = $this->createUserRecord(['grupo_id' => null]);
        Sanctum::actingAs($user);

        $response = $this->post('/api/v1/auth/photo', [
            'photo_file' => UploadedFile::fake()->image('foto.jpg', 1200, 900),
        ]);

        $response->assertOk()->assertJsonPath('data.has_photo', true);

        $user->refresh();
        $path = (string) $user->foto;
        $this->assertStringStartsWith('private/usuarios/' . $user->id . '/', $path);
        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_uploading_a_new_photo_replaces_and_deletes_the_previous_one(): void
    {
        $user = $this->createUserRecord(['grupo_id' => null]);
        Sanctum::actingAs($user);

        $this->post('/api/v1/auth/photo', [
            'photo_file' => UploadedFile::fake()->image('primeira.jpg', 600, 600),
        ])->assertOk();
        $firstPath = (string) $user->refresh()->foto;

        $this->post('/api/v1/auth/photo', [
            'photo_file' => UploadedFile::fake()->image('segunda.png', 600, 600),
        ])->assertOk();
        $secondPath = (string) $user->refresh()->foto;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);
    }

    public function test_rejects_non_image_upload_without_persisting(): void
    {
        $user = $this->createUserRecord(['grupo_id' => null]);
        Sanctum::actingAs($user);

        $this->post('/api/v1/auth/photo', [
            'photo_file' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);

        $this->assertSame('', (string) $user->refresh()->foto);
    }

    public function test_image_endpoint_serves_current_photo_and_404_when_missing(): void
    {
        $user = $this->createUserRecord(['grupo_id' => null]);
        Sanctum::actingAs($user);

        $this->get('/api/v1/auth/photo/image')->assertStatus(404);

        $this->post('/api/v1/auth/photo', [
            'photo_file' => UploadedFile::fake()->image('foto.webp', 400, 400),
        ])->assertOk();

        $this->get('/api/v1/auth/photo/image')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_user_removes_profile_photo(): void
    {
        $user = $this->createUserRecord(['grupo_id' => null]);
        Sanctum::actingAs($user);

        $this->post('/api/v1/auth/photo', [
            'photo_file' => UploadedFile::fake()->image('foto.jpg', 400, 400),
        ])->assertOk();
        $path = (string) $user->refresh()->foto;

        $this->delete('/api/v1/auth/photo')
            ->assertOk()
            ->assertJsonPath('data.has_photo', false);

        $this->assertSame('', (string) $user->refresh()->foto);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_photo_upload_requires_authentication(): void
    {
        $this->post('/api/v1/auth/photo', [
            'photo_file' => UploadedFile::fake()->image('foto.jpg'),
        ])->assertStatus(401);
    }
}
