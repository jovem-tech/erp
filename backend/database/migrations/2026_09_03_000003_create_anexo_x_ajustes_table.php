<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes manuais declarados no Anexo X — Res. CGSN 140/2018, art. 106.
 *
 * **Por que existe.** O Anexo X tem que declarar TODA a receita bruta do mês, e
 * o relatório do sistema só conhece o que passou pelo ERP. Uma venda cobrada em
 * dinheiro e não lançada é receita bruta do mesmo jeito: sem um lugar para
 * declará-la, o operador só teria a alternativa de preencher o formulário à mão
 * fora do sistema — perdendo o encerramento, o hash e todo o rastro.
 *
 * **Por que não é sobrescrever o valor calculado.** O ajuste é um LANÇAMENTO
 * somado ao que foi apurado, e o apurado continua visível ao lado dele. A tela
 * mostra a tríade Calculado / Ajuste / Declarado. Sobrescrever apagaria a única
 * informação capaz de responder "de onde veio esse número?".
 *
 * **Tabela própria, e não coluna em `anexo_x_fechamentos`:** o ajuste vive num
 * mês ABERTO, e `anexo_x_fechamentos` só tem linha para mês que já foi fechado
 * ao menos uma vez.
 *
 * **Sem unique em (competencia, regime, linha):** dois lançamentos fora do
 * sistema na mesma linha são dois fatos, cada um com seu motivo e seu autor. A
 * apuração soma; a tela lista os dois.
 *
 * **`valor` com sinal, e não `tipo` + `valor`:** a operação inteira é `SUM()`.
 * Um enum `tipo` obrigaria um `CASE` em todo agregado, inclusive no acumulado
 * do ano.
 *
 * **Sem UPDATE.** Corrigir é cancelar e lançar de novo. O cancelado continua
 * listado, riscado, com quem cancelou e por quê — o produto aqui é a trilha,
 * e um UPDATE apagaria quem declarou o quê e quando.
 *
 * Aditiva, como todas as migrations deste banco (compartilhado com o legado).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('anexo_x_ajustes')) {
            return;
        }

        Schema::create('anexo_x_ajustes', function (Blueprint $table): void {
            $table->id();

            $table->char('competencia', 7);      // 'YYYY-MM'
            $table->string('regime', 20);        // 'competencia' | 'caixa'

            // Só as SEIS linhas-folha do formulário: i, ii, iv, v, vii, viii.
            // III, VI, IX e X sao somas das demais — ajustar uma delas exigiria
            // ratear de volta para as folhas, e esse rateio e' decisao fiscal
            // (a receita extra teve documento ou nao?) que so' o operador sabe.
            $table->string('linha', 4);

            $table->decimal('valor', 14, 2);
            $table->text('motivo');

            $table->dateTime('criado_em');
            $table->unsignedBigInteger('criado_por');
            $table->dateTime('cancelado_em')->nullable();
            $table->unsignedBigInteger('cancelado_por')->nullable();
            $table->text('motivo_cancelamento')->nullable();

            // Mesma razao da coluna homonima em `anexo_x_fechamentos`: uma
            // divergencia entre dois periodos nao distingue "o dado mudou" de
            // "a regra de apuracao mudou entre um deploy e outro".
            $table->string('app_versao', 20)->nullable();

            $table->timestamps();

            $table->index(['competencia', 'regime', 'cancelado_em'], 'idx_anexox_aj_comp_regime');
            $table->index('competencia', 'idx_anexox_aj_ano');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anexo_x_ajustes');
    }
};
