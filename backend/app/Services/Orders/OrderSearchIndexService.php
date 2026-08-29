<?php

namespace App\Services\Orders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mantem `os.busca_texto`, a coluna que a listagem de OS usa para busca livre.
 *
 * O conteudo e' montado com UMA consulta por lote, lendo direto das tabelas de
 * origem (cliente, equipamento e os catalogos de tipo/marca/modelo). Reconstruir
 * por lote — e nao campo a campo no modelo — mantem a regra num lugar so' e faz
 * a reindexacao do acervo inteiro custar poucas consultas.
 */
class OrderSearchIndexService
{
    /**
     * Colunas de `os` que entram no indice. Deliberadamente NAO inclui campos
     * que ninguem procura por texto (orcamento_pdf, forma_pagamento) nem
     * valores monetarios: cada um deles custava uma avaliacao de funcao por
     * linha na busca antiga sem nunca ser o que o operador digitou.
     *
     * @var array<int, string>
     */
    private const ORDER_COLUMNS = [
        'numero_os',
        'numero_os_legado',
        'relato_cliente',
        'diagnostico_tecnico',
        'solucao_aplicada',
        'procedimentos_executados',
        'acessorios',
        'observacoes_internas',
        'observacoes_cliente',
    ];

    public function rebuildForOrders(array $orderIds): int
    {
        $orderIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $orderIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($orderIds === [] || ! $this->indexAvailable()) {
            return 0;
        }

        $rows = $this->sourceQuery()->whereIn('os.id', $orderIds)->get();
        $updated = 0;

        foreach ($rows as $row) {
            DB::table('os')
                ->where('id', (int) $row->id)
                ->update(['busca_texto' => $this->composeFromRow($row)]);

            $updated++;
        }

        return $updated;
    }

    public function rebuildForClient(int $clientId): int
    {
        if ($clientId <= 0 || ! $this->indexAvailable()) {
            return 0;
        }

        return $this->rebuildForOrders(
            DB::table('os')->where('cliente_id', $clientId)->pluck('id')->all()
        );
    }

    public function rebuildForEquipment(int $equipmentId): int
    {
        if ($equipmentId <= 0 || ! $this->indexAvailable()) {
            return 0;
        }

        return $this->rebuildForOrders(
            DB::table('os')->where('equipamento_id', $equipmentId)->pluck('id')->all()
        );
    }

    /**
     * Reconstroi o acervo inteiro em lotes, para a migracao inicial e para
     * quando um catalogo (tipo/marca/modelo) for renomeado em massa.
     *
     * @param  callable(int):void|null  $onBatch
     */
    public function rebuildAll(int $chunkSize = 500, ?callable $onBatch = null): int
    {
        if (! $this->indexAvailable()) {
            return 0;
        }

        $total = 0;
        $lastId = 0;

        while (true) {
            $ids = DB::table('os')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $lastId = (int) end($ids);
            $total += $this->rebuildForOrders($ids);

            if ($onBatch !== null) {
                $onBatch($total);
            }
        }

        return $total;
    }

    public function indexAvailable(): bool
    {
        return Schema::hasTable('os') && Schema::hasColumn('os', 'busca_texto');
    }

    /**
     * Normaliza o termo digitado da mesma forma que o conteudo indexado, para
     * que a comparacao seja simetrica.
     */
    public static function normalizeTerm(string $term): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $term) ?? ''));
    }

    private function sourceQuery(): \Illuminate\Database\Query\Builder
    {
        $select = ['os.id'];

        foreach (self::ORDER_COLUMNS as $column) {
            $select[] = 'os.'.$column;
        }

        return DB::table('os')
            ->leftJoin('clientes', 'clientes.id', '=', 'os.cliente_id')
            ->leftJoin('equipamentos', 'equipamentos.id', '=', 'os.equipamento_id')
            ->leftJoin('equipamentos_tipos', 'equipamentos_tipos.id', '=', 'equipamentos.tipo_id')
            ->leftJoin('equipamentos_marcas', 'equipamentos_marcas.id', '=', 'equipamentos.marca_id')
            ->leftJoin('equipamentos_modelos', 'equipamentos_modelos.id', '=', 'equipamentos.modelo_id')
            ->select(array_merge($select, [
                'clientes.nome_razao as cliente_nome',
                'clientes.cpf_cnpj as cliente_documento',
                'clientes.email as cliente_email',
                'clientes.telefone1 as cliente_telefone1',
                'clientes.telefone2 as cliente_telefone2',
                'clientes.nome_contato as cliente_contato',
                'clientes.cidade as cliente_cidade',
                'clientes.bairro as cliente_bairro',
                'equipamentos.resumo_tecnico as equipamento_resumo',
                'equipamentos.numero_serie as equipamento_serie',
                'equipamentos.imei as equipamento_imei',
                'equipamentos.cor as equipamento_cor',
                'equipamentos_tipos.nome as equipamento_tipo',
                'equipamentos_marcas.nome as equipamento_marca',
                'equipamentos_modelos.nome as equipamento_modelo',
            ]));
    }

    private function composeFromRow(object $row): string
    {
        $parts = [];

        foreach (get_object_vars($row) as $key => $value) {
            if ($key === 'id' || $value === null) {
                continue;
            }

            $parts[] = (string) $value;
        }

        // Digitos crus dos identificadores entram tambem sem pontuacao: quem
        // procura por CPF ou telefone quase nunca digita a mascara igual a' do
        // cadastro.
        foreach (['cliente_documento', 'cliente_telefone1', 'cliente_telefone2'] as $key) {
            $digits = preg_replace('/\D+/', '', (string) ($row->{$key} ?? ''));

            if (is_string($digits) && $digits !== '') {
                $parts[] = $digits;
            }
        }

        return self::normalizeTerm(implode(' ', $parts));
    }
}
