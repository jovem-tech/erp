<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            // Modo de navegacao do shell, por usuario: 'fixed' mantem a sidebar
            // sempre presente (comportamento historico) e 'drawer' esconde a
            // sidebar atras do botao sanduiche da navbar. O default preserva o
            // que todo mundo ja via antes desta migration.
            $table->string('navigation_mode', 16)->default('fixed')->after('desktop_theme');

            // Lista ordenada de nomes de rota favoritados, em JSON. Nao ha
            // tabela separada porque um favorito nao tem atributo proprio: e'
            // so' um ponteiro para uma pagina ja descrita em DesktopNavigation.
            $table->text('favorites')->nullable()->after('navigation_mode');
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->dropColumn(['navigation_mode', 'favorites']);
        });
    }
};
