<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orcamentos')) {
            Schema::table('orcamentos', function (Blueprint $table): void {
                if (! Schema::hasColumn('orcamentos', 'desconto_tipo')) {
                    $table->string('desconto_tipo', 20)->default('valor')->after('desconto');
                }

                if (! Schema::hasColumn('orcamentos', 'desconto_percentual')) {
                    $table->decimal('desconto_percentual', 8, 4)->nullable()->after('desconto_tipo');
                }

                if (! Schema::hasColumn('orcamentos', 'acrescimo_tipo')) {
                    $table->string('acrescimo_tipo', 20)->default('valor')->after('acrescimo');
                }

                if (! Schema::hasColumn('orcamentos', 'acrescimo_percentual')) {
                    $table->decimal('acrescimo_percentual', 8, 4)->nullable()->after('acrescimo_tipo');
                }
            });
        }

        if (Schema::hasTable('orcamento_itens')) {
            Schema::table('orcamento_itens', function (Blueprint $table): void {
                if (! Schema::hasColumn('orcamento_itens', 'desconto_tipo')) {
                    $table->string('desconto_tipo', 20)->default('valor')->after('desconto');
                }

                if (! Schema::hasColumn('orcamento_itens', 'desconto_percentual')) {
                    $table->decimal('desconto_percentual', 8, 4)->nullable()->after('desconto_tipo');
                }

                if (! Schema::hasColumn('orcamento_itens', 'acrescimo_tipo')) {
                    $table->string('acrescimo_tipo', 20)->default('valor')->after('acrescimo');
                }

                if (! Schema::hasColumn('orcamento_itens', 'acrescimo_percentual')) {
                    $table->decimal('acrescimo_percentual', 8, 4)->nullable()->after('acrescimo_tipo');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orcamento_itens')) {
            Schema::table('orcamento_itens', function (Blueprint $table): void {
                foreach (['desconto_percentual', 'desconto_tipo', 'acrescimo_percentual', 'acrescimo_tipo'] as $column) {
                    if (Schema::hasColumn('orcamento_itens', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('orcamentos')) {
            Schema::table('orcamentos', function (Blueprint $table): void {
                foreach (['desconto_percentual', 'desconto_tipo', 'acrescimo_percentual', 'acrescimo_tipo'] as $column) {
                    if (Schema::hasColumn('orcamentos', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
