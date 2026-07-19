<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite famílias de documentos criadas pelo usuário sem aceitar classes,
 * queries ou código arbitrário. Cada família personalizada herda apenas o
 * catálogo seguro de variáveis de um tipo-base registrado no motor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pdf_templates')) {
            return;
        }

        $addPersonalizado = ! Schema::hasColumn('pdf_templates', 'personalizado');
        $addTipoBase = ! Schema::hasColumn('pdf_templates', 'tipo_base_codigo');
        $addOrigem = ! Schema::hasColumn('pdf_templates', 'origem_template_id');

        Schema::table('pdf_templates', function (Blueprint $table) use ($addPersonalizado, $addTipoBase, $addOrigem): void {
            if ($addPersonalizado) {
                $table->boolean('personalizado')->default(false)->index('idx_pdf_templates_personalizado');
            }
            if ($addTipoBase) {
                $table->string('tipo_base_codigo', 80)->nullable()->index('idx_pdf_templates_tipo_base');
            }
            if ($addOrigem) {
                $table->unsignedBigInteger('origem_template_id')->nullable()->index('idx_pdf_templates_origem');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pdf_templates')) {
            return;
        }

        $dropOrigem = Schema::hasColumn('pdf_templates', 'origem_template_id');
        $dropTipoBase = Schema::hasColumn('pdf_templates', 'tipo_base_codigo');
        $dropPersonalizado = Schema::hasColumn('pdf_templates', 'personalizado');

        Schema::table('pdf_templates', function (Blueprint $table) use ($dropOrigem, $dropTipoBase, $dropPersonalizado): void {
            if ($dropOrigem) {
                $table->dropIndex('idx_pdf_templates_origem');
                $table->dropColumn('origem_template_id');
            }
            if ($dropTipoBase) {
                $table->dropIndex('idx_pdf_templates_tipo_base');
                $table->dropColumn('tipo_base_codigo');
            }
            if ($dropPersonalizado) {
                $table->dropIndex('idx_pdf_templates_personalizado');
                $table->dropColumn('personalizado');
            }
        });
    }
};
