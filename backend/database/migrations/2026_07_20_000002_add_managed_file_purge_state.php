<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('managed_files') || Schema::hasColumn('managed_files', 'purged_at')) {
            return;
        }

        Schema::table('managed_files', function (Blueprint $table): void {
            $table->dateTime('purged_at', 6)->nullable()->after('trashed_at');
            $table->index(['lifecycle_status', 'trashed_at'], 'ix_mf_lifecycle_trashed');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('managed_files') || ! Schema::hasColumn('managed_files', 'purged_at')) {
            return;
        }

        Schema::table('managed_files', function (Blueprint $table): void {
            $table->dropIndex('ix_mf_lifecycle_trashed');
            $table->dropColumn('purged_at');
        });
    }
};
