<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetPaymentMethod;
use App\Models\FinanceiroChavePix;
use App\Models\FinanceiroFormaPagamento;
use Illuminate\Support\Collection;

/**
 * Condições comerciais do orçamento: formas de pagamento aceitas, chaves Pix,
 * parcelamento sem juros e prazo de garantia.
 *
 * Antes tudo isso era digitado à mão no campo livre `orcamentos.condicoes` a
 * cada orçamento — e por isso quase sempre ficava em branco. Aqui os dados
 * viram estrutura, reaproveitando o catálogo de formas de pagamento e as
 * chaves Pix das configurações financeiras, e é este serviço que monta o texto
 * exibido na tela, no link público e no PDF, para as três superfícies dizerem
 * exatamente a mesma coisa.
 */
class BudgetCommercialTermsService
{
    /**
     * Código de sistema da forma Pix (ver migration
     * 2026_07_21_000001_create_financeiro_formas_pagamento_table).
     */
    private const PIX_CODE = 'pix';

    /**
     * Débito é cartão, mas não parcela: fica fora do texto de parcelamento.
     */
    private const DEBIT_CARD_CODE = 'cartao_debito';

    /**
     * Catálogo para montar o formulário: formas ativas, chaves Pix ativas,
     * prazos de garantia e teto de parcelas.
     *
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        return [
            'formas_pagamento' => FinanceiroFormaPagamento::catalog()
                ->where('ativo', true)
                ->map(static fn (FinanceiroFormaPagamento $forma): array => [
                    'id' => (int) $forma->id,
                    'codigo' => (string) $forma->codigo,
                    'nome' => (string) $forma->nome,
                    'is_cartao' => (bool) $forma->is_cartao,
                    'aceita_parcelamento' => (bool) $forma->is_cartao
                        && (string) $forma->codigo !== self::DEBIT_CARD_CODE,
                    'is_pix' => (string) $forma->codigo === self::PIX_CODE,
                ])
                ->values()
                ->all(),
            'chaves_pix' => FinanceiroChavePix::ativasParaDocumento()
                ->map(fn (FinanceiroChavePix $chave): array => $this->mapPixKey($chave))
                ->values()
                ->all(),
            'garantia_options' => Budget::warrantyOptions(),
            'max_parcelas_sem_juros' => Budget::MAX_INTEREST_FREE_INSTALLMENTS,
        ];
    }

    /**
     * Grava as formas aceitas, congelando código, rótulo e tipo.
     *
     * @param  array<int, string>  $codes
     */
    public function syncPaymentMethods(Budget $budget, array $codes): void
    {
        $codes = $this->normalizeCodes($codes);
        $catalog = FinanceiroFormaPagamento::catalog()->keyBy('codigo');

        BudgetPaymentMethod::query()
            ->where('orcamento_id', (int) $budget->id)
            ->whereNotIn('forma_codigo', $codes !== [] ? $codes : [''])
            ->delete();

        foreach (array_values($codes) as $ordem => $codigo) {
            $forma = $catalog->get($codigo);

            BudgetPaymentMethod::query()->updateOrCreate(
                ['orcamento_id' => (int) $budget->id, 'forma_codigo' => $codigo],
                [
                    'forma_pagamento_id' => $forma instanceof FinanceiroFormaPagamento ? (int) $forma->id : null,
                    // Forma removida do catálogo entre dois saves: preserva o
                    // rótulo que já estava gravado em vez de apagá-lo.
                    'forma_nome' => $forma instanceof FinanceiroFormaPagamento
                        ? (string) $forma->nome
                        : $this->fallbackLabel($budget, $codigo),
                    'is_cartao' => $forma instanceof FinanceiroFormaPagamento
                        ? (bool) $forma->is_cartao
                        : FinanceiroFormaPagamento::isCardCode($codigo),
                    'ordem' => $ordem,
                ]
            );
        }

        $budget->unsetRelation('paymentMethods');
    }

    /**
     * Só aceita códigos do catálogo ativo, sem repetição, na ordem de exibição
     * do catálogo (para o documento nunca sair fora de ordem).
     *
     * @param  array<int, mixed>  $codes
     * @return array<int, string>
     */
    public function normalizeCodes(array $codes): array
    {
        $requested = collect($codes)
            ->map(static fn (mixed $code): string => trim((string) $code))
            ->filter(static fn (string $code): bool => $code !== '')
            ->unique()
            ->all();

        if ($requested === []) {
            return [];
        }

        return FinanceiroFormaPagamento::catalog()
            ->where('ativo', true)
            ->pluck('codigo')
            ->map(static fn (mixed $code): string => (string) $code)
            ->filter(static fn (string $code): bool => in_array($code, $requested, true))
            ->values()
            ->all();
    }

    /**
     * Normaliza o prazo de garantia: só os prazos oferecidos são aceitos.
     */
    public function normalizeWarrantyDays(mixed $value): ?int
    {
        $days = (int) $value;

        return array_key_exists($days, Budget::WARRANTY_TERMS) ? $days : null;
    }

    /**
     * Parcelamento só existe acompanhado de alguma forma de cartão parcelável.
     *
     * @param  array<int, string>  $codes
     */
    public function normalizeInstallments(mixed $value, array $codes): ?int
    {
        $parcelas = (int) $value;

        if ($parcelas < 2 || $parcelas > Budget::MAX_INTEREST_FREE_INSTALLMENTS) {
            return null;
        }

        return $this->hasInstallmentCard($codes) ? $parcelas : null;
    }

    /**
     * Condições completas de um orçamento, prontas para tela, link público e
     * PDF.
     *
     * @return array<string, mixed>
     */
    public function forBudget(Budget $budget): array
    {
        $budget->loadMissing('paymentMethods');

        $formas = $budget->paymentMethods
            ->sortBy('ordem')
            ->map(static fn (BudgetPaymentMethod $forma): array => [
                'codigo' => (string) $forma->forma_codigo,
                'nome' => (string) $forma->forma_nome,
                'is_cartao' => (bool) $forma->is_cartao,
            ])
            ->values();

        $codes = $formas->pluck('codigo')->all();
        $aceitaPix = in_array(self::PIX_CODE, $codes, true);

        // As chaves são resolvidas na leitura, e não congeladas no orçamento:
        // se a empresa trocar de chave, a proposta ainda válida passa a exibir
        // a chave certa em vez de mandar o cliente pagar numa chave morta.
        $chavesPix = $aceitaPix
            ? FinanceiroChavePix::ativasParaDocumento()->map(fn (FinanceiroChavePix $chave): array => $this->mapPixKey($chave))->values()
            : collect();

        $garantiaDias = $this->normalizeWarrantyDays($budget->garantia_dias);
        $garantiaLabel = Budget::warrantyLabel($garantiaDias);
        $parcelas = $this->normalizeInstallments($budget->parcelas_sem_juros, $codes);
        $complemento = trim((string) ($budget->condicoes ?? ''));

        $terms = [
            'formas_pagamento' => $formas->all(),
            'formas_pagamento_texto' => $formas->pluck('nome')->implode(', '),
            'aceita_pix' => $aceitaPix,
            'chaves_pix' => $chavesPix->all(),
            'chaves_pix_texto' => $chavesPix->pluck('rotulo')->implode("\n"),
            'parcelas_sem_juros' => $parcelas,
            'parcelamento_texto' => $this->installmentText($parcelas, $formas),
            'garantia_dias' => $garantiaDias,
            'garantia_label' => $garantiaLabel,
            'garantia_texto' => $this->warrantyText($garantiaLabel),
            'complemento' => $complemento,
        ];

        $terms['resumo'] = $this->summaryText($terms);
        $terms['tem_conteudo'] = $formas->isNotEmpty() || $garantiaDias !== null || $complemento !== '';

        return $terms;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{codigo: string, nome: string, is_cartao: bool}>  $formas
     */
    private function installmentText(?int $parcelas, Collection $formas): string
    {
        if ($parcelas === null) {
            return '';
        }

        $cartoes = $formas
            ->filter(static fn (array $forma): bool => $forma['is_cartao'] && $forma['codigo'] !== self::DEBIT_CARD_CODE)
            ->pluck('nome');

        if ($cartoes->isEmpty()) {
            return '';
        }

        return sprintf('%s em até %dx sem juros.', $cartoes->implode(' / '), $parcelas);
    }

    private function warrantyText(string $garantiaLabel): string
    {
        if ($garantiaLabel === '') {
            return '';
        }

        return sprintf(
            'Garantia de %s sobre os serviços executados e as peças substituídas, contada a partir da data de entrega do equipamento.',
            $garantiaLabel
        );
    }

    /**
     * Bloco de texto único usado onde não cabe layout estruturado (WhatsApp,
     * campo de texto do PDF legado).
     *
     * @param  array<string, mixed>  $terms
     */
    private function summaryText(array $terms): string
    {
        $linhas = [];

        if (($terms['formas_pagamento_texto'] ?? '') !== '') {
            $linhas[] = 'Formas de pagamento aceitas: '.$terms['formas_pagamento_texto'].'.';
        }

        if (($terms['parcelamento_texto'] ?? '') !== '') {
            $linhas[] = $terms['parcelamento_texto'];
        }

        if (($terms['chaves_pix_texto'] ?? '') !== '') {
            $linhas[] = 'Chave Pix para pagamento:'."\n".$terms['chaves_pix_texto'];
        }

        if (($terms['garantia_texto'] ?? '') !== '') {
            $linhas[] = $terms['garantia_texto'];
        }

        if (($terms['complemento'] ?? '') !== '') {
            $linhas[] = $terms['complemento'];
        }

        return implode("\n", $linhas);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPixKey(FinanceiroChavePix $chave): array
    {
        return [
            'id' => (int) $chave->id,
            'tipo' => (string) $chave->tipo,
            'tipo_label' => FinanceiroChavePix::tipoLabel($chave->tipo),
            'chave' => (string) $chave->chave,
            'titular' => (string) ($chave->titular ?? ''),
            'instituicao' => (string) ($chave->instituicao ?? ''),
            'principal' => (bool) $chave->principal,
            'rotulo' => $chave->rotuloCompleto(),
        ];
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function hasInstallmentCard(array $codes): bool
    {
        return FinanceiroFormaPagamento::catalog()
            ->whereIn('codigo', $codes)
            ->contains(static fn (FinanceiroFormaPagamento $forma): bool => (bool) $forma->is_cartao
                && (string) $forma->codigo !== self::DEBIT_CARD_CODE);
    }

    private function fallbackLabel(Budget $budget, string $codigo): string
    {
        $existente = BudgetPaymentMethod::query()
            ->where('orcamento_id', (int) $budget->id)
            ->where('forma_codigo', $codigo)
            ->value('forma_nome');

        $existente = trim((string) ($existente ?? ''));

        return $existente !== '' ? $existente : ucfirst(str_replace('_', ' ', $codigo));
    }
}
