<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fechamento mensal do Anexo X — relatorio mensal das receitas brutas do MEI
 * (Resolucao CGSN 140/2018, art. 106).
 *
 * **Por que congelar, e nao recalcular sempre.** O relatorio e' assinado e
 * guardado pelo prazo decadencial. Um lancamento retroativo — uma OS corrigida
 * em novembro, uma devolucao registrada com atraso — mudaria em silencio um
 * documento que ja' foi impresso, assinado e arquivado. Reimprimir o mes
 * passado tem que devolver exatamente o que foi declarado, nao o que os dados
 * de hoje diriam.
 *
 * **Por que as dez linhas viram COLUNAS, e nao so' campos do JSON.** O
 * acumulado do ano-calendario (limite de R$ 81.000 do MEI) precisa somar doze
 * meses. Com coluna isso e' um `SUM(linha_x)`; com JSON seria desserializar
 * doze payloads a cada carga de tela. O `payload_json` continua guardando o
 * relatorio inteiro, drill-down incluso, para a reconferencia.
 *
 * **Por que `versao`, e nao uma linha por competencia.** Reabrir e fechar de
 * novo e' evento auditavel: quem reabriu, quando e por que. `caixa_sessoes`
 * reabre virando o status da mesma linha porque nao guarda retrato do
 * fechamento — aqui o retrato E' o produto, e sobrescreve-lo apagaria a
 * evidencia de que a primeira versao existiu. O fechamento vigente e' a maior
 * `versao` do par (competencia, regime) com `status = 'fechado'`.
 *
 * **Competencia e caixa fecham separadamente**: sao duas apuracoes diferentes
 * da mesma receita, e o MEI declara por uma delas. Fechar competencia nao pode
 * congelar caixa.
 *
 * Aditiva, como todas as migrations deste banco (compartilhado com o legado).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('anexo_x_fechamentos')) {
            return;
        }

        Schema::create('anexo_x_fechamentos', function (Blueprint $table): void {
            $table->id();

            $table->char('competencia', 7);          // 'YYYY-MM'
            $table->string('regime', 20);            // 'competencia' | 'caixa'
            $table->unsignedInteger('versao')->default(1);
            $table->string('status', 20)->default('fechado');   // 'fechado' | 'reaberto'

            // As dez linhas do formulario oficial, na numeracao da norma.
            foreach (['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x'] as $linha) {
                $table->decimal('linha_'.$linha, 14, 2)->default(0);
            }

            $table->decimal('deducao_descontos', 14, 2)->default(0);
            $table->decimal('deducao_devolucoes', 14, 2)->default(0);
            $table->decimal('acumulado_ano', 14, 2)->default(0);
            $table->decimal('limite_aplicado', 14, 2)->default(0);

            $table->longText('payload_json');
            $table->char('payload_hash_sha256', 64);

            // Versao do ERP que produziu o numero. Sem isso, uma divergencia
            // entre dois fechamentos nao distingue "o dado mudou" de "a regra
            // de apuracao mudou entre um deploy e outro".
            $table->string('app_versao', 20)->nullable();

            $table->dateTime('fechado_em');
            $table->unsignedBigInteger('fechado_por');
            $table->dateTime('reaberto_em')->nullable();
            $table->unsignedBigInteger('reaberto_por')->nullable();
            $table->text('motivo_reabertura')->nullable();

            $table->timestamps();

            $table->unique(['competencia', 'regime', 'versao'], 'ux_anexox_comp_regime_versao');
            $table->index(['competencia', 'regime', 'status'], 'idx_anexox_comp_regime_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anexo_x_fechamentos');
    }
};
