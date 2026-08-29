<?php

use App\Services\Orders\OrderSearchIndexService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coluna desnormalizada de busca da OS.
 *
 * A listagem de OS buscava texto livre com 53 predicados
 * `LOWER(COALESCE(coluna,'')) LIKE '%termo%'` espalhados por 7 tabelas unidas.
 * Nenhum indice serve esse formato (wildcard a' esquerda + funcao sobre a
 * coluna), entao cada busca varria a juncao inteira: medido em 124ms para a
 * pagina + 120ms para o COUNT do paginador, com apenas 3.645 OS — e o custo
 * cresce linearmente com o acervo.
 *
 * Concentrando o mesmo conteudo em UMA coluna da tabela dirigente, a busca vira
 * um unico LIKE sobre `os`, sem depender das juncoes: ~21ms nas mesmas 3.645
 * linhas. A coluna e' mantida por OrderSearchIndexService (ver os observers de
 * Order, Client e Equipment) e pode ser reconstruida com `os:reindexar-busca`.
 *
 * Sem indice de proposito: BTREE nao atende `LIKE '%x%'`, e indice que nao
 * serve a consulta so' encarece INSERT/UPDATE. Quando o acervo justificar,
 * o passo seguinte e' um FULLTEXT sobre esta mesma coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('os') || Schema::hasColumn('os', 'busca_texto')) {
            return;
        }

        Schema::table('os', function (Blueprint $table): void {
            $table->text('busca_texto')->nullable()->after('observacoes_cliente');
        });

        // Backfill imediato: sem ele o acervo existente ficaria com a coluna
        // NULL ate' alguem lembrar de rodar `os:reindexar-busca`, e a busca
        // dependeria da rede de seguranca (mais lenta) por tempo indeterminado.
        // Em lotes, para nao segurar a tabela numa transacao unica.
        app(OrderSearchIndexService::class)->rebuildAll(500);
    }

    public function down(): void
    {
        if (! Schema::hasTable('os') || ! Schema::hasColumn('os', 'busca_texto')) {
            return;
        }

        Schema::table('os', function (Blueprint $table): void {
            $table->dropColumn('busca_texto');
        });
    }
};
