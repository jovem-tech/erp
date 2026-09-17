<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `os:administrar` — conceder desconto ao cliente no fechamento da OS.
 *
 * Permissão PRÓPRIA, e não reuso de `os:editar` (que já libera a tela de
 * baixa inteira), porque reduzir o valor faturado é um poder de ordem
 * diferente do resto do fechamento: qualquer técnico fecha OS o dia inteiro,
 * mas conceder desconto tem peso fiscal (reduz a base de ISS/receita bruta
 * declarada) e financeiro (reduz o que a empresa recebe). Ver
 * OrderClosureService::close().
 *
 * `administrar` já existe no catálogo global de permissões e ainda não era
 * usada pelo módulo `os` (hoje só usa `visualizar`/`criar`/`editar`) — reusá-la
 * evita coluna nova vazia na tela de Grupos para os demais módulos, mesmo
 * raciocínio já registrado em 2026_09_02_000002 e 2026_09_09_000002.
 *
 * Semeada espelhando `os:editar`: quem já fecha OS ganha a permissão de
 * desconto inicialmente — ninguém perde acesso — e apertar depois (restringir
 * a um perfil gerencial, por exemplo) é uma linha na tela de Grupos.
 */
return new class extends Migration
{
    /** @var array<string, string> destino => origem */
    private const ESPELHO = ['administrar' => 'editar'];

    public function up(): void
    {
        // Tabelas legadas: nos testes elas sao reconstruidas depois das
        // migrations, entao aqui isto e' no-op de proposito.
        if (! Schema::hasTable('modulos') || ! Schema::hasTable('permissoes') || ! Schema::hasTable('grupo_permissoes')) {
            return;
        }

        $moduloOs = (int) (DB::table('modulos')->where('slug', 'os')->value('id') ?? 0);

        if ($moduloOs === 0) {
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
                ->where('modulo_id', $moduloOs)
                ->where('permissao_id', $idOrigem)
                ->pluck('grupo_id');

            foreach ($grupos as $grupoId) {
                $jaTem = DB::table('grupo_permissoes')
                    ->where('grupo_id', $grupoId)
                    ->where('modulo_id', $moduloOs)
                    ->where('permissao_id', $idDestino)
                    ->exists();

                if (! $jaTem) {
                    $novas[] = [
                        'grupo_id' => (int) $grupoId,
                        'modulo_id' => $moduloOs,
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

        $moduloOs = (int) (DB::table('modulos')->where('slug', 'os')->value('id') ?? 0);
        $idAdministrar = (int) (DB::table('permissoes')->where('slug', 'administrar')->value('id') ?? 0);

        if ($moduloOs === 0 || $idAdministrar === 0) {
            return;
        }

        DB::table('grupo_permissoes')
            ->where('modulo_id', $moduloOs)
            ->where('permissao_id', $idAdministrar)
            ->delete();
    }
};
