<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'chat';

    public function up(): void
    {
        if (Schema::hasTable('mensagem_anexos') && ! Schema::hasColumn('mensagem_anexos', 'managed_file_uuid')) {
            Schema::table('mensagem_anexos', function (Blueprint $table): void {
                $table->uuid('managed_file_uuid')->nullable()->after('storage_path');
                $table->index('managed_file_uuid', 'idx_mensagem_anexos_managed_uuid');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mensagem_anexos') && Schema::hasColumn('mensagem_anexos', 'managed_file_uuid')) {
            Schema::table('mensagem_anexos', function (Blueprint $table): void {
                $table->dropIndex('idx_mensagem_anexos_managed_uuid');
                $table->dropColumn('managed_file_uuid');
            });
        }
    }
};
