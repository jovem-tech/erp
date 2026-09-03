<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `fiscal:editar` — lançar e cancelar ajuste manual no Anexo X.
 *
 * Permissão PRÓPRIA, e não reuso de `fiscal:encerrar`, porque corrigir o
 * relatório e congelá-lo são poderes diferentes: dá para querer que alguém
 * lance a receita que faltou sem poder declarar o mês encerrado, e o contrário
 * também. Compartilhar o slug tornaria essa separação impossível sem uma nova
 * migration depois.
 *
 * `editar` já existe no catálogo global de permissões e não era usado por
 * nenhum módulo fiscal — reusá-lo evita poluir a tela de Grupos com uma coluna
 * nova vazia em todos os outros módulos, que é o raciocínio já registrado na
 * 2026_09_02_000002.
 *
 * Semeada espelhando `fiscal:encerrar`: quem já pode congelar o mês passa a
 * poder corrigi-lo, ninguém perde acesso ao subir, e apertar depois é uma linha
 * na tela de Grupos.
 */
return new class extends Migration
{
    /** @var array<string, string> destino => origem */
    private const ESPELHO = ['editar' => 'encerrar'];

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
        $idEditar = (int) (DB::table('permissoes')->where('slug', 'editar')->value('id') ?? 0);

        if ($moduloFiscal === 0 || $idEditar === 0) {
            return;
        }

        DB::table('grupo_permissoes')
            ->where('modulo_id', $moduloFiscal)
            ->where('permissao_id', $idEditar)
            ->delete();
    }
};
