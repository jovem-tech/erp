<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cifra `equipamentos.senha_acesso` em repouso.
 *
 * A coluna guarda a senha/padrão de desbloqueio do aparelho do cliente — dado
 * pessoal sensível. Estava em texto puro: qualquer dump ou backup do banco
 * expunha todas de uma vez.
 *
 * A coluna precisa virar TEXT antes: um valor de 50 caracteres vira 288 depois
 * de cifrado, e a validação de entrada aceita até 255 (que cifrado passa de
 * 650) — não cabe no varchar(255) original.
 *
 * A conversão é idempotente: valores que já decifram são deixados como estão,
 * então rodar de novo (ou rodar depois de um deploy parcial) não corrompe nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('equipamentos', 'senha_acesso')) {
            return;
        }

        Schema::table('equipamentos', function (Blueprint $table): void {
            $table->text('senha_acesso')->nullable()->change();
        });

        DB::table('equipamentos')
            ->whereNotNull('senha_acesso')
            ->where('senha_acesso', '<>', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $current = (string) $row->senha_acesso;

                    if ($this->isEncrypted($current)) {
                        continue;
                    }

                    DB::table('equipamentos')
                        ->where('id', $row->id)
                        ->update(['senha_acesso' => Crypt::encryptString($current)]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('equipamentos', 'senha_acesso')) {
            return;
        }

        DB::table('equipamentos')
            ->whereNotNull('senha_acesso')
            ->where('senha_acesso', '<>', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $current = (string) $row->senha_acesso;

                    if (! $this->isEncrypted($current)) {
                        continue;
                    }

                    DB::table('equipamentos')
                        ->where('id', $row->id)
                        ->update(['senha_acesso' => Crypt::decryptString($current)]);
                }
            });

        Schema::table('equipamentos', function (Blueprint $table): void {
            $table->string('senha_acesso', 255)->nullable()->change();
        });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
