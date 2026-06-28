<?php

namespace App\Services\Financeiro;

use App\Models\Financeiro;
use App\Models\FinanceiroCategoria;
use App\Models\FinanceiroMovimento;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use RuntimeException;

class FinanceiroService
{
    /**
     * @param array<string, mixed> $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        return Financeiro::query()
            ->withFilters($filters)
            ->with(['order', 'client'])
            ->orderByDesc('data_vencimento')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): Financeiro
    {
        $resolved = $this->resolveClassification($payload, null);

        $financeiro = Financeiro::create($resolved);
        $this->finalizeAfterSave($financeiro, $payload);

        return $financeiro->refresh();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(Financeiro $financeiro, array $payload): Financeiro
    {
        $this->guardMutationAgainstMovements($financeiro, $payload);

        $resolved = $this->resolveClassification($payload, $financeiro);
        $financeiro->update($resolved);
        $this->finalizeAfterSave($financeiro, $payload);

        return $financeiro->refresh();
    }

    public function delete(Financeiro $financeiro): void
    {
        $financeiro->delete();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function registerMovement(Financeiro $financeiro, array $payload): array
    {
        if ($financeiro->status === Financeiro::STATUS_CANCELADO) {
            throw new RuntimeException('Não é possível registrar baixa em título cancelado.');
        }

        $summary = $this->movementSummary($financeiro);
        $valorAberto = round((float) $summary['valor_aberto'], 2);

        if ($valorAberto <= 0) {
            throw new RuntimeException('Este título já está totalmente liquidado.');
        }

        $valorMovimento = round((float) ($payload['valor_movimento'] ?? $payload['valor'] ?? 0), 2);

        if ($valorMovimento <= 0) {
            throw new RuntimeException('Informe um valor válido para a baixa.');
        }

        if ($valorMovimento > $valorAberto + 0.001) {
            throw new RuntimeException('O valor da baixa não pode ser maior que o saldo em aberto do título.');
        }

        $formaPagamento = trim((string) ($payload['forma_pagamento'] ?? ''));
        $observacoes = trim((string) ($payload['observacoes'] ?? ''));
        $documentoRef = trim((string) ($payload['documento_ref'] ?? ''));

        $movimento = FinanceiroMovimento::create([
            'financeiro_id' => $financeiro->id,
            'tipo_movimento' => $financeiro->tipo === Financeiro::TIPO_RECEBER
                ? FinanceiroMovimento::TIPO_ENTRADA
                : FinanceiroMovimento::TIPO_SAIDA,
            'data_movimento' => $this->normalizeDate($payload['data_movimento'] ?? $payload['data_pagamento'] ?? null) ?? now()->toDateString(),
            'valor_movimento' => $valorMovimento,
            'forma_pagamento' => $formaPagamento !== '' ? $formaPagamento : null,
            'documento_ref' => $documentoRef !== '' ? $documentoRef : null,
            'observacoes' => $observacoes !== '' ? $observacoes : null,
        ]);

        $summary = $this->syncFromMovements($financeiro);
        $summary['movement_id'] = $movimento->id;

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function movementSummary(Financeiro $financeiro): array
    {
        $valorTitulo = round((float) $financeiro->valor, 2);

        $aggregate = $financeiro->movimentos()
            ->selectRaw('COUNT(*) as total_movimentos, COALESCE(SUM(valor_movimento), 0) as valor_movimentado, MAX(data_movimento) as ultimo_movimento_em')
            ->first();

        $valorMovimentado = round((float) ($aggregate->valor_movimentado ?? 0), 2);
        $valorAberto = max(0, round($valorTitulo - $valorMovimentado, 2));
        $totalMovimentos = (int) ($aggregate->total_movimentos ?? 0);

        $formasPagamento = $financeiro->movimentos()
            ->whereNotNull('forma_pagamento')
            ->distinct()
            ->pluck('forma_pagamento');

        $formaPagamentoResolvida = $totalMovimentos > 1
            ? 'multiplo'
            : ($formasPagamento->first() ?? null);

        return [
            'titulo_id' => $financeiro->id,
            'valor_titulo' => $valorTitulo,
            'valor_movimentado' => $valorMovimentado,
            'valor_aberto' => $valorAberto,
            'total_movimentos' => $totalMovimentos,
            'ultimo_movimento_em' => $aggregate->ultimo_movimento_em ?? null,
            'forma_pagamento_resolvida' => $formaPagamentoResolvida,
            'status_resolvido' => $this->resolveStatus($financeiro->status, $valorTitulo, $valorMovimentado),
            'percentual_quitado' => $valorTitulo > 0 ? min(100, round(($valorMovimentado / $valorTitulo) * 100, 2)) : 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function syncFromMovements(Financeiro $financeiro): array
    {
        $summary = $this->movementSummary($financeiro);
        $status = (string) $summary['status_resolvido'];
        $clearPagamento = in_array($status, [Financeiro::STATUS_CANCELADO, Financeiro::STATUS_PENDENTE], true);

        $formaPagamento = (string) ($summary['forma_pagamento_resolvida'] ?? '');

        $financeiro->update([
            'status' => $status,
            'data_pagamento' => $clearPagamento ? null : ($summary['ultimo_movimento_em'] ?? null),
            // "multiplo" é só um rótulo informativo do resumo da API; a coluna
            // financeiro.forma_pagamento é um ENUM restrito do banco real e não
            // aceita esse valor sintético — o detalhe de cada baixa já fica
            // registrado em financeiro_movimentos.forma_pagamento (texto livre).
            'forma_pagamento' => (! $clearPagamento && in_array($formaPagamento, Financeiro::FORMAS_PAGAMENTO, true))
                ? $formaPagamento
                : null,
        ]);

        return $summary;
    }

    private function resolveStatus(string $statusAtual, float $valorTitulo, float $valorMovimentado): string
    {
        if ($statusAtual === Financeiro::STATUS_CANCELADO) {
            return Financeiro::STATUS_CANCELADO;
        }

        if ($valorMovimentado <= 0) {
            return Financeiro::STATUS_PENDENTE;
        }

        if ($valorTitulo > 0 && $valorMovimentado + 0.001 < $valorTitulo) {
            return Financeiro::STATUS_PARCIAL;
        }

        return Financeiro::STATUS_PAGO;
    }

    /**
     * Depois de criar/atualizar o título, garante que o status declarado e os
     * movimentos de baixa fiquem consistentes entre si (mesma regra do legado:
     * "pago" sem movimento cria a baixa total automaticamente; "parcial" sem
     * movimento volta para "pendente").
     *
     * @param array<string, mixed> $payload
     */
    private function finalizeAfterSave(Financeiro $financeiro, array $payload): void
    {
        $summary = $this->movementSummary($financeiro);

        if ($financeiro->status === Financeiro::STATUS_CANCELADO) {
            if ((int) $summary['total_movimentos'] > 0) {
                throw new RuntimeException('Não é possível cancelar um título que já possui movimentos realizados.');
            }

            return;
        }

        if ((int) $summary['total_movimentos'] > 0) {
            $this->syncFromMovements($financeiro);

            return;
        }

        if ($financeiro->status === Financeiro::STATUS_PAGO) {
            $this->registerMovement($financeiro, [
                'valor_movimento' => $financeiro->valor,
                'data_movimento' => $payload['data_pagamento'] ?? $financeiro->data_pagamento,
                'forma_pagamento' => $payload['forma_pagamento'] ?? $financeiro->forma_pagamento,
                'observacoes' => $payload['observacoes'] ?? null,
            ]);

            return;
        }

        if ($financeiro->status === Financeiro::STATUS_PARCIAL) {
            $financeiro->update(['status' => Financeiro::STATUS_PENDENTE, 'data_pagamento' => null, 'forma_pagamento' => null]);

            return;
        }

        if ($financeiro->data_pagamento !== null || $financeiro->forma_pagamento !== null) {
            $financeiro->update(['data_pagamento' => null, 'forma_pagamento' => null]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function guardMutationAgainstMovements(Financeiro $financeiro, array $payload): void
    {
        $summary = $this->movementSummary($financeiro);
        if ((int) $summary['total_movimentos'] <= 0) {
            return;
        }

        if (array_key_exists('tipo', $payload) && (string) $payload['tipo'] !== $financeiro->tipo) {
            throw new RuntimeException('Não é possível alterar o tipo de um título que já possui movimentações registradas.');
        }

        if (array_key_exists('impacta_fluxo_caixa', $payload) && ! filter_var($payload['impacta_fluxo_caixa'], FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Um título que já possui movimentos realizados deve continuar impactando o fluxo de caixa.');
        }

        $statusDestino = (string) ($payload['status'] ?? $financeiro->status);
        if ($statusDestino === Financeiro::STATUS_CANCELADO) {
            throw new RuntimeException('Não é possível cancelar um título que já possui movimentações registradas.');
        }

        if (array_key_exists('valor', $payload) && round((float) $payload['valor'], 2) + 0.001 < round((float) $summary['valor_movimentado'], 2)) {
            throw new RuntimeException('O valor total do título não pode ficar menor que o valor já baixado.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveClassification(array $payload, ?Financeiro $existing): array
    {
        $merged = array_merge($existing?->toArray() ?? [], $payload);

        $tipo = strtolower(trim((string) ($merged['tipo'] ?? '')));
        $categoriaNome = trim((string) ($merged['categoria'] ?? ''));
        $categoriaConfig = $categoriaNome !== ''
            ? FinanceiroCategoria::query()
                ->whereRaw('LOWER(nome) = ?', [mb_strtolower($categoriaNome, 'UTF-8')])
                ->whereIn('tipo', array_filter([$tipo, FinanceiroCategoria::TIPO_AMBOS]))
                ->with(['dre_grupo', 'dre_subgrupo'])
                ->first()
            : null;

        $resolved = $payload;

        $resolved['tipo'] = $tipo;
        $resolved['status'] = trim((string) ($payload['status'] ?? $existing?->status ?? '')) !== ''
            ? $payload['status'] ?? $existing?->status
            : Financeiro::STATUS_PENDENTE;
        $resolved['categoria'] = $categoriaNome !== '' ? $categoriaNome : $existing?->categoria;
        $resolved['descricao'] = trim((string) ($payload['descricao'] ?? '')) !== ''
            ? $payload['descricao']
            : ($existing?->descricao ?? $categoriaNome);

        $resolved['grupo_dre'] = trim((string) ($payload['grupo_dre'] ?? '')) !== ''
            ? $payload['grupo_dre']
            : ($categoriaConfig?->dre_grupo?->nome ?? $existing?->grupo_dre);

        $resolved['subgrupo_dre'] = trim((string) ($payload['subgrupo_dre'] ?? '')) !== ''
            ? $payload['subgrupo_dre']
            : ($categoriaConfig?->dre_subgrupo?->nome ?? $existing?->subgrupo_dre);

        $resolved['impacta_dre'] = array_key_exists('impacta_dre', $payload)
            ? filter_var($payload['impacta_dre'], FILTER_VALIDATE_BOOL)
            : ($existing?->impacta_dre ?? (bool) ($categoriaConfig?->impacta_dre_padrao ?? true));

        $resolved['impacta_fluxo_caixa'] = array_key_exists('impacta_fluxo_caixa', $payload)
            ? filter_var($payload['impacta_fluxo_caixa'], FILTER_VALIDATE_BOOL)
            : ($existing?->impacta_fluxo_caixa ?? (bool) ($categoriaConfig?->impacta_fluxo_caixa_padrao ?? true));

        $resolved['dre_fixo_mensal'] = $tipo === Financeiro::TIPO_PAGAR
            ? (array_key_exists('dre_fixo_mensal', $payload)
                ? filter_var($payload['dre_fixo_mensal'], FILTER_VALIDATE_BOOL)
                : ($existing?->dre_fixo_mensal ?? (bool) ($categoriaConfig?->dre_fixo_mensal_padrao ?? false)))
            : false;

        $resolved['data_competencia'] = $this->normalizeDate($payload['data_competencia'] ?? null)
            ?? $existing?->data_competencia?->toDateString()
            ?? $this->normalizeDate($merged['data_vencimento'] ?? null);

        if (($resolved['status'] ?? $existing?->status) === Financeiro::STATUS_PAGO && empty($merged['data_pagamento'])) {
            $resolved['data_pagamento'] = now()->toDateString();
        }

        $osId = (int) ($merged['os_id'] ?? 0);

        if ($tipo === Financeiro::TIPO_RECEBER) {
            $clienteId = (int) ($payload['cliente_id'] ?? $existing?->cliente_id ?? 0);

            if ($clienteId <= 0 && $osId > 0) {
                $clienteId = (int) (Order::query()->where('id', $osId)->value('cliente_id') ?? 0);
            }

            if ($clienteId <= 0 && $osId <= 0) {
                throw new RuntimeException('Selecione o cliente desta cobrança ou vincule uma OS antes de salvar.');
            }

            $resolved['cliente_id'] = $clienteId > 0 ? $clienteId : null;
            $resolved['fornecedor_id'] = null;
        } else {
            $resolved['cliente_id'] = null;
            $resolved['fornecedor_id'] = ! empty($payload['fornecedor_id']) ? (int) $payload['fornecedor_id'] : null;
        }

        return $resolved;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }
}
