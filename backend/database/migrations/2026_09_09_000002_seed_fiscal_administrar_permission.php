<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `fiscal:administrar` — trocar o ambiente de emissao (homologacao/producao).
 *
 * Permissao PROPRIA, e nao reuso de `fiscal:criar` ou `configuracoes:editar`,
 * porque os poderes sao de ordens diferentes: emitir uma nota gera UM
 * documento; virar a chave para producao faz TODA emissao seguinte valer de
 * verdade, para todo mundo. Quem opera o balcao precisa do primeiro e nao do
 * segundo. E `configuracoes:editar` e' larga demais — e' a mesma permissao que
 * instala o certificado e mexe em integracao.
 *
 * `administrar` ja' existe no catalogo global e ainda nao era usada por nenhum
 * modulo fiscal — reusa-la evita coluna nova vazia na tela de Grupos, mesmo
 * raciocinio ja' registrado na 2026_09_02_000002.
 *
 * Semeada espelhando `fiscal:excluir` (cancelar documento fiscal), que e' hoje
 * o poder fiscal mais pesado do catalogo. Ninguem ganha acesso que nao tivesse
 * um equivalente; apertar depois e' uma linha na tela de Grupos.
 */
return new class extends Migration
{
    /** @var array<string, string> destino => origem */
    private const ESPELHO = ['administrar' => 'excluir'];

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
                        'grupo_id' => $grupoId,
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
        $idAdministrar = (int) (DB::table('permissoes')->where('slug', 'administrar')->value('id') ?? 0);

        if ($moduloFiscal === 0 || $idAdministrar === 0) {
            return;
        }

        DB::table('grupo_permissoes')
            ->where('modulo_id', $moduloFiscal)
            ->where('permissao_id', $idAdministrar)
            ->delete();
    }
};
