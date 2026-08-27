<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quantidades passam de INT para DECIMAL(14,4).
 *
 * Motivo: nem tudo se mede em unidade inteira. Nao existe meia tela, mas
 * existe meio metro de cabo flat, 1,5 g de pasta termica e 250 ml de alcool
 * isopropilico. Quem decide se cabe fracao e a UNIDADE DE MEDIDA do item
 * (specs/036) — esta migration so garante que a coluna comporte a resposta.
 *
 * Feito agora porque o estoque esta praticamente vazio (9 pecas, 1
 * movimentacao). Com o razao populado, a mesma mudanca custaria o razao
 * inteiro.
 *
 * URGENCIA: a v5.58.0.0 relaxou a validacao de `integer` para `numeric` sem
 * aplicar este alargamento. Enquanto as colunas forem INT, o MySQL
 * ARREDONDA em silencio (`CAST(0.5 AS SIGNED)` = 1, `CAST(2.5 AS SIGNED)` = 3)
 * mesmo sob STRICT_TRANS_TABLES: uma saida de 0,5 sobre saldo 3 grava saldo 3
 * (inalterado) e movimento 1. O razao passa a mentir sem avisar.
 *
 * `pecas`, `movimentacoes` e `orcamento_itens` sao tabelas LEGADAS, declaradas
 * apenas em tests/Concerns/BuildsLegacyErpSchema.php — que roda DEPOIS das
 * migrations e recria as tabelas do zero. O tipo novo precisa ser declarado
 * nos dois lugares.
 *
 * Nao usa $table->change(): doctrine/dbal nao e dependencia direta deste
 * projeto. ALTER direto, e no-op em SQLite (la o tipo e afinidade, nao
 * restricao, e a suite ja grava decimal sem reclamar).
 */
return new class extends Migration
{
    /**
     * @var array<string, array<int, string>>
     */
    private const COLUNAS = [
        'pecas' => ['quantidade_atual', 'estoque_minimo', 'estoque_maximo'],
        'movimentacoes' => ['quantidade'],
        // orcamento_itens e a unica tabela do fluxo que nao comporta o catalogo
        // de unidades: em DECIMAL(10,2), 0,250 kg vira 0,25 e 0,125 kg vira
        // 0,13. Alargada junto para que a mesma quantidade signifique a mesma
        // coisa do orcamento ao razao.
        'orcamento_itens' => ['quantidade'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::COLUNAS as $tabela => $colunas) {
            if (! Schema::hasTable($tabela)) {
                continue;
            }

            foreach ($colunas as $coluna) {
                if (! Schema::hasColumn($tabela, $coluna)) {
                    continue;
                }

                DB::statement($this->alterPreservandoDefinicao($tabela, $coluna));
            }
        }
    }

    /**
     * Monta o ALTER preservando nulidade e default ATUAIS da coluna.
     *
     * Escrever `NOT NULL DEFAULT 0` fixo seria mudar duas coisas de vez em
     * tabela legada. No banco real de desenvolvimento, por exemplo,
     * `pecas.estoque_minimo` e NULL-able com DEFAULT 1 — um ALTER fixo o
     * tornaria NOT NULL com DEFAULT 0, alterando o criterio de estoque baixo
     * pela porta dos fundos, sem ninguem pedir.
     *
     * A largura do tipo e o unico atributo que esta migration tem o direito
     * de mudar.
     */
    private function alterPreservandoDefinicao(string $tabela, string $coluna): string
    {
        // SHOW COLUMNS nao aceita parametro vinculado no MySQL — o nome vai
        // interpolado. Seguro: tabela e coluna vem de self::COLUNAS, uma
        // constante desta classe, nunca de entrada do usuario.
        $definicao = DB::selectOne(sprintf("SHOW COLUMNS FROM `%s` LIKE '%s'", $tabela, $coluna));

        $aceitaNulo = strtoupper((string) $definicao->Null) === 'YES';
        $sql = sprintf(
            'ALTER TABLE `%s` MODIFY `%s` DECIMAL(14,4) %s',
            $tabela,
            $coluna,
            $aceitaNulo ? 'NULL' : 'NOT NULL'
        );

        if ($definicao->Default !== null) {
            // Default numerico: interpolado como literal, nunca como string,
            // para nao virar DEFAULT '1.00' num tipo decimal.
            $sql .= ' DEFAULT ' . number_format((float) $definicao->Default, 4, '.', '');
        } elseif ($aceitaNulo) {
            $sql .= ' DEFAULT NULL';
        }

        return $sql;
    }

    /**
     * Sem rollback, de proposito.
     *
     * DECIMAL -> INT nao trunca: o MySQL ARREDONDA. Um rollback que
     * silenciosamente transforma 2,5 m em 3 m de saldo e pior que nao ter
     * rollback. Quem precisar reverter deve decidir explicitamente o que fazer
     * com as fracoes antes de rodar o ALTER na mao.
     */
    public function down(): void
    {
    }
};
