<?php

namespace App\Services\Financeiro;

use App\Models\FinanceiroCartaoBandeira;
use App\Models\FinanceiroCartaoOperadora;
use App\Models\FinanceiroCartaoTaxa;
use Illuminate\Support\Carbon;
use RuntimeException;

class FinanceiroCartaoService
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function simulate(array $payload): array
    {
        $valorBruto = round((float) ($payload['valor_bruto'] ?? $payload['valor'] ?? 0), 2);
        if ($valorBruto <= 0) {
            throw new RuntimeException('Informe um valor bruto válido para simular o recebimento.');
        }

        $operadoraId = (int) ($payload['operadora_id'] ?? 0);
        if ($operadoraId <= 0) {
            throw new RuntimeException('Selecione a operadora da maquininha.');
        }

        $bandeiraId = ! empty($payload['bandeira_id']) ? (int) $payload['bandeira_id'] : null;
        $modalidade = $this->normalizeModalidade(
            (string) ($payload['modalidade'] ?? ''),
            (string) ($payload['forma_pagamento'] ?? '')
        );

        if (! in_array($modalidade, [FinanceiroCartaoTaxa::MODALIDADE_CREDITO, FinanceiroCartaoTaxa::MODALIDADE_DEBITO], true)) {
            throw new RuntimeException('Selecione se a venda será no crédito ou no débito.');
        }

        $parcelas = max(1, (int) ($payload['parcelas'] ?? 1));
        if ($modalidade === FinanceiroCartaoTaxa::MODALIDADE_DEBITO) {
            $parcelas = 1;
        }

        $taxa = $this->findApplicableRate($operadoraId, $modalidade, $parcelas, $bandeiraId);
        if (! $taxa instanceof FinanceiroCartaoTaxa) {
            throw new RuntimeException('Não foi encontrada uma taxa ativa para a combinação de operadora, bandeira e parcelas.');
        }

        $operadora = FinanceiroCartaoOperadora::query()->find($operadoraId);
        $bandeira = $bandeiraId !== null ? FinanceiroCartaoBandeira::query()->find($bandeiraId) : null;

        $percentual = round((float) $taxa->taxa_percentual, 4);
        $taxaFixa = round((float) $taxa->taxa_fixa, 2);
        $valorTaxa = round(($valorBruto * ($percentual / 100)) + $taxaFixa, 2);
        $valorLiquido = round($valorBruto - $valorTaxa, 2);
        $prazoRecebimentoDias = (int) ($taxa->prazo_recebimento_dias ?: ($operadora?->prazo_padrao_dias ?? 0));
        $dataPrevista = Carbon::now()->addDays(max(0, $prazoRecebimentoDias))->toDateString();

        return [
            'ok' => true,
            'valor_bruto' => $valorBruto,
            'valor_taxa' => $valorTaxa,
            'valor_liquido' => $valorLiquido,
            'taxa_percentual' => $percentual,
            'taxa_fixa' => $taxaFixa,
            'parcelas' => $parcelas,
            'modalidade' => $modalidade,
            'modalidade_label' => $modalidade === FinanceiroCartaoTaxa::MODALIDADE_DEBITO ? 'Cartão de débito' : 'Cartão de crédito',
            'prazo_recebimento_dias' => $prazoRecebimentoDias,
            'data_prevista_repasse' => $dataPrevista,
            'data_prevista_recebimento' => $dataPrevista,
            'data_credito_efetivo' => null,
            'operadora_id' => $operadora?->id,
            'operadora_nome' => (string) ($operadora?->nome ?? ''),
            'bandeira_id' => $bandeira?->id,
            'bandeira_nome' => (string) ($bandeira?->nome ?? ''),
            'taxa_id' => (int) $taxa->id,
        ];
    }

    public function normalizeModalidade(string $modalidade = '', string $formaPagamento = ''): string
    {
        $normalized = strtolower(trim($modalidade));
        if (in_array($normalized, [FinanceiroCartaoTaxa::MODALIDADE_CREDITO, FinanceiroCartaoTaxa::MODALIDADE_DEBITO], true)) {
            return $normalized;
        }

        return match (strtolower(trim($formaPagamento))) {
            'cartao_debito' => FinanceiroCartaoTaxa::MODALIDADE_DEBITO,
            'cartao_credito' => FinanceiroCartaoTaxa::MODALIDADE_CREDITO,
            default => '',
        };
    }

    public function findApplicableRate(int $operadoraId, string $modalidade, int $parcelas, ?int $bandeiraId = null): ?FinanceiroCartaoTaxa
    {
        $rows = FinanceiroCartaoTaxa::query()
            ->active()
            ->where('operadora_id', $operadoraId)
            ->where('modalidade', $modalidade)
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $parcelas = max(1, $parcelas);

        $rows = $rows->filter(function (FinanceiroCartaoTaxa $row) use ($parcelas, $bandeiraId): bool {
            $inicio = max(1, (int) $row->parcelas_inicial);
            $fim = max($inicio, (int) ($row->parcelas_final ?: $inicio));
            $taxaBandeiraId = $row->bandeira_id;

            if ($parcelas < $inicio || $parcelas > $fim) {
                return false;
            }

            if ($taxaBandeiraId === null) {
                return true;
            }

            return $bandeiraId !== null && $taxaBandeiraId === $bandeiraId;
        })->values();

        if ($rows->isEmpty()) {
            return null;
        }

        $sorted = $rows->sort(function (FinanceiroCartaoTaxa $left, FinanceiroCartaoTaxa $right) use ($bandeiraId): int {
            $leftSpecific = $bandeiraId !== null && $left->bandeira_id !== null ? 1 : 0;
            $rightSpecific = $bandeiraId !== null && $right->bandeira_id !== null ? 1 : 0;

            if ($leftSpecific !== $rightSpecific) {
                return $rightSpecific <=> $leftSpecific;
            }

            $leftRange = max(1, (int) ($left->parcelas_final ?: 1)) - max(1, (int) $left->parcelas_inicial);
            $rightRange = max(1, (int) ($right->parcelas_final ?: 1)) - max(1, (int) $right->parcelas_inicial);

            if ($leftRange !== $rightRange) {
                return $leftRange <=> $rightRange;
            }

            return $left->id <=> $right->id;
        })->values();

        return $sorted->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildActiveDataset(): array
    {
        return [
            'operadoras' => FinanceiroCartaoOperadora::query()->active()->orderBy('ordem_exibicao')->get()
                ->map(static fn (FinanceiroCartaoOperadora $op): array => [
                    'id' => $op->id,
                    'nome' => (string) $op->nome,
                    'prazo_padrao_dias' => (int) $op->prazo_padrao_dias,
                ])->values()->all(),
            'bandeiras' => FinanceiroCartaoBandeira::query()->active()->orderBy('ordem_exibicao')->get()
                ->map(static fn (FinanceiroCartaoBandeira $bandeira): array => [
                    'id' => $bandeira->id,
                    'nome' => (string) $bandeira->nome,
                ])->values()->all(),
            'taxas' => FinanceiroCartaoTaxa::query()->active()->get()
                ->map(static fn (FinanceiroCartaoTaxa $taxa): array => [
                    'id' => $taxa->id,
                    'operadora_id' => $taxa->operadora_id,
                    'bandeira_id' => $taxa->bandeira_id,
                    'modalidade' => (string) $taxa->modalidade,
                    'parcelas_inicial' => (int) $taxa->parcelas_inicial,
                    'parcelas_final' => (int) $taxa->parcelas_final,
                    'taxa_percentual' => round((float) $taxa->taxa_percentual, 4),
                    'taxa_fixa' => round((float) $taxa->taxa_fixa, 2),
                    'prazo_recebimento_dias' => (int) $taxa->prazo_recebimento_dias,
                ])->values()->all(),
        ];
    }
}
