<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class MoveEquipmentAccessoriesToOrdersMigrationTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
    }

    public function test_it_archives_legacy_values_and_only_fills_a_blank_latest_order(): void
    {
        $clientId = $this->createClientRecord();

        $equipmentToCopy = $this->createEquipmentRecord($clientId, [
            'numero_serie' => 'MIG-ACESSORIOS-001',
            'acessorios' => 'Carregador original',
        ]);
        $equipmentToPreserve = $this->createEquipmentRecord($clientId, [
            'numero_serie' => 'MIG-ACESSORIOS-002',
            'acessorios' => 'Bolsa de transporte',
        ]);
        $equipmentWithoutOrder = $this->createEquipmentRecord($clientId, [
            'numero_serie' => 'MIG-ACESSORIOS-003',
            'acessorios' => 'Capa protetora',
        ]);

        $blankOrderId = $this->createOrderRecord([
            'numero_os' => 'OS-MIG-ACESS-001',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentToCopy,
            'acessorios' => null,
        ]);
        $existingOrderId = $this->createOrderRecord([
            'numero_os' => 'OS-MIG-ACESS-002',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentToPreserve,
            'acessorios' => 'Cabo USB recebido nesta OS',
        ]);

        $migration = require database_path('migrations/2026_07_19_000004_move_equipment_accessories_to_orders.php');
        $migration->up();

        $this->assertSame('Carregador original', DB::table('os')->where('id', $blankOrderId)->value('acessorios'));
        $this->assertSame('Cabo USB recebido nesta OS', DB::table('os')->where('id', $existingOrderId)->value('acessorios'));
        $this->assertSame(0, DB::table('equipamentos')->whereNotNull('acessorios')->count());

        $this->assertDatabaseHas('equipamento_acessorios_legado', [
            'equipamento_id' => $equipmentToCopy,
            'os_destino_id' => $blankOrderId,
            'acessorios' => 'Carregador original',
            'resultado' => 'copiado_para_os',
        ]);
        $this->assertDatabaseHas('equipamento_acessorios_legado', [
            'equipamento_id' => $equipmentToPreserve,
            'os_destino_id' => $existingOrderId,
            'acessorios' => 'Bolsa de transporte',
            'resultado' => 'os_ja_possuia',
        ]);
        $this->assertDatabaseHas('equipamento_acessorios_legado', [
            'equipamento_id' => $equipmentWithoutOrder,
            'os_destino_id' => null,
            'acessorios' => 'Capa protetora',
            'resultado' => 'sem_os',
        ]);

        $migration->down();

        $this->assertSame('Carregador original', DB::table('equipamentos')->where('id', $equipmentToCopy)->value('acessorios'));
        $this->assertSame('Bolsa de transporte', DB::table('equipamentos')->where('id', $equipmentToPreserve)->value('acessorios'));
        $this->assertSame('Capa protetora', DB::table('equipamentos')->where('id', $equipmentWithoutOrder)->value('acessorios'));
        $this->assertNull(DB::table('os')->where('id', $blankOrderId)->value('acessorios'));
        $this->assertSame('Cabo USB recebido nesta OS', DB::table('os')->where('id', $existingOrderId)->value('acessorios'));
        $this->assertFalse(Schema::hasTable('equipamento_acessorios_legado'));
    }
}
