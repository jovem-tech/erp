<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro do que a baixa entregou FORA do pacote de manutenção contratado.
 *
 * Quando o cliente aprova um nível (Básica/Avançada/Completa), o orçamento
 * promete garantia, formas de pagamento, parcelamento sem juros e entrega em
 * domicílio daquele nível. A baixa passou a ratificar essas quatro condições
 * (OrderClosureService::resolvePackageRatification) e a exigir motivo quando
 * o encerramento sai delas.
 *
 * Colunas próprias, e não só o histórico de eventos, pelo mesmo motivo de
 * `desconto_baixa_*`: o orçamento convertido AINDA PODE SER EDITADO depois
 * (BudgetWorkflowService::updateConvertedBudget), então a promessa muda sob os
 * pés do registro. Aqui fica o que valia no instante do encerramento, e fica
 * consultável — "quais OS entreguei fora do que vendi" é uma pergunta de
 * gestão, não uma varredura de timeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('os') || Schema::hasColumn('os', 'pacote_desvios')) {
            return;
        }

        $after = Schema::hasColumn('os', 'desconto_baixa_concedido_em')
            ? 'desconto_baixa_concedido_em'
            : null;

        Schema::table('os', function (Blueprint $table) use ($after): void {
            // CSV dos códigos (garantia, forma_pagamento, parcelas,
            // entrega_domicilio) — a lista é curta, fechada e sempre lida
            // inteira; uma tabela filha aqui seria peso sem ganho.
            $coluna = $table->string('pacote_desvios', 120)->nullable();
            if ($after !== null) {
                $coluna->after($after);
            }

            $table->string('pacote_desvio_motivo', 500)->nullable()->after('pacote_desvios');
            $table->unsignedBigInteger('pacote_desvio_por')->nullable()->after('pacote_desvio_motivo');
            $table->dateTime('pacote_desvio_em')->nullable()->after('pacote_desvio_por');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('os') || ! Schema::hasColumn('os', 'pacote_desvios')) {
            return;
        }

        Schema::table('os', function (Blueprint $table): void {
            $table->dropColumn([
                'pacote_desvios',
                'pacote_desvio_motivo',
                'pacote_desvio_por',
                'pacote_desvio_em',
            ]);
        });
    }
};
