<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('financeiro_anexos')) {
            return;
        }

        Schema::table('financeiro_anexos', function (Blueprint $table): void {
            if (! Schema::hasColumn('financeiro_anexos', 'managed_file_uuid')) {
                $table->uuid('managed_file_uuid')->nullable()->after('usuario_id');
                $table->unique('managed_file_uuid', 'uq_financeiro_anexos_managed_uuid');
            }
            if (! Schema::hasColumn('financeiro_anexos', 'file_manager_synced_at')) {
                $table->dateTime('file_manager_synced_at', 6)->nullable()->after('managed_file_uuid');
            }
            if (! Schema::hasColumn('financeiro_anexos', 'deleted_at')) {
                $table->softDeletes('deleted_at', 6);
            }
        });

        Schema::table('financeiro_anexos', function (Blueprint $table): void {
            $table->index(['financeiro_id', 'deleted_at', 'id'], 'ix_financeiro_anexos_parent_lifecycle');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('financeiro_anexos')) {
            return;
        }

        Schema::table('financeiro_anexos', function (Blueprint $table): void {
            if (Schema::hasColumn('financeiro_anexos', 'deleted_at')) {
                $table->dropIndex('ix_financeiro_anexos_parent_lifecycle');
                $table->dropSoftDeletes('deleted_at');
            }
            if (Schema::hasColumn('financeiro_anexos', 'file_manager_synced_at')) {
                $table->dropColumn('file_manager_synced_at');
            }
            if (Schema::hasColumn('financeiro_anexos', 'managed_file_uuid')) {
                $table->dropUnique('uq_financeiro_anexos_managed_uuid');
                $table->dropColumn('managed_file_uuid');
            }
        });
    }
};
