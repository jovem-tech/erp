<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'chat';

    public function up(): void
    {
        if (Schema::hasTable('mensagens') && ! Schema::hasColumn('mensagens', 'content_type')) {
            Schema::table('mensagens', function (Blueprint $table): void {
                $table->string('content_type', 30)->default('text')->after('conteudo');
            });
        }

        if (Schema::hasTable('mensagens')) {
            DB::connection('chat')
                ->table('mensagens')
                ->where(function ($query): void {
                    $query->whereNull('content_type')
                        ->orWhere('content_type', '');
                })
                ->update(['content_type' => 'text']);
        }

        if (! Schema::hasTable('mensagem_anexos')) {
            Schema::create('mensagem_anexos', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('mensagem_id');
                $table->string('attachment_type', 30)->default('document');
                $table->string('transfer_status', 20)->default('available');
                $table->string('disk', 40)->nullable();
                $table->string('storage_path', 255)->nullable();
                $table->string('original_name', 255)->nullable();
                $table->string('stored_name', 255)->nullable();
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('byte_size')->nullable();
                $table->string('provider_url', 500)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['mensagem_id'], 'idx_mensagem_anexos_mensagem');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mensagem_anexos');

        if (Schema::hasTable('mensagens') && Schema::hasColumn('mensagens', 'content_type')) {
            Schema::table('mensagens', function (Blueprint $table): void {
                $table->dropColumn('content_type');
            });
        }
    }
};
