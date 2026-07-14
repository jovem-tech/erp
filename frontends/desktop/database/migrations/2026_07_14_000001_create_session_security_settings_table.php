<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_security_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('idle_timeout_minutes')->default(120);
            $table->boolean('remember_me_enabled')->default(true);
            $table->unsignedInteger('remember_me_lifetime_days')->default(30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_security_settings');
    }
};
