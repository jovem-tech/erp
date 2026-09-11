<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pecas.quantidade_reservada` (specs/040) — cache do somatorio de
 * `estoque_reservas`, para o disponivel ser lido sem JOIN.
 *
 * Existe porque `Disponivel = quantidade_atual - quantidade_reservada` e lido em
 * caminho quente e por codigo que nao conhece reserva: a busca do PDV
 * (`SaleStockService::previewShortages()`), a listagem de estoque e o seletor de
 * peca do orcamento. Fazer cada um deles somar `estoque_reservas` por JOIN
 * espalharia a regra por cinco lugares.
 *
 * NAO e a fonte da verdade — a verdade e `estoque_reservas`. E reconstruivel a
 * qualquer momento por EstoqueReservaService::recalcular(), e e sempre reescrito
 * por soma absoluta dentro do lock da linha, nunca por delta.
 *
 * `pecas` e tabela LEGADA sem migration de criacao (nasceu no sistema antigo),
 * entao segue o padrao de 2026_08_27_000001_widen_stock_quantities_to_decimal:
 * DB::statement cru, guardado por Schema::hasColumn, no-op em SQLite (onde a
 * tabela vem do trait de teste) e `down()` que nao derruba coluna de tabela
 * compartilhada com o sistema legado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pecas') || Schema::hasColumn('pecas', 'quantidade_reservada')) {
            return;
        }

        // SQLite (suite de teste) recebe a coluna pelo BuildsLegacyErpSchema;
        // aqui so o MySQL real precisa do ALTER.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'ALTER TABLE `pecas` ADD COLUMN `quantidade_reservada` DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER `quantidade_atual`'
        );
    }

    public function down(): void
    {
        // Vazio de proposito, igual a 2026_08_27_000001: `pecas` e compartilhada
        // com a aplicacao legada e derrubar coluna em rollback e mais arriscado
        // que deixar uma coluna zerada parada.
    }
};
