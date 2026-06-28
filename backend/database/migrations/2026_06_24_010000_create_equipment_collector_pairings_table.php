<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_collector_pairings', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->string('code', 32)->unique();
            $table->longText('snapshot_payload')->nullable();
            $table->longText('snapshot_normalized')->nullable();
            $table->string('source', 120)->nullable();
            $table->string('agent_version', 60)->nullable();
            $table->string('hostname', 120)->nullable();
            $table->dateTime('snapshot_received_at')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('user_id')->references('id')->on('usuarios')->nullOnDelete();
            $table->index(['code', 'expires_at'], 'idx_equipment_collector_pairings_code_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_collector_pairings');
    }
};
