<?php

namespace Tests\Feature\Api\V1;

use App\Models\EquipmentCollectorPairing;
use App\Models\EquipmentPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class EquipmentCreationTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(3, [
            'equipamentos' => ['visualizar', 'criar', 'editar'],
            'clientes' => ['visualizar', 'criar'],
        ]);
    }

    public function test_equipment_form_data_returns_catalogs_defaults_and_metadata(): void
    {
        Sanctum::actingAs($this->makeEquipmentManager(), ['*']);

        $response = $this->getJson('/api/v1/equipments/form-data');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.form.max_photos', 4)
            ->assertJsonPath('data.form.password_modes.0.value', 'desenho')
            ->assertJsonPath('data.form.collector.pairing_ttl_minutes', 30)
            ->assertJsonPath('data.form.collector.local_root', config('services.collector.local_root'))
            ->assertJsonPath('data.form.desktop_defaults.marca_nome', 'Montado')
            ->assertJsonPath('data.form.desktop_defaults.modelo_nome', 'Desktop montado')
            ->assertJsonStructure([
                'data' => [
                    'form' => [
                        'catalog_relations',
                    ],
                ],
            ])
            ->assertJsonFragment([
                'tipo_id' => 1,
                'marca_id' => 2,
                'modelo_id' => 2,
            ])
            ->assertJsonPath('data.form.types.0.nome', 'Desktop');
    }

    public function test_quick_add_brand_and_model_create_catalog_entries(): void
    {
        Sanctum::actingAs($this->makeEquipmentManager(), ['*']);

        $brandResponse = $this->postJson('/api/v1/equipments/brands', [
            'tipo_id' => 2,
            'nome' => 'Lenovo',
        ]);

        $brandResponse->assertCreated()
            ->assertJsonPath('data.brand.tipo_id', 2)
            ->assertJsonPath('data.brand.nome', 'Lenovo');

        $brandId = (int) $brandResponse->json('data.brand.id');
        $anchorModelId = (int) DB::table('equipamentos_catalogo_relacoes')
            ->where('tipo_id', 2)
            ->where('marca_id', $brandId)
            ->value('modelo_id');

        $this->assertGreaterThan(0, $anchorModelId);

        $modelResponse = $this->postJson('/api/v1/equipments/models', [
            'tipo_id' => 2,
            'marca_id' => $brandId,
            'nome' => 'ThinkPad T14',
        ]);

        $modelResponse->assertCreated()
            ->assertJsonPath('data.model.tipo_id', 2)
            ->assertJsonPath('data.model.marca_id', $brandId)
            ->assertJsonPath('data.model.nome', 'Thinkpad T14');

        $modelId = (int) $modelResponse->json('data.model.id');

        $this->assertDatabaseHas('equipamentos_marcas', [
            'id' => $brandId,
            'nome' => 'Lenovo',
        ]);

        $this->assertDatabaseHas('equipamentos_modelos', [
            'id' => $anchorModelId,
            'marca_id' => $brandId,
            'nome' => '__CATALOG_BRAND_SCOPE__',
            'ativo' => 0,
        ]);

        $this->assertDatabaseHas('equipamentos_modelos', [
            'marca_id' => $brandId,
            'nome' => 'Thinkpad T14',
        ]);

        $this->assertDatabaseHas('equipamentos_catalogo_relacoes', [
            'tipo_id' => 2,
            'marca_id' => $brandId,
            'modelo_id' => $anchorModelId,
            'ativo' => 1,
        ]);

        $this->assertDatabaseHas('equipamentos_catalogo_relacoes', [
            'tipo_id' => 2,
            'marca_id' => $brandId,
            'modelo_id' => $modelId,
            'ativo' => 1,
        ]);
    }

    public function test_store_creates_equipment_with_private_photos_and_desktop_defaults(): void
    {
        Storage::fake('local');

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Equipamento',
            'telefone1' => '(21) 98888-1000',
        ]);

        Sanctum::actingAs($this->makeEquipmentManager(), ['*']);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/equipments', [
                'cliente_id' => $clientId,
                'tipo_id' => 1,
                'numero_serie' => 'SN-DESKTOP-001',
                'senha_tipo' => 'desenho',
                'senha_desenho' => '1-2-5-8',
                'desktop_modalidade' => 'montado',
                'gabinete_tipo' => 'Mid Tower',
                'placa_mae' => 'B550M',
                'processador' => 'Ryzen 5 5600',
                'memoria_ram' => '16GB',
                'armazenamento' => 'SSD 1TB',
                'placa_video' => 'RTX 4060',
                'fonte_alimentacao' => '650W',
                'cor' => 'Preto',
                'cor_hex' => '#111827',
                'cor_rgb' => '17, 24, 39',
                'estado_fisico' => 'Gabinete em bom estado.',
                'acessorios' => 'Fonte, cabo de forca',
                'observacoes' => 'Cadastro completo de bancada.',
                'foto_principal_index' => 1,
                'fotos' => [
                    UploadedFile::fake()->image('equipamento-frontal.jpg'),
                    UploadedFile::fake()->image('equipamento-lateral.png'),
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.equipment.client.nome_razao', 'Cliente Equipamento')
            ->assertJsonPath('data.equipment.desktop_modalidade', 'montado')
            ->assertJsonPath('data.equipment.marca_nome', 'Montado')
            ->assertJsonPath('data.equipment.modelo_nome', 'Desktop montado');

        $equipmentId = (int) $response->json('data.equipment.id');

        $this->assertDatabaseHas('equipamentos', [
            'id' => $equipmentId,
            'cliente_id' => $clientId,
            'tipo_id' => 1,
            'marca_id' => 2,
            'modelo_id' => 2,
            'numero_serie' => 'SN-DESKTOP-001',
            'senha_acesso' => 'desenho_1-2-5-8',
            'desktop_modalidade' => 'montado',
            'gabinete_tipo' => 'Mid Tower',
            'processador' => 'Ryzen 5 5600',
        ]);

        $photos = EquipmentPhoto::query()
            ->where('equipamento_id', $equipmentId)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $photos);
        $this->assertSame(1, $photos->where('is_principal', true)->count());
        $this->assertTrue((bool) $photos->values()->get(1)?->is_principal);

        foreach ($photos as $photo) {
            Storage::disk('local')->assertExists((string) $photo->arquivo);
        }

        $photoResponse = $this->get('/api/v1/equipments/' . $equipmentId . '/photos/' . $photos->first()->id);

        $photoResponse->assertOk();
        $this->assertStringStartsWith('image/', (string) $photoResponse->headers->get('content-type'));

        $primaryPhotoId = (int) $photos->values()->get(1)?->id;

        $summaryResponse = $this->getJson('/api/v1/equipments');

        $summaryResponse->assertOk()
            ->assertJsonPath('data.equipments.0.id', $equipmentId)
            ->assertJsonPath('data.equipments.0.primary_photo_id', $primaryPhotoId);

        $detailResponse = $this->getJson('/api/v1/equipments/' . $equipmentId);

        $detailResponse->assertOk()
            ->assertJsonPath('data.equipment.primary_photo_id', $primaryPhotoId)
            ->assertJsonPath('data.equipment.photos.0.id', $primaryPhotoId);
    }

    public function test_store_requires_at_least_one_photo(): void
    {
        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Sem Foto',
            'telefone1' => '(21) 98888-3000',
        ]);

        Sanctum::actingAs($this->makeEquipmentManager(), ['*']);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/equipments', [
                'cliente_id' => $clientId,
                'tipo_id' => 1,
                'numero_serie' => 'SN-SEM-FOTO-001',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.details.fotos.0', 'validation.required');
    }

    public function test_update_keeps_existing_photo_as_primary_and_replaces_removed_files(): void
    {
        Storage::fake('local');

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Atualizacao',
            'telefone1' => '(21) 95555-1000',
        ]);

        Sanctum::actingAs($this->makeEquipmentManager(), ['*']);

        $createResponse = $this
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/equipments', [
                'cliente_id' => $clientId,
                'tipo_id' => 1,
                'numero_serie' => 'SN-EDIT-001',
                'desktop_modalidade' => 'montado',
                'foto_principal_index' => 1,
                'fotos' => [
                    UploadedFile::fake()->image('equipamento-original-1.jpg'),
                    UploadedFile::fake()->image('equipamento-original-2.jpg'),
                ],
            ]);

        $createResponse->assertCreated();

        $equipmentId = (int) $createResponse->json('data.equipment.id');
        $originalPhotos = EquipmentPhoto::query()
            ->where('equipamento_id', $equipmentId)
            ->orderBy('id')
            ->get()
            ->values();

        $retainedPhoto = $originalPhotos->get(0);
        $removedPhoto = $originalPhotos->get(1);

        $this->assertNotNull($retainedPhoto);
        $this->assertNotNull($removedPhoto);

        $removedPath = (string) $removedPhoto->arquivo;
        Storage::disk('local')->assertExists($removedPath);

        $updateResponse = $this
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/equipments/' . $equipmentId, [
                '_method' => 'PATCH',
                'cliente_id' => $clientId,
                'tipo_id' => 1,
                'numero_serie' => 'SN-EDIT-002',
                'desktop_modalidade' => 'montado',
                'existing_photo_sync' => 1,
                'existing_photo_ids' => [
                    (int) $retainedPhoto->id,
                ],
                'foto_principal_existente_id' => (int) $retainedPhoto->id,
                'fotos' => [
                    UploadedFile::fake()->image('equipamento-atualizado.jpg'),
                ],
            ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.equipment.id', $equipmentId)
            ->assertJsonPath('data.equipment.numero_serie', 'SN-EDIT-002')
            ->assertJsonPath('data.equipment.primary_photo_id', (int) $retainedPhoto->id);

        $updatedPhotos = EquipmentPhoto::query()
            ->where('equipamento_id', $equipmentId)
            ->orderBy('id')
            ->get()
            ->values();

        $this->assertCount(2, $updatedPhotos);
        $this->assertTrue($updatedPhotos->contains(fn (EquipmentPhoto $photo): bool => (int) $photo->id === (int) $retainedPhoto->id));
        $this->assertFalse($updatedPhotos->contains(fn (EquipmentPhoto $photo): bool => (int) $photo->id === (int) $removedPhoto->id));
        $this->assertSame(1, $updatedPhotos->where('is_principal', true)->count());
        $this->assertTrue((bool) EquipmentPhoto::query()->find((int) $retainedPhoto->id)?->is_principal);

        Storage::disk('local')->assertMissing($removedPath);

        foreach ($updatedPhotos as $photo) {
            Storage::disk('local')->assertExists((string) $photo->arquivo);
        }
    }

    public function test_update_truncates_technical_summary_to_fit_database_column(): void
    {
        Storage::fake('local');

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Resumo Longo',
            'telefone1' => '(21) 94444-1000',
        ]);

        Sanctum::actingAs($this->makeEquipmentManager(), ['*']);

        $createResponse = $this
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/equipments', [
                'cliente_id' => $clientId,
                'tipo_id' => 1,
                'numero_serie' => 'SN-RESUMO-001',
                'desktop_modalidade' => 'montado',
                'fotos' => [
                    UploadedFile::fake()->image('resumo-inicial.jpg'),
                ],
            ]);

        $createResponse->assertCreated();

        $equipmentId = (int) $createResponse->json('data.equipment.id');
        $photoId = (int) EquipmentPhoto::query()
            ->where('equipamento_id', $equipmentId)
            ->orderBy('id')
            ->value('id');

        $long120 = str_repeat('Gabinete amplo ', 7);
        $long255 = str_repeat('Resumo tecnico extenso para validacao ', 6);

        $updateResponse = $this
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/equipments/' . $equipmentId, [
                '_method' => 'PATCH',
                'cliente_id' => $clientId,
                'tipo_id' => 1,
                'numero_serie' => 'SN-RESUMO-002',
                'desktop_modalidade' => 'montado',
                'gabinete_tipo' => $long120,
                'gabinete_observacao' => $long255,
                'placa_mae' => $long255,
                'chipset' => $long255,
                'processador' => $long255,
                'memoria_ram' => $long255,
                'armazenamento' => $long255,
                'placa_video' => $long255,
                'fonte_alimentacao' => $long255,
                'existing_photo_sync' => 1,
                'existing_photo_ids' => [
                    $photoId,
                ],
                'foto_principal_existente_id' => $photoId,
            ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.equipment.id', $equipmentId);

        $this->assertLessThanOrEqual(255, mb_strlen((string) $updateResponse->json('data.equipment.resumo_tecnico')));
        $this->assertDatabaseHas('equipamentos', [
            'id' => $equipmentId,
            'numero_serie' => 'SN-RESUMO-002',
        ]);
    }

    public function test_store_requires_brand_and_model_for_notebook_type(): void
    {
        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Notebook Sem Catalogo',
            'telefone1' => '(21) 98888-2000',
        ]);

        Sanctum::actingAs($this->makeEquipmentManager(), ['*']);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/equipments', [
                'cliente_id' => $clientId,
                'tipo_id' => 2,
                'numero_serie' => 'SN-NOTEBOOK-001',
                'fotos' => [
                    UploadedFile::fake()->image('notebook-sem-catalogo.jpg'),
                ],
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.details.marca_id.0', 'Selecione uma marca para o equipamento.')
            ->assertJsonPath('error.details.modelo_id.0', 'Selecione um modelo para o equipamento.');
    }

    public function test_store_forces_oem_modality_for_notebook_even_when_montado_is_requested(): void
    {
        Storage::fake('local');

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Notebook OEM',
            'telefone1' => '(21) 98888-2100',
        ]);

        Sanctum::actingAs($this->makeEquipmentManager(), ['*']);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/equipments', [
                'cliente_id' => $clientId,
                'tipo_id' => 2,
                'marca_id' => 1,
                'modelo_id' => 1,
                'numero_serie' => 'SN-NOTEBOOK-002',
                'desktop_modalidade' => 'montado',
                'fotos' => [
                    UploadedFile::fake()->image('notebook-oem.jpg'),
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.equipment.desktop_modalidade', 'oem')
            ->assertJsonPath('data.equipment.marca_nome', 'Dell')
            ->assertJsonPath('data.equipment.modelo_nome', 'Inspiron 15');

        $equipmentId = (int) $response->json('data.equipment.id');

        $this->assertDatabaseHas('equipamentos', [
            'id' => $equipmentId,
            'tipo_id' => 2,
            'marca_id' => 1,
            'modelo_id' => 1,
            'desktop_modalidade' => 'oem',
        ]);
    }

    public function test_model_suggestions_fail_safe_to_empty_list_when_upstream_fails(): void
    {
        Sanctum::actingAs($this->makeEquipmentManager(), ['*']);

        Http::fake([
            'https://suggestqueries.google.com/*' => Http::response([], 500),
        ]);

        $response = $this->getJson('/api/v1/equipments/models/suggestions?nome=thinkpad&marca_nome=Lenovo&tipo_nome=Notebook');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.suggestions', []);
    }

    public function test_local_collector_snapshot_reads_legacy_file_and_maps_fields(): void
    {
        Sanctum::actingAs($this->makeEquipmentManager(), ['*']);

        $root = storage_path('framework/testing/collector-local-read');
        @mkdir($root, 0777, true);

        config()->set('services.collector.local_root', $root);

        file_put_contents($root . DIRECTORY_SEPARATOR . 'last-snapshot.json', json_encode([
            'savedAtUtc' => '2026-06-24T18:00:00Z',
            'collectedAtUtc' => '2026-06-24T18:00:01Z',
            'snapshot' => [
                'deviceType' => 'desktop',
                'serialNumber' => 'SN-LOCAL-001',
                'serialSource' => 'bios',
                'manufacturer' => 'Dell',
                'model' => 'OptiPlex 7010',
                'motherboard' => 'B660M',
                'chipset' => 'B660',
                'cpu' => 'Intel Core i5-12400',
                'memorySummary' => '16 GB DDR4',
                'storageSummary' => 'SSD 512 GB',
                'gpu' => 'Intel UHD',
                'chassisType' => 'Mini Tower',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $response = $this->getJson('/api/v1/equipments/collector/local-snapshot');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.collector.source_path', $root . DIRECTORY_SEPARATOR . 'last-snapshot.json')
            ->assertJsonPath('data.collector.mapped.numero_serie', 'SN-LOCAL-001')
            ->assertJsonPath('data.collector.mapped.placa_mae', 'B660M')
            ->assertJsonPath('data.collector.mapped.processador', 'Intel Core i5-12400')
            ->assertJsonPath('data.collector.mapped.gabinete_tipo', 'Mini Tower')
            ->assertJsonPath('data.collector.mapped.marca_nome', 'Dell');
    }

    public function test_local_collector_collect_falls_back_to_existing_snapshot_when_executable_is_missing(): void
    {
        Sanctum::actingAs($this->makeEquipmentManager(), ['*']);

        $root = storage_path('framework/testing/collector-local-fallback');
        @mkdir($root, 0777, true);

        config()->set('services.collector.local_root', $root);
        config()->set('services.collector.published_root', storage_path('framework/testing/collector-published-missing'));

        file_put_contents($root . DIRECTORY_SEPARATOR . 'last-snapshot.json', json_encode([
            'snapshot' => [
                'deviceType' => 'desktop',
                'serialNumber' => 'SN-LOCAL-002',
                'manufacturer' => 'Lenovo',
                'motherboard' => 'H510',
                'cpu' => 'Intel Core i7',
                'memorySummary' => '32 GB DDR4',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $response = $this->postJson('/api/v1/equipments/collector/local-collect');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.collector.mapped.numero_serie', 'SN-LOCAL-002')
            ->assertJsonPath('data.collector.collector.installed_now', false);

        $this->assertStringContainsString(
            'ultimo snapshot local disponivel',
            (string) $response->json('data.collector.collector.warning')
        );
    }

    public function test_collector_pairing_snapshot_flow_is_consumed_by_equipment_creation(): void
    {
        Storage::fake('local');

        config()->set('services.collector.token', 'collector-secret');

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Snapshot',
            'telefone1' => '(21) 97777-1000',
        ]);

        $user = $this->makeEquipmentManager();
        Sanctum::actingAs($user, ['*']);

        $pairingResponse = $this->postJson('/api/v1/equipments/collector-pairings');
        $pairingResponse->assertCreated();

        $code = (string) $pairingResponse->json('data.pairing.code');

        $snapshotResponse = $this
            ->withHeader('X-Collector-Token', 'collector-secret')
            ->postJson('/api/v1/collector/snapshots', [
                'pairing_code' => $code,
                'source' => 'bench-agent',
                'agent_version' => '1.4.0',
                'hostname' => 'LAB-01',
                'snapshot' => [
                    'tipo_nome' => 'Desktop',
                    'desktop_mode' => 'oem',
                    'motherboard' => 'B660M DS3H',
                    'cpu' => 'Intel Core i7-12700',
                    'ram' => '32GB DDR4',
                    'storage' => 'SSD NVMe 1TB',
                    'gpu' => 'RTX 4070',
                    'psu' => '750W',
                    'serial_number' => 'SN-COLLECTOR-9',
                ],
            ]);

        $snapshotResponse->assertCreated()
            ->assertJsonPath('data.pairing.code', $code);

        $pairingViewResponse = $this->getJson('/api/v1/equipments/collector-pairings/' . $code);

        $pairingViewResponse->assertOk()
            ->assertJsonPath('data.pairing.code', $code)
            ->assertJsonPath('data.pairing.snapshot.processador', 'Intel Core i7-12700')
            ->assertJsonPath('data.pairing.snapshot.memoria_ram', '32GB DDR4')
            ->assertJsonPath('data.pairing.snapshot.numero_serie', 'SN-COLLECTOR-9');

        $createResponse = $this->postJson('/api/v1/equipments', [
            'cliente_id' => $clientId,
            'tipo_id' => 1,
            'marca_id' => 1,
            'modelo_id' => 1,
            'collector_pairing_code' => $code,
            'numero_serie' => 'SN-COLLECTOR-9',
            'desktop_modalidade' => 'oem',
            'placa_mae' => 'B660M DS3H',
            'processador' => 'Intel Core i7-12700',
            'memoria_ram' => '32GB DDR4',
            'armazenamento' => 'SSD NVMe 1TB',
            'placa_video' => 'RTX 4070',
            'fonte_alimentacao' => '750W',
            'fotos' => [
                UploadedFile::fake()->image('collector-desktop.jpg'),
            ],
        ]);

        $createResponse->assertCreated();

        $this->assertDatabaseHas('equipment_collector_pairings', [
            'code' => $code,
        ]);

        $pairing = EquipmentCollectorPairing::query()->where('code', $code)->firstOrFail();
        $this->assertNotNull($pairing->consumed_at);
    }

    private function makeEquipmentManager()
    {
        return $this->createUserRecord([
            'nome' => 'Gestor de Equipamentos',
            'email' => 'equipamentos.manager@example.com',
            'perfil' => 'gerente',
            'grupo_id' => 3,
        ]);
    }
}
