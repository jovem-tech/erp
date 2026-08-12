<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de Vendas (balcão/PDV) — ver specs/027-vendas-balcao-pdv/spec.md.
 *
 * Sem FK física para tabelas legadas (`clientes`, `pecas`, `servicos`, `usuarios`,
 * `os`): o schema legado não vive no repositório e o restante dos módulos novos
 * segue a mesma regra — integridade referencial fica na aplicação.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vendas')) {
            Schema::create('vendas', function (Blueprint $table): void {
                $table->id();
                $table->string('numero', 40)->unique();
                $table->string('status', 30)->default('concluida');
                $table->string('canal', 30)->default('balcao');

                // Cliente cadastrado OU consumidor final (mesmo padrão de `orcamentos`).
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->string('cliente_nome_avulso', 160)->nullable();
                $table->string('cliente_documento_avulso', 20)->nullable();
                $table->string('telefone_contato', 30)->nullable();
                $table->string('email_contato', 120)->nullable();

                $table->unsignedBigInteger('vendedor_id')->nullable();
                $table->unsignedBigInteger('criado_por')->nullable();
                $table->unsignedBigInteger('cancelado_por')->nullable();

                // Vínculo OPCIONAL com OS (acessório levado junto ao aparelho em conserto).
                $table->unsignedBigInteger('os_id')->nullable();

                $table->date('data_venda');
                $table->dateTime('concluida_em')->nullable();

                // Ajustes em R$ ou %, com os MESMOS tipos de
                // 2026_07_03_000001_add_adjustment_modes_to_orcamentos_tables.php,
                // para reaproveitar a lógica de resolução de desconto/acréscimo.
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('desconto', 12, 2)->default(0);
                $table->string('desconto_tipo', 20)->default('valor');
                $table->decimal('desconto_percentual', 8, 4)->nullable();
                $table->decimal('acrescimo', 12, 2)->default(0);
                $table->string('acrescimo_tipo', 20)->default('valor');
                $table->decimal('acrescimo_percentual', 8, 4)->nullable();
                $table->decimal('total', 12, 2)->default(0);

                // Custo e margem MATERIALIZADOS: sem isso, editar `pecas.preco_custo`
                // reescreveria retroativamente a margem de vendas já fechadas.
                $table->decimal('custo_total', 12, 2)->default(0);
                $table->decimal('margem_valor', 12, 2)->default(0);
                $table->decimal('margem_percentual', 7, 2)->default(0);

                // Cache denormalizado do pagamento — a verdade continua em
                // FinanceiroService::movementSummary(). Evita JOIN triplo na listagem.
                $table->decimal('valor_pago', 12, 2)->default(0);
                $table->string('status_pagamento', 20)->default('pendente');
                $table->unsignedBigInteger('financeiro_id')->nullable();

                // Idempotência da baixa de estoque + marca de saldo insuficiente.
                $table->dateTime('estoque_baixado_em')->nullable();
                $table->boolean('estoque_divergente')->default(false);

                $table->text('observacoes')->nullable();
                $table->dateTime('cancelado_em')->nullable();
                $table->text('motivo_cancelamento')->nullable();

                // Duplo clique em "Finalizar" é o defeito nº 1 de um PDV.
                $table->uuid('creation_request_id')->nullable();
                $table->char('creation_request_fingerprint', 64)->nullable();
                $table->unsignedBigInteger('creation_requested_by')->nullable();

                $table->timestamps();

                $table->unique('creation_request_id', 'ux_vendas_creation_request_id');
                $table->index(['status', 'data_venda'], 'idx_vendas_status_data');
                $table->index(['cliente_id', 'data_venda'], 'idx_vendas_cliente_data');
                $table->index(['vendedor_id', 'data_venda'], 'idx_vendas_vendedor_data');
                $table->index(['data_venda', 'id'], 'idx_vendas_data_id');
                $table->index('financeiro_id', 'idx_vendas_financeiro');
                $table->index('os_id', 'idx_vendas_os');
            });
        }

        if (! Schema::hasTable('venda_itens')) {
            Schema::create('venda_itens', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('venda_id');

                // Mesmo desenho de `orcamento_itens`: tipo + referência solta
                // (peca|servico|avulso). `referencia_id` não é FK de propósito.
                $table->string('tipo_item', 30)->default('peca');
                $table->unsignedBigInteger('referencia_id')->nullable();

                // Congelados: renomear/encerrar uma peça não reescreve o histórico.
                $table->string('codigo_snapshot', 120)->nullable();
                $table->string('descricao', 255);

                $table->decimal('quantidade', 10, 3)->default(1);
                $table->decimal('valor_unitario', 12, 2)->default(0);
                $table->decimal('desconto', 12, 2)->default(0);
                $table->string('desconto_tipo', 20)->default('valor');
                $table->decimal('desconto_percentual', 8, 4)->nullable();
                $table->decimal('acrescimo', 12, 2)->default(0);
                $table->string('acrescimo_tipo', 20)->default('valor');
                $table->decimal('acrescimo_percentual', 8, 4)->nullable();
                $table->decimal('total', 12, 2)->default(0);

                $table->decimal('custo_unitario', 12, 2)->default(0);
                $table->decimal('custo_total', 12, 2)->default(0);
                $table->decimal('preco_venda_referencia', 12, 2)->nullable();

                // Flag EXPLÍCITA, não derivada de tipo_item === 'peca':
                // permite brinde/consignado/saldo divergente sem mexer no estoque.
                $table->boolean('baixa_estoque')->default(false);

                $table->integer('ordem')->default(0);
                $table->text('observacoes')->nullable();
                $table->timestamps();

                $table->index(['venda_id', 'ordem'], 'idx_venda_itens_venda_ordem');
                $table->index(['tipo_item', 'referencia_id'], 'idx_venda_itens_referencia');
            });
        }

        if (! Schema::hasTable('venda_pagamentos')) {
            Schema::create('venda_pagamentos', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('venda_id');

                // VARCHAR, nunca ENUM — lição já paga em `financeiro.forma_pagamento`,
                // cujo ENUM travado impede formas personalizadas do catálogo.
                $table->string('forma_pagamento', 40);
                $table->unsignedBigInteger('conta_financeira_id')->nullable();

                $table->decimal('valor', 12, 2)->default(0);

                // Troco não é movimento financeiro, é conferência de gaveta —
                // por isso não cabe em `financeiro_movimentos`.
                $table->decimal('valor_recebido', 12, 2)->nullable();
                $table->decimal('troco', 12, 2)->default(0);

                $table->smallInteger('parcelas')->default(1);
                $table->unsignedBigInteger('operadora_id')->nullable();
                $table->unsignedBigInteger('bandeira_id')->nullable();
                $table->string('modalidade', 20)->nullable();

                // Snapshot da taxa: permite margem LÍQUIDA por venda sem passar
                // por `financeiro_movimentos_cartao`.
                $table->decimal('valor_taxa', 12, 2)->default(0);
                $table->decimal('valor_liquido', 12, 2)->nullable();

                // Ponteiro de reconciliação para `financeiro_movimentos.id`.
                $table->unsignedBigInteger('movimento_id')->nullable();

                $table->date('data_pagamento');
                $table->text('observacoes')->nullable();
                $table->integer('ordem')->default(0);
                $table->timestamps();

                $table->index(['venda_id', 'ordem'], 'idx_venda_pagamentos_venda_ordem');
                $table->index(['forma_pagamento', 'data_pagamento'], 'idx_venda_pagamentos_forma_data');
                $table->index('movimento_id', 'idx_venda_pagamentos_movimento');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('venda_pagamentos');
        Schema::dropIfExists('venda_itens');
        Schema::dropIfExists('vendas');
    }
};
