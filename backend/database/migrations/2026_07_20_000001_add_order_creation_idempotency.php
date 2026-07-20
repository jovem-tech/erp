<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('os')) {
            return;
        }

        $missingRequestId = ! Schema::hasColumn('os', 'creation_request_id');
        $missingFingerprint = ! Schema::hasColumn('os', 'creation_request_fingerprint');
        $missingRequestedBy = ! Schema::hasColumn('os', 'creation_requested_by');

        Schema::table('os', function (Blueprint $table) use ($missingRequestId, $missingFingerprint, $missingRequestedBy): void {
            if ($missingRequestId) {
                $table->uuid('creation_request_id')->nullable()->after('numero_os');
            }
            if ($missingFingerprint) {
                $table->char('creation_request_fingerprint', 64)->nullable()->after('creation_request_id');
            }
            if ($missingRequestedBy) {
                $table->unsignedBigInteger('creation_requested_by')->nullable()->after('creation_request_fingerprint');
            }
        });

        if (! $this->hasIndex('os', 'ux_os_creation_request_id')) {
            Schema::table('os', function (Blueprint $table): void {
                $table->unique('creation_request_id', 'ux_os_creation_request_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('os')) {
            return;
        }

        if ($this->hasIndex('os', 'ux_os_creation_request_id')) {
            Schema::table('os', function (Blueprint $table): void {
                $table->dropUnique('ux_os_creation_request_id');
            });
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('os', 'creation_request_id') ? 'creation_request_id' : null,
            Schema::hasColumn('os', 'creation_request_fingerprint') ? 'creation_request_fingerprint' : null,
            Schema::hasColumn('os', 'creation_requested_by') ? 'creation_requested_by' : null,
        ]));

        Schema::table('os', function (Blueprint $table) use ($columns): void {
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(static fn (object $index): bool => (string) ($index->name ?? '') === $indexName);
        }

        return DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$indexName]) !== [];
    }
};
