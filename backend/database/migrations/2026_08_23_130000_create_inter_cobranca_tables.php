<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabelas da cobranca Pix do Banco Inter.
 *
 * Tres tabelas com papeis distintos, de proposito:
 *
 * - `inter_cobrancas`   o que emitimos
 * - `inter_liquidacoes` o que o banco confirmou que foi pago
 * - `inter_eventos`     tudo que aconteceu, append-only
 *
 * A separacao entre a primeira e a segunda e' o que permite pagamento parcial
 * e multiplos Pix na mesma cobranca sem gambiarra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inter_cobrancas')) {
            Schema::create('inter_cobrancas', function (Blueprint $table): void {
                $table->id();

                // Gravado sempre, mesmo com um unico provedor possivel hoje:
                // sem isso nao ha como saber retroativamente de onde veio cada
                // cobranca quando existir um segundo provedor ou tenant.
                $table->string('provider', 20)->default('inter');

                // txid do Pix: 26 a 35 caracteres alfanumericos, gerado por nos.
                $table->string('txid', 35)->unique();
                $table->string('conta_corrente', 30)->nullable();

                // De onde a cobranca nasceu. Todos nullable porque uma cobranca
                // avulsa (sem OS nem orcamento) e' legitima.
                $table->unsignedBigInteger('financeiro_id')->nullable();
                $table->unsignedBigInteger('os_id')->nullable();
                $table->unsignedBigInteger('orcamento_id')->nullable();

                $table->decimal('valor', 12, 2);

                // Status do Pix conforme o BACEN: ATIVA, CONCLUIDA,
                // REMOVIDA_PELO_USUARIO_RECEBEDOR, REMOVIDA_PELO_PSP.
                // Guardado como veio, sem traduzir: traduzir aqui esconderia um
                // estado novo do padrao atras de um "desconhecido".
                $table->string('status', 40)->default('ATIVA');

                $table->dateTime('expira_em')->nullable();

                // Copia-e-cola do Pix. A IMAGEM do QR nao e' guardada de
                // proposito: ela e' derivavel deste payload e engordaria o dump
                // diario do banco sem acrescentar informacao.
                $table->text('pix_copia_e_cola')->nullable();
                $table->string('location', 255)->nullable();

                $table->json('solicitacao_payload')->nullable();
                $table->unsignedBigInteger('criado_por_usuario_id')->nullable();
                $table->dateTime('cancelada_em')->nullable();
                $table->timestamps();

                $table->index(['financeiro_id'], 'idx_inter_cob_financeiro');
                $table->index(['os_id'], 'idx_inter_cob_os');
                // Indice do comando de conciliacao: "cobrancas abertas que ainda
                // nao expiraram".
                $table->index(['status', 'expira_em'], 'idx_inter_cob_status_expira');
            });
        }

        if (! Schema::hasTable('inter_liquidacoes')) {
            Schema::create('inter_liquidacoes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('inter_cobranca_id');

                /*
                 * ESTA e' a chave de idempotencia da integracao.
                 *
                 * O endToEndId identifica unicamente um Pix no SPI. Com UNIQUE
                 * aqui, duas entregas concorrentes do mesmo pagamento fazem a
                 * segunda bater em violacao de constraint — e o banco de dados
                 * resolve a corrida, nao a ordem de execucao do PHP.
                 *
                 * O caminho manual (financeiro_movimentos.documento_ref) NAO
                 * serve: nao tem indice unico e ja carrega outra semantica.
                 */
                $table->string('e2eid', 40)->unique();

                $table->decimal('valor', 12, 2);
                $table->dateTime('horario')->nullable();

                // Preenchido DEPOIS da baixa. Nulo aqui significa "Pix
                // confirmado pelo banco mas ainda nao lancado" — estado que
                // precisa ser visivel, nao escondido.
                $table->unsignedBigInteger('financeiro_movimento_id')->nullable();

                $table->json('payload')->nullable();
                $table->timestamps();

                $table->index(['inter_cobranca_id'], 'idx_inter_liq_cobranca');
                $table->index(['financeiro_movimento_id'], 'idx_inter_liq_movimento');

                $table->foreign('inter_cobranca_id', 'fk_inter_liq_cobranca')
                    ->references('id')->on('inter_cobrancas')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('inter_eventos')) {
            Schema::create('inter_eventos', function (Blueprint $table): void {
                $table->id();
                $table->string('txid', 35)->nullable();
                $table->string('e2eid', 40)->nullable();

                $table->string('evento', 60);
                $table->string('nivel', 20)->default('info');

                // webhook | polling | manual — de onde veio o gatilho.
                $table->string('origem', 20)->default('polling');

                $table->unsignedSmallInteger('http_status')->nullable();

                // O que decidimos e por que. Sem isto, investigar uma baixa
                // errada vira arqueologia de log.
                $table->string('decisao', 40)->nullable();
                $table->string('motivo', 500)->nullable();

                // Corpo recebido (dados do pagador mascarados na escrita) e
                // corpo da reconsulta ao banco, que e' a fonte da verdade.
                $table->json('payload_recebido')->nullable();
                $table->json('payload_reconsulta')->nullable();

                $table->dateTime('created_at')->nullable();

                $table->index(['txid'], 'idx_inter_ev_txid');
                $table->index(['e2eid'], 'idx_inter_ev_e2eid');
                $table->index(['created_at'], 'idx_inter_ev_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inter_eventos');
        Schema::dropIfExists('inter_liquidacoes');
        Schema::dropIfExists('inter_cobrancas');
    }
};
