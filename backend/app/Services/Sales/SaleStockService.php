<?php

namespace App\Services\Sales;

use App\Models\Movimentacao;
use App\Models\Peca;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Baixa e estorno de estoque das vendas de balcão.
 *
 * Deliberadamente NÃO reutiliza EstoqueController::storeMovement(): aquele
 * caminho lê o saldo, calcula em PHP e regrava (`forceFill`), o que é uma
 * corrida clássica quando dois caixas vendem a mesma peça ao mesmo tempo.
 * Aqui o saldo é sempre alterado por decremento atômico no banco.
 *
 * Ver specs/027-vendas-balcao-pdv/spec.md.
 */
class SaleStockService
{
    /**
     * Dá baixa no estoque dos itens marcados com `baixa_estoque`.
     *
     * Deve ser chamado DENTRO da transação que cria a venda.
     *
     * @param  Collection<int, SaleItem>  $items
     * @return bool  true quando alguma peça saiu com saldo insuficiente
     *
     * @throws InsufficientStockException quando falta saldo e o operador ainda não confirmou
     */
    public function debitForSale(Sale $sale, Collection $items, ?int $actorId, bool $allowNegative = false): bool
    {
        // Idempotência: replay da mesma requisição não pode baixar duas vezes.
        if ($sale->estoque_baixado_em !== null) {
            return (bool) $sale->estoque_divergente;
        }

        $demand = $this->aggregateDemand($items);

        if ($demand === []) {
            $sale->forceFill(['estoque_baixado_em' => now()])->save();

            return false;
        }

        // orderBy('id') é OBRIGATÓRIO: garante ordem determinística de lock e
        // evita deadlock entre dois PDVs com carrinhos que se cruzam.
        $parts = Peca::query()
            ->whereIn('id', array_keys($demand))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $shortages = [];

        foreach ($demand as $partId => $entry) {
            $part = $parts->get($partId);

            if (! $part instanceof Peca) {
                continue;
            }

            $available = (float) ($part->quantidade_atual ?? 0);

            if ($available < $entry['quantidade']) {
                $shortages[] = [
                    'peca_id' => (int) $part->id,
                    'codigo' => (string) ($part->codigo ?? ''),
                    'nome' => (string) ($part->nome ?? ''),
                    'disponivel' => $available,
                    'solicitado' => $entry['quantidade'],
                ];
            }
        }

        if ($shortages !== [] && ! $allowNegative) {
            throw new InsufficientStockException($shortages);
        }

        $divergent = $shortages !== [];
        $now = now();

        foreach ($demand as $partId => $entry) {
            if (! $parts->has($partId)) {
                continue;
            }

            foreach ($entry['itens'] as $item) {
                $quantity = (int) round((float) $item->quantidade);

                if ($quantity <= 0) {
                    continue;
                }

                Movimentacao::query()->create([
                    'peca_id' => $partId,
                    'os_id' => null,
                    'venda_id' => (int) $sale->id,
                    'venda_item_id' => (int) $item->id,
                    'tipo' => 'saida',
                    'quantidade' => $quantity,
                    'motivo' => $this->movementReason($sale, $divergent),
                    'responsavel_id' => $actorId,
                    'created_at' => $now,
                ]);
            }

            // Decremento atômico. O saldo PODE ficar negativo quando o operador
            // confirmou a venda sem estoque: é o sinal honesto de que o
            // inventário precisa de acerto, e a coluna é `int` com sinal.
            Peca::query()
                ->whereKey($partId)
                ->update([
                    'quantidade_atual' => DB::raw('quantidade_atual - '.(int) round($entry['quantidade'])),
                    'updated_at' => $now,
                ]);
        }

        $sale->forceFill([
            'estoque_baixado_em' => $now,
            'estoque_divergente' => $divergent,
        ])->save();

        return $divergent;
    }

    /**
     * Devolve ao estoque tudo o que a venda tirou.
     *
     * Nunca apaga a movimentação de saída: o histórico precisa mostrar saída e
     * entrada, porque é isso que concilia com a contagem física.
     */
    public function creditForSaleCancellation(Sale $sale, ?int $actorId): void
    {
        if ($sale->estoque_baixado_em === null) {
            return;
        }

        $movements = Movimentacao::query()
            ->where('venda_id', (int) $sale->id)
            ->where('tipo', 'saida')
            ->orderBy('peca_id')
            ->lockForUpdate()
            ->get();

        if ($movements->isEmpty()) {
            $sale->forceFill(['estoque_baixado_em' => null])->save();

            return;
        }

        $now = now();

        // Mesma ordem determinística da baixa.
        $totals = $movements
            ->groupBy('peca_id')
            ->map(static fn (Collection $group): int => (int) $group->sum('quantidade'))
            ->sortKeys();

        Peca::query()
            ->whereIn('id', $totals->keys()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($movements as $movement) {
            Movimentacao::query()->create([
                'peca_id' => (int) $movement->peca_id,
                'os_id' => null,
                'venda_id' => (int) $sale->id,
                'venda_item_id' => $movement->venda_item_id,
                'tipo' => 'entrada',
                'quantidade' => (int) $movement->quantidade,
                'motivo' => 'Estorno da venda '.$sale->numero,
                'responsavel_id' => $actorId,
                'created_at' => $now,
            ]);
        }

        foreach ($totals as $partId => $quantity) {
            Peca::query()
                ->whereKey($partId)
                ->update([
                    'quantidade_atual' => DB::raw('quantidade_atual + '.(int) $quantity),
                    'updated_at' => $now,
                ]);
        }

        $sale->forceFill(['estoque_baixado_em' => null])->save();
    }

    /**
     * Devolve ao estoque as quantidades de uma devolução parcial.
     *
     * Diferente de creditForSaleCancellation(), que devolve tudo: aqui só volta
     * o que o cliente trouxe de fato (specs/029-devolucao-troca).
     *
     * @param  array<int, array{peca_id: int, venda_item_id: int, quantidade: int}>  $lines
     */
    public function creditForReturn(Sale $sale, string $returnNumber, array $lines, ?int $actorId): void
    {
        $totals = [];

        foreach ($lines as $line) {
            $partId = (int) ($line['peca_id'] ?? 0);
            $quantity = (int) round((float) ($line['quantidade'] ?? 0));

            if ($partId <= 0 || $quantity <= 0) {
                continue;
            }

            $totals[$partId] = ($totals[$partId] ?? 0) + $quantity;
        }

        if ($totals === []) {
            return;
        }

        ksort($totals);

        // Mesma ordem determinística de lock usada na baixa, para não cruzar
        // com uma venda simultânea da mesma peça.
        Peca::query()
            ->whereIn('id', array_keys($totals))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $now = now();

        foreach ($lines as $line) {
            $partId = (int) ($line['peca_id'] ?? 0);
            $quantity = (int) round((float) ($line['quantidade'] ?? 0));

            if ($partId <= 0 || $quantity <= 0) {
                continue;
            }

            Movimentacao::query()->create([
                'peca_id' => $partId,
                'os_id' => null,
                // Aponta para a venda original: a ficha da peça precisa mostrar
                // a saída e o retorno lado a lado.
                'venda_id' => (int) $sale->id,
                'venda_item_id' => (int) ($line['venda_item_id'] ?? 0) ?: null,
                'tipo' => 'entrada',
                'quantidade' => $quantity,
                'motivo' => 'Devolução '.$returnNumber,
                'responsavel_id' => $actorId,
                'created_at' => $now,
            ]);
        }

        foreach ($totals as $partId => $quantity) {
            Peca::query()
                ->whereKey($partId)
                ->update([
                    'quantidade_atual' => DB::raw('quantidade_atual + '.(int) $quantity),
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * Confere saldo sem gravar nada — usado antes de abrir a transação.
     *
     * @param  array<int, array<string, mixed>>  $items  itens já normalizados
     * @return array<int, array{peca_id: int, codigo: string, nome: string, disponivel: float, solicitado: float}>
     */
    public function previewShortages(array $items): array
    {
        $demand = [];

        foreach ($items as $item) {
            if (empty($item['baixa_estoque'])) {
                continue;
            }

            $partId = (int) ($item['referencia_id'] ?? 0);
            $quantity = (float) ($item['quantidade'] ?? 0);

            if ($partId <= 0 || $quantity <= 0) {
                continue;
            }

            $demand[$partId] = ($demand[$partId] ?? 0) + $quantity;
        }

        if ($demand === []) {
            return [];
        }

        $parts = Peca::query()->whereIn('id', array_keys($demand))->get()->keyBy('id');
        $shortages = [];

        foreach ($demand as $partId => $quantity) {
            $part = $parts->get($partId);

            if (! $part instanceof Peca) {
                continue;
            }

            $available = (float) ($part->quantidade_atual ?? 0);

            if ($available < $quantity) {
                $shortages[] = [
                    'peca_id' => (int) $part->id,
                    'codigo' => (string) ($part->codigo ?? ''),
                    'nome' => (string) ($part->nome ?? ''),
                    'disponivel' => $available,
                    'solicitado' => $quantity,
                ];
            }
        }

        return $shortages;
    }

    /**
     * Agrupa a demanda por peça: duas linhas da mesma película viram uma única
     * checagem de saldo (esquecer isso deixa vender o dobro do disponível).
     *
     * @param  Collection<int, SaleItem>  $items
     * @return array<int, array{quantidade: float, itens: array<int, SaleItem>}>
     */
    private function aggregateDemand(Collection $items): array
    {
        $demand = [];

        foreach ($items as $item) {
            if (! $item->baixa_estoque) {
                continue;
            }

            $partId = (int) ($item->referencia_id ?? 0);
            $quantity = (float) ($item->quantidade ?? 0);

            if ($partId <= 0 || $quantity <= 0) {
                continue;
            }

            if (! isset($demand[$partId])) {
                $demand[$partId] = ['quantidade' => 0.0, 'itens' => []];
            }

            $demand[$partId]['quantidade'] += $quantity;
            $demand[$partId]['itens'][] = $item;
        }

        ksort($demand);

        return $demand;
    }

    private function movementReason(Sale $sale, bool $divergent): string
    {
        return $divergent
            ? 'Venda '.$sale->numero.' (saldo insuficiente)'
            : 'Venda '.$sale->numero;
    }
}
