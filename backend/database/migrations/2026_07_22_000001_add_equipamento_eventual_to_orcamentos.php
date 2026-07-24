<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipamento eventual em orçamentos avulsos — espelha o cliente eventual
 * (cliente_nome_avulso). Um cliente eventual (ou um cliente cadastrado cujo
 * aparelho nunca veio à loja) não tem equipamento no banco; estes campos livres
 * identificam o aparelho e, na geração da OS, pré-preenchem o cadastro do
 * equipamento. A cor reutiliza a coluna já existente `equipamento_cor`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orcamentos')) {
            return;
        }

        Schema::table('orcamentos', function (Blueprint $table): void {
            if (! Schema::hasColumn('orcamentos', 'envolve_equipamento')) {
                $table->boolean('envolve_equipamento')->default(true)->after('equipamento_id');
            }

            if (! Schema::hasColumn('orcamentos', 'equipamento_tipo_avulso')) {
                $table->string('equipamento_tipo_avulso', 120)->nullable()->after('equipamento_modelo_id');
            }

            if (! Schema::hasColumn('orcamentos', 'equipamento_marca_avulso')) {
                $table->string('equipamento_marca_avulso', 120)->nullable()->after('equipamento_tipo_avulso');
            }

            if (! Schema::hasColumn('orcamentos', 'equipamento_modelo_avulso')) {
                $table->string('equipamento_modelo_avulso', 120)->nullable()->after('equipamento_marca_avulso');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orcamentos')) {
            return;
        }

        Schema::table('orcamentos', function (Blueprint $table): void {
            foreach ([
                'equipamento_modelo_avulso',
                'equipamento_marca_avulso',
                'equipamento_tipo_avulso',
                'envolve_equipamento',
            ] as $column) {
                if (Schema::hasColumn('orcamentos', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
