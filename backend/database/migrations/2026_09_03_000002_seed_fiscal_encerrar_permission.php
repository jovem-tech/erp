<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `fiscal:encerrar` — fechar e reabrir o Anexo X de um mes.
 *
 * Reusa o slug `encerrar`, que ja' existe no catalogo global de permissoes e
 * significa literalmente isto. As alternativas foram descartadas:
 *
 *  - `fiscal:excluir` esta' tomado por CANCELAR documento fiscal, ato mais
 *    pesado que precisa continuar revogavel em separado;
 *  - inventar `fechar_periodo` obrigaria a mexer no catalogo global e
 *    apareceria como coluna vazia em todos os outros modulos da tela de
 *    Grupos — o mesmo raciocinio que a 2026_09_02_000002 ja' registrou.
 *
 * Semeada espelhando `fiscal:criar`: quem ja' emite nota passa a poder fechar
 * o mes, ninguem perde acesso ao subir, e apertar depois e' uma linha na tela
 * de Grupos.
 */
return new class extends Migration
{
    /** @var array<string, string> destino => origem */
    private const ESPELHO = ['encerrar' => 'criar'];

    public function up(): void
    {
        // Tabelas legadas: nos testes elas sao reconstruidas depois das
        // migrations, entao aqui isto e' no-op de proposito.
        if (! Schema::hasTable('modulos') || ! Schema::hasTable('permissoes') || ! Schema::hasTable('grupo_permissoes')) {
            return;
        }

        $moduloFiscal = (int) (DB::table('modulos')->where('slug', 'fiscal')->value('id') ?? 0);

        if ($moduloFiscal === 0) {
            return;
        }

        $permissoes = DB::table('permissoes')->pluck('id', 'slug')->all();

        $novas = [];

        foreach (self::ESPELHO as $destino => $origem) {
            $idDestino = (int) ($permissoes[$destino] ?? 0);
            $idOrigem = (int) ($permissoes[$origem] ?? 0);

            if ($idDestino === 0 || $idOrigem === 0) {
                continue;
            }

            // Espelha DENTRO do proprio modulo fiscal: quem pode montar e
            // registrar documento fiscal e' quem fecha o mes.
            $grupos = DB::table('grupo_permissoes')
                ->where('modulo_id', $moduloFiscal)
                ->where('permissao_id', $idOrigem)
                ->pluck('grupo_id');

            foreach ($grupos as $grupoId) {
                $jaTem = DB::table('grupo_permissoes')
                    ->where('grupo_id', $grupoId)
                    ->where('modulo_id', $moduloFiscal)
                    ->where('permissao_id', $idDestino)
                    ->exists();

                if (! $jaTem) {
                    $novas[] = [
                        'grupo_id' => (int) $grupoId,
                        'modulo_id' => $moduloFiscal,
                        'permissao_id' => $idDestino,
                    ];
                }
            }
        }

        if ($novas !== []) {
            DB::table('grupo_permissoes')->insert($novas);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('modulos') || ! Schema::hasTable('permissoes') || ! Schema::hasTable('grupo_permissoes')) {
            return;
        }

        $moduloFiscal = (int) (DB::table('modulos')->where('slug', 'fiscal')->value('id') ?? 0);
        $idEncerrar = (int) (DB::table('permissoes')->where('slug', 'encerrar')->value('id') ?? 0);

        if ($moduloFiscal === 0 || $idEncerrar === 0) {
            return;
        }

        DB::table('grupo_permissoes')
            ->where('modulo_id', $moduloFiscal)
            ->where('permissao_id', $idEncerrar)
            ->delete();
    }
};
