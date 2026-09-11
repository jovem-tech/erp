<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Razao de reserva de peca (specs/040).
 *
 * Ate aqui `orcamento_itens` guardava `tipo_item='peca'` + `referencia_id` SEM FK
 * e sem nenhuma validacao de saldo: dois orcamentos podiam prometer a mesma peca
 * unica e ninguem descobria ate o tecnico abrir a gaveta.
 *
 * Por que tabela propria, e nao so um contador em `pecas`:
 *  - reserva tem CICLO DE VIDA (ativa -> consumida | liberada) e vencimento, que
 *    um contador nao comporta;
 *  - a pergunta operacional e "para QUEM esta peca esta presa?", e so uma linha
 *    por (orcamento, peca) responde isso;
 *  - `movimentacoes` nao serve: e imutavel por design (sem `updated_at`) e
 *    registra fato consumado, nao promessa.
 *
 * `pecas.quantidade_reservada` (migration seguinte) e apenas o CACHE do somatorio
 * desta tabela, para o disponivel ser lido sem JOIN no PDV e nas listagens.
 *
 * Chaveada por (orcamento_id, peca_id) e NUNCA por orcamento_item_id:
 * `BudgetWorkflowService::syncItems()` apaga e reinsere TODOS os itens a cada
 * save, entao o id de `orcamento_itens` nao sobrevive a uma edicao. O proprio
 * repositorio ja assume isso em `cotacaoCongelada()`, que casa itens por
 * `tipo_item:referencia_id`.
 *
 * ATENCAO: `peca_id` e INT, nao bigint — `pecas.id` e `int` no legado, e
 * `movimentacoes.peca_id` ja segue essa forma. Tipo divergente impede a FK.
 *
 * Espelho obrigatorio em tests/Concerns/BuildsLegacyErpSchema.php: o trait
 * derruba e recria `pecas` e `orcamentos` DEPOIS das migrations, entao esta
 * tabela precisa nascer la tambem (createEstoqueReservasTable) ou a suite nao a
 * enxerga.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('estoque_reservas')) {
            return;
        }

        Schema::create('estoque_reservas', function (Blueprint $table): void {
            $table->id();

            $table->integer('peca_id');
            $table->unsignedBigInteger('orcamento_id');

            // Denormalizados de proposito: a tela precisa dizer PARA QUEM a peca
            // esta reservada sem tres JOINs, e `equipamento_id` e o vinculo que o
            // dono pediu — a peca fica presa ao aparelho daquele orcamento.
            $table->unsignedBigInteger('os_id')->nullable();
            $table->unsignedBigInteger('equipamento_id')->nullable();

            // DECIMAL(14,4) como todo saldo desde 2026_08_27_000001: insumo se
            // mede em fracao (0,5 m de cabo) e INT impediria isso para sempre.
            $table->decimal('quantidade', 14, 4)->default(0);
            $table->decimal('quantidade_consumida', 14, 4)->default(0);

            // ativa: segura saldo pelo remanescente (quantidade - consumida).
            // consumida: a peca saiu de verdade na baixa da OS.
            // liberada: orcamento morreu, item saiu do orcamento, ou soltaram na mao.
            $table->string('status', 20)->default('ativa');
            $table->string('origem', 30)->default('orcamento');

            // Copia de Budget::publicLinkDeadline() — a mesma regra unica que o
            // link publico (410) e o PDF ja leem, para as tres nao discordarem.
            $table->dateTime('expira_em')->nullable();

            // Trava da reconciliacao: reenviar o orcamento NAO pode desfazer uma
            // liberacao manual, senao o botao "liberar" e mentira.
            $table->boolean('liberacao_manual')->default(false);
            $table->dateTime('liberada_em')->nullable();
            $table->unsignedBigInteger('liberada_por')->nullable();
            $table->string('motivo_liberacao', 255)->nullable();

            $table->timestamps();

            // O coracao do desenho: uma linha por peca por orcamento. Duas linhas
            // da mesma peca no orcamento somam numa reserva so — mesma regra do
            // agregarPorPeca() do motor de movimentacao.
            $table->unique(['orcamento_id', 'peca_id'], 'uk_reserva_orcamento_peca');
            // Soma do reservado por peca (recalcular) e "quem segura esta peca".
            $table->index(['peca_id', 'status'], 'idx_reserva_peca_status');
            // Varredura de vencimento.
            $table->index(['status', 'expira_em'], 'idx_reserva_status_expira');

            $table->foreign('peca_id')->references('id')->on('pecas')->cascadeOnDelete();
            $table->foreign('orcamento_id')->references('id')->on('orcamentos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estoque_reservas');
    }
};
