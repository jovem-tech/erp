<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modulo RBAC proprio para o fiscal.
 *
 * Ate' aqui emitir e CANCELAR nota fiscal exigiam `os:editar` — a mesma
 * permissao que o tecnico usa o dia inteiro. Cancelar documento fiscal e' ato
 * com peso legal, e nao havia como tira-lo de quem edita OS sem tirar tambem a
 * edicao da OS. O proprio docblock do controller admitia que a decisao
 * "voltaria a mesa"; ela voltou.
 *
 * **Ninguem perde acesso nesta migration.** As permissoes novas sao semeadas
 * espelhando o que cada grupo ja' tem em `os`, inclusive `fiscal:excluir`
 * (cancelar) para quem tem `os:editar` — que e' exatamente quem podia cancelar
 * ontem. O que muda e' que agora DA' para apertar: revogar `fiscal:excluir` de
 * um grupo passa a ser uma linha na tela de Grupos, e nao um pedido de
 * refatoracao. Apertar e' decisao do dono do sistema, nao desta migration.
 *
 * Usa os slugs de permissao que ja' existem (`visualizar`, `criar`, `excluir`)
 * em vez de inventar `emitir`/`cancelar`: permissao nova exigiria mexer no
 * catalogo global e apareceria vazia em todos os outros modulos da tela.
 */
return new class extends Migration
{
    /**
     * De onde cada permissao do fiscal e' copiada.
     *
     * @var array<string, string>
     */
    private const ESPELHO = [
        // Ver a tela, baixar XML/PDF, gerar DANFSe.
        'visualizar' => 'visualizar',
        // Montar rascunho, registrar emissao, importar XML, anexar arquivo.
        'criar' => 'editar',
        // Cancelar. Nasce igual ao de hoje, para poder ser apertado depois.
        'excluir' => 'editar',
    ];

    public function up(): void
    {
        // Tabelas legadas: nos testes elas sao reconstruidas depois das
        // migrations, entao aqui isto e' no-op de proposito.
        if (! Schema::hasTable('modulos') || ! Schema::hasTable('permissoes') || ! Schema::hasTable('grupo_permissoes')) {
            return;
        }

        $moduloFiscal = (int) (DB::table('modulos')->where('slug', 'fiscal')->value('id') ?? 0);

        if ($moduloFiscal === 0) {
            $moduloFiscal = (int) DB::table('modulos')->insertGetId([
                'nome' => 'Fiscal',
                'slug' => 'fiscal',
                'icone' => 'bi-receipt-cutoff',
                'ordem_menu' => 48,
                'ativo' => 1,
            ]);
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
        if (! Schema::hasTable('modulos') || ! Schema::hasTable('grupo_permissoes')) {
            return;
        }

        $moduloFiscal = (int) (DB::table('modulos')->where('slug', 'fiscal')->value('id') ?? 0);

        if ($moduloFiscal === 0) {
            return;
        }

        DB::table('grupo_permissoes')->where('modulo_id', $moduloFiscal)->delete();
        DB::table('modulos')->where('id', $moduloFiscal)->delete();
    }
};
