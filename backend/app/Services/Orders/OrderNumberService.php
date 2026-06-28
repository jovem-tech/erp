<?php

namespace App\Services\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderNumberService
{
    public function nextNumber(): string
    {
        return DB::transaction(function (): string {
            if (! Schema::hasTable('configuracoes')) {
                return $this->fallbackNumber();
            }

            $rows = DB::table('configuracoes')
                ->whereIn('chave', ['os_prefixo', 'os_ano', 'os_mes', 'os_ultimo_numero'])
                ->lockForUpdate()
                ->get()
                ->keyBy('chave');

            $prefix = trim((string) ($rows['os_prefixo']->valor ?? 'OS'));
            $prefix = $prefix !== '' ? $prefix : 'OS';

            $currentYear = now()->format('y');
            $currentMonth = now()->format('m');
            $storedYear = trim((string) ($rows['os_ano']->valor ?? ''));
            $storedMonth = trim((string) ($rows['os_mes']->valor ?? ''));

            if ($storedYear !== $currentYear || $storedMonth !== $currentMonth) {
                $sequence = 1;
                $this->upsertConfig('os_ano', $currentYear, 'numero');
                $this->upsertConfig('os_mes', $currentMonth, 'numero');
            } else {
                $sequence = (int) ($rows['os_ultimo_numero']->valor ?? 0) + 1;
            }

            $this->upsertConfig('os_ultimo_numero', (string) $sequence, 'numero');

            return $prefix . $currentYear . $currentMonth . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        });
    }

    private function upsertConfig(string $key, string $value, string $type): void
    {
        $exists = DB::table('configuracoes')
            ->where('chave', $key)
            ->exists();

        if ($exists) {
            DB::table('configuracoes')
                ->where('chave', $key)
                ->update([
                    'valor' => $value,
                    'tipo' => $type,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('configuracoes')->insert([
            'chave' => $key,
            'valor' => $value,
            'tipo' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fallbackNumber(): string
    {
        $sequence = ((int) Order::query()->count()) + 1;

        return 'OS' . now()->format('ym') . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
