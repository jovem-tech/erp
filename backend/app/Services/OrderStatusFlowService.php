<?php

namespace App\Services;

use App\Models\OrderStatus;
use App\Models\OrderStatusTransition;
use Illuminate\Support\Facades\DB;

class OrderStatusFlowService
{
    /**
     * @param array<int|string, array<int, int|string>> $transitionsByOrigem
     */
    public function syncTransitions(array $transitionsByOrigem): void
    {
        DB::transaction(function () use ($transitionsByOrigem): void {
            $allStatusIds = OrderStatus::query()->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            foreach ($allStatusIds as $origemId) {
                $desiredDestinoIds = array_map(
                    static fn ($value): int => (int) $value,
                    $transitionsByOrigem[$origemId] ?? $transitionsByOrigem[(string) $origemId] ?? []
                );

                $existing = OrderStatusTransition::query()
                    ->where('status_origem_id', $origemId)
                    ->get()
                    ->keyBy(static fn (OrderStatusTransition $transition): int => (int) $transition->status_destino_id);

                foreach ($desiredDestinoIds as $destinoId) {
                    if ($destinoId === $origemId) {
                        continue;
                    }

                    $row = $existing->get($destinoId);

                    if ($row instanceof OrderStatusTransition) {
                        if (! $row->ativo) {
                            $row->forceFill(['ativo' => true, 'updated_at' => now()])->save();
                        }
                        continue;
                    }

                    OrderStatusTransition::query()->create([
                        'status_origem_id' => $origemId,
                        'status_destino_id' => $destinoId,
                        'ativo' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                foreach ($existing as $destinoId => $row) {
                    $destinoId = (int) $destinoId;

                    if (! in_array($destinoId, $desiredDestinoIds, true) && $row->ativo) {
                        $row->forceFill(['ativo' => false, 'updated_at' => now()])->save();
                    }
                }
            }
        });
    }
}
