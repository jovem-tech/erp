<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_security_settings', function (Blueprint $table) {
            $table->boolean('warn_on_close')->default(true)->after('remember_me_lifetime_days');
        });
    }

    public function down(): void
    {
        Schema::table('session_security_settings', function (Blueprint $table) {
            $table->dropColumn('warn_on_close');
        });
    }
};
