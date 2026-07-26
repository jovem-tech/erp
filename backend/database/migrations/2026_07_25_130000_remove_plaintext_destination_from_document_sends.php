<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('os_documento_envios')
            || ! Schema::hasColumn('os_documento_envios', 'destino_criptografado')
            || ! Schema::hasColumn('os_documento_envios', 'metadados_json')) {
            return;
        }

        DB::table('os_documento_envios')
            ->whereNotNull('destino_criptografado')
            ->where('destino_criptografado', '<>', '')
            ->orderBy('id')
            ->chunkById(100, function ($sends): void {
                foreach ($sends as $send) {
                    $metadata = json_decode((string) ($send->metadados_json ?? ''), true);
                    if (! is_array($metadata) || ! array_key_exists('destination_value', $metadata)) {
                        continue;
                    }

                    unset($metadata['destination_value']);

                    DB::table('os_documento_envios')
                        ->where('id', (int) $send->id)
                        ->update([
                            'metadados_json' => json_encode(
                                $metadata,
                                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                            ),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Irreversível por segurança: dados pessoais removidos em texto puro não
        // devem ser recriados. A cópia criptografada permanece preservada.
    }
};
