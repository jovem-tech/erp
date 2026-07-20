<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('os_documento_arquivos') && ! Schema::hasColumn('os_documento_arquivos', 'managed_file_uuid')) {
            Schema::table('os_documento_arquivos', function (Blueprint $table): void {
                $table->uuid('managed_file_uuid')->nullable()->after('hash_sha256');
                $table->index('managed_file_uuid', 'ix_os_doc_arquivos_managed_uuid');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('os_documento_arquivos') && Schema::hasColumn('os_documento_arquivos', 'managed_file_uuid')) {
            Schema::table('os_documento_arquivos', function (Blueprint $table): void {
                $table->dropIndex('ix_os_doc_arquivos_managed_uuid');
                $table->dropColumn('managed_file_uuid');
            });
        }
    }
};
