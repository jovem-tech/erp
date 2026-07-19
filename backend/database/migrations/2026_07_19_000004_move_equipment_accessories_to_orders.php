<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ARCHIVE_TABLE = 'equipamento_acessorios_legado';

    public function up(): void
    {
        if (! Schema::hasTable('equipamentos') || ! Schema::hasTable('os')) {
            return;
        }

        if (! Schema::hasTable(self::ARCHIVE_TABLE)) {
            Schema::create(self::ARCHIVE_TABLE, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('equipamento_id')->unique();
                $table->unsignedBigInteger('os_destino_id')->nullable()->index();
                $table->text('acessorios');
                $table->string('resultado', 40);
                $table->timestamps();
            });
        }

        DB::transaction(function (): void {
            DB::table('equipamentos')
                ->select(['id', 'acessorios'])
                ->whereNotNull('acessorios')
                ->whereRaw("TRIM(acessorios) <> ''")
                ->orderBy('id')
                ->chunkById(200, function ($equipments): void {
                    $equipmentIds = $equipments
                        ->pluck('id')
                        ->map(static fn ($id): int => (int) $id)
                        ->all();

                    $latestOrders = DB::table('os')
                        ->select(['id', 'equipamento_id', 'acessorios'])
                        ->whereIn('equipamento_id', $equipmentIds)
                        ->orderBy('equipamento_id')
                        ->orderByDesc('id')
                        ->get()
                        ->groupBy('equipamento_id')
                        ->map(static fn ($orders) => $orders->first());

                    $now = now();
                    $archiveRows = [];

                    foreach ($equipments as $equipment) {
                        $equipmentId = (int) $equipment->id;
                        $accessories = trim((string) $equipment->acessorios);
                        $latestOrder = $latestOrders->get($equipmentId);
                        $orderId = $latestOrder !== null ? (int) $latestOrder->id : null;
                        $result = 'sem_os';

                        if ($latestOrder !== null) {
                            $orderAccessories = trim((string) ($latestOrder->acessorios ?? ''));
                            $result = 'os_ja_possuia';

                            if ($orderAccessories === '') {
                                $updated = DB::table('os')
                                    ->where('id', $orderId)
                                    ->where(function ($query): void {
                                        $query->whereNull('acessorios')
                                            ->orWhereRaw("TRIM(acessorios) = ''");
                                    })
                                    ->update([
                                        'acessorios' => $accessories,
                                        'updated_at' => $now,
                                    ]);

                                $result = $updated === 1 ? 'copiado_para_os' : 'os_ja_possuia';
                            }
                        }

                        $archiveRows[] = [
                            'equipamento_id' => $equipmentId,
                            'os_destino_id' => $orderId,
                            'acessorios' => $accessories,
                            'resultado' => $result,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    DB::table(self::ARCHIVE_TABLE)->upsert(
                        $archiveRows,
                        ['equipamento_id'],
                        ['os_destino_id', 'acessorios', 'resultado', 'updated_at']
                    );

                    DB::table('equipamentos')
                        ->whereIn('id', $equipmentIds)
                        ->update(['acessorios' => null, 'updated_at' => $now]);
                }, 'id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::ARCHIVE_TABLE)) {
            return;
        }

        $hasEquipments = Schema::hasTable('equipamentos');
        $hasOrders = Schema::hasTable('os');

        DB::transaction(function () use ($hasEquipments, $hasOrders): void {
            DB::table(self::ARCHIVE_TABLE)
                ->orderBy('id')
                ->chunkById(200, function ($archiveRows) use ($hasEquipments, $hasOrders): void {
                    $now = now();

                    foreach ($archiveRows as $archive) {
                        if ($hasEquipments) {
                            DB::table('equipamentos')
                                ->where('id', (int) $archive->equipamento_id)
                                ->where(function ($query): void {
                                    $query->whereNull('acessorios')
                                        ->orWhereRaw("TRIM(acessorios) = ''");
                                })
                                ->update([
                                    'acessorios' => (string) $archive->acessorios,
                                    'updated_at' => $now,
                                ]);
                        }

                        if ((string) $archive->resultado === 'copiado_para_os'
                            && $archive->os_destino_id !== null
                            && $hasOrders) {
                            DB::table('os')
                                ->where('id', (int) $archive->os_destino_id)
                                ->where('acessorios', (string) $archive->acessorios)
                                ->update(['acessorios' => null, 'updated_at' => $now]);
                        }
                    }
                }, 'id');
        });

        Schema::dropIfExists(self::ARCHIVE_TABLE);
    }
};
