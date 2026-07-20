<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('managed_files')) {
            Schema::create('managed_files', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('operation_key', 120)->nullable()->unique();
                $table->string('original_name');
                $table->string('safe_download_name');
                $table->string('extension', 20);
                $table->string('declared_mime_type', 120)->nullable();
                $table->string('detected_mime_type', 120);
                $table->unsignedBigInteger('size_bytes');
                $table->char('sha256', 64);
                $table->string('storage_disk', 40);
                $table->string('storage_key', 500);
                $table->string('category', 80);
                $table->string('origin', 40);
                $table->string('lifecycle_status', 30)->default('active');
                $table->string('integrity_status', 30)->default('valid');
                $table->string('security_status', 30)->default('clean');
                $table->string('migration_status', 30)->default('native');
                $table->string('visibility', 30)->default('private');
                $table->string('confidentiality', 40)->default('confidential');
                $table->integer('created_by')->nullable();
                $table->dateTime('archived_at', 6)->nullable();
                $table->dateTime('trashed_at', 6)->nullable();
                $table->dateTime('quarantined_at', 6)->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps(6);

                $table->unique(['storage_disk', 'storage_key'], 'uq_mf_disk_key');
                $table->index(['sha256', 'size_bytes'], 'ix_mf_hash_size');
                $table->index(['category', 'created_at'], 'ix_mf_category_created');
                $table->index(['lifecycle_status', 'security_status', 'created_at'], 'ix_mf_states_created');
                $table->index(['migration_status', 'created_at'], 'ix_mf_migration_created');
            });
        }

        if (! Schema::hasTable('managed_file_links')) {
            Schema::create('managed_file_links', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('file_id')->constrained('managed_files')->restrictOnDelete();
                $table->string('subject_type', 80);
                $table->unsignedBigInteger('subject_id');
                $table->string('relation', 80);
                $table->boolean('is_current')->default(true);
                $table->integer('created_by')->nullable();
                $table->dateTime('unlinked_at', 6)->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamp('created_at', 6)->useCurrent();

                $table->unique(['file_id', 'subject_type', 'subject_id', 'relation'], 'uq_mfl_identity');
                $table->index(['subject_type', 'subject_id', 'relation', 'unlinked_at'], 'ix_mfl_subject');
                $table->index(['file_id', 'unlinked_at'], 'ix_mfl_file');
            });
        }

        if (! Schema::hasTable('managed_file_legacy_aliases')) {
            Schema::create('managed_file_legacy_aliases', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('file_id')->constrained('managed_files')->restrictOnDelete();
                $table->string('legacy_disk', 40);
                $table->string('legacy_path', 500);
                $table->char('path_hash', 64);
                $table->string('source_table', 80)->nullable();
                $table->string('source_column', 80)->nullable();
                $table->string('source_record_id', 120)->nullable();
                $table->dateTime('verified_at', 6)->nullable();
                $table->dateTime('retired_at', 6)->nullable();
                $table->timestamp('created_at', 6)->useCurrent();

                $table->unique(['legacy_disk', 'path_hash'], 'uq_mfa_disk_path_hash');
                $table->index(['file_id', 'retired_at'], 'ix_mfa_file');
                $table->index(['source_table', 'source_record_id'], 'ix_mfa_source');
            });
        }

        if (! Schema::hasTable('managed_file_events')) {
            Schema::create('managed_file_events', function (Blueprint $table): void {
                $table->id();
                $table->uuid('event_uuid')->unique();
                $table->foreignId('file_id')->nullable()->constrained('managed_files')->nullOnDelete();
                $table->integer('actor_id')->nullable();
                $table->string('action', 60);
                $table->string('result', 20);
                $table->string('module', 80)->nullable();
                $table->char('ip_fingerprint', 64)->nullable();
                $table->char('user_agent_fingerprint', 64)->nullable();
                $table->string('correlation_id', 100)->nullable();
                $table->json('context_json')->nullable();
                $table->timestamp('created_at', 6)->useCurrent();

                $table->index(['file_id', 'created_at'], 'ix_mfe_file_created');
                $table->index(['action', 'created_at'], 'ix_mfe_action_created');
                $table->index(['module', 'created_at'], 'ix_mfe_module_created');
                $table->index('correlation_id', 'ix_mfe_correlation');
            });
        }

        if (! Schema::hasTable('file_scan_runs')) {
            Schema::create('file_scan_runs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('process_name', 80);
                $table->string('mode', 20)->default('dry_run');
                $table->char('roots_fingerprint', 64);
                $table->string('status', 30)->default('pending');
                $table->json('checkpoint_json')->nullable();
                $table->unsignedBigInteger('processed_count')->default(0);
                $table->unsignedBigInteger('skipped_count')->default(0);
                $table->unsignedBigInteger('finding_count')->default(0);
                $table->unsignedBigInteger('failed_count')->default(0);
                $table->integer('started_by')->nullable();
                $table->dateTime('started_at', 6)->nullable();
                $table->dateTime('heartbeat_at', 6)->nullable();
                $table->dateTime('completed_at', 6)->nullable();
                $table->timestamps(6);

                $table->index(['status', 'created_at'], 'ix_fsr_status_created');
                $table->index(['process_name', 'created_at'], 'ix_fsr_process_created');
            });
        }

        if (! Schema::hasTable('file_scan_findings')) {
            Schema::create('file_scan_findings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('scan_run_id')->constrained('file_scan_runs')->cascadeOnDelete();
                $table->string('finding_type', 50);
                $table->string('severity', 20);
                $table->char('path_hash', 64)->nullable();
                $table->string('restricted_path', 500)->nullable();
                $table->foreignId('file_id')->nullable()->constrained('managed_files')->nullOnDelete();
                $table->json('source_reference_json')->nullable();
                $table->json('evidence_json')->nullable();
                $table->string('resolution_status', 30)->default('open');
                $table->integer('resolved_by')->nullable();
                $table->dateTime('resolved_at', 6)->nullable();
                $table->timestamps(6);

                $table->index(['scan_run_id', 'severity'], 'ix_fsf_run_severity');
                $table->index(['finding_type', 'resolution_status', 'created_at'], 'ix_fsf_type_status');
                $table->index('file_id', 'ix_fsf_file');
                $table->index('path_hash', 'ix_fsf_path_hash');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('file_scan_findings');
        Schema::dropIfExists('file_scan_runs');
        Schema::dropIfExists('managed_file_events');
        Schema::dropIfExists('managed_file_legacy_aliases');
        Schema::dropIfExists('managed_file_links');
        Schema::dropIfExists('managed_files');
    }
};
