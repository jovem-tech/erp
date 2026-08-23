<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Concede o módulo `backups` ao grupo de administração total.
 *
 * A migração 000002 seguia o padrão herdado do gerenciador de arquivos, que
 * semeia apenas o grupo chamado exatamente "Administrador". Neste sistema
 * existe também "super administrador" — o grupo supremo, que pode e deve fazer
 * qualquer coisa — e ele ficava de fora de todo módulo novo.
 *
 * Idempotente (insertOrIgnore): rodar em ambiente onde a 000002 já corrigida
 * cobriu os dois grupos é inofensivo.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const ADMIN_GROUPS = ['administrador', 'super administrador'];

    /** @var array<int, string> */
    private const PERMISSIONS = ['visualizar', 'criar', 'baixar', 'excluir', 'restaurar', 'administrar'];

    public function up(): void
    {
        if (! Schema::hasTable('modulos')
            || ! Schema::hasTable('grupos')
            || ! Schema::hasTable('permissoes')
            || ! Schema::hasTable('grupo_permissoes')) {
            return;
        }

        $moduleId = (int) DB::table('modulos')->where('slug', 'backups')->value('id');

        if ($moduleId <= 0) {
            return;
        }

        $permissionIds = DB::table('permissoes')
            ->whereIn('slug', self::PERMISSIONS)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $groupIds = DB::table('grupos')
            ->whereIn(DB::raw('LOWER(TRIM(nome))'), self::ADMIN_GROUPS)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $rows = [];
        foreach ($groupIds as $groupId) {
            foreach ($permissionIds as $permissionId) {
                $rows[] = [
                    'grupo_id' => $groupId,
                    'modulo_id' => $moduleId,
                    'permissao_id' => $permissionId,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('grupo_permissoes')->insertOrIgnore($rows);
        }

        Cache::flush();
    }

    public function down(): void
    {
        // Sem down: remover permissões de administração total do grupo supremo
        // causaria mais estrago do que a reversão pretende evitar.
    }
};
