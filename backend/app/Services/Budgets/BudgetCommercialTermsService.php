<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetLevelPaymentMethod;
use App\Models\BudgetLevelTerms;
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
 *
 * Orçamento em níveis de manutenção: os campos do orçamento são o PADRÃO e
 * cada nível pode sobrescrever garantia, parcelamento, formas de pagamento e
 * entrega em domicílio de forma independente (`orcamento_nivel_condicoes` /
 * `orcamento_nivel_formas_pagamento`), além de ter uma lista livre de
 * diferenciais. forBudget() recebe o nível e resolve o efetivo; depois da
 * aprovação resolve sozinho para o nível aprovado, e o efetivo desse nível é
 * copiado para as colunas base (collapseApprovedLevel), para OS/revisão/baixa
 * continuarem lendo `orcamentos.*` direto sem saber que existiu nível.
 */
class BudgetCommercialTermsService
{
    /**
     * Código de sistema da forma Pix (ver migration
     * 2026_07_21_000001_create_financeiro_formas_pagamento_table).
     */
    private const PIX_CODE = 'pix';

    /**
     * Texto único da entrega em domicílio (tela, link público e PDF).
     */
    public const ENTREGA_DOMICILIO_LABEL = 'Entrega no seu endereço';

    public const ENTREGA_DOMICILIO_TEXTO = 'Entrega do equipamento reparado no endereço do cliente, sem custo adicional.';

    /**
     * Teto de diferenciais livres por nível — é uma lista de destaque no
     * cartão, não um campo de observações.
     */
    public const MAX_BENEFICIOS_POR_NIVEL = 10;

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
     * `$nivel` é a opção de manutenção sendo exibida (orçamento em níveis,
     * antes da decisão). Depois da aprovação o parâmetro é ignorado e vale
     * sempre o nível aprovado; orçamento sem níveis ignora overrides.
     *
     * @return array<string, mixed>
     */
    public function forBudget(Budget $budget, ?int $nivel = null): array
    {
        return $this->buildTerms($budget, $this->resolveEffectiveLevel($budget, $nivel));
    }

    /**
     * Monta as condições para um nível já resolvido (null = só o padrão).
     *
     * @return array<string, mixed>
     */
    private function buildTerms(Budget $budget, ?int $efetivo): array
    {
        $budget->loadMissing('paymentMethods');

        $override = null;
        $formasNivel = collect();

        if ($efetivo !== null) {
            $budget->loadMissing(['levelTerms', 'levelPaymentMethods']);
            $override = $budget->levelTerms->firstWhere('nivel', $efetivo);
            $formasNivel = $budget->levelPaymentMethods->where('nivel', $efetivo);
        }

        $formas = ($formasNivel->isNotEmpty() ? $formasNivel : $budget->paymentMethods)
            ->sortBy('ordem')
            ->map(static fn (BudgetPaymentMethod|BudgetLevelPaymentMethod $forma): array => [
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

        // `??` é o operador certo: só null cai no padrão; false gravado no
        // nível ("sem entrega, mesmo que o padrão inclua") é preservado.
        $garantiaDias = $this->normalizeWarrantyDays($override?->garantia_dias ?? $budget->garantia_dias);
        $garantiaLabel = Budget::warrantyLabel($garantiaDias);
        $parcelas = $this->normalizeInstallments($override?->parcelas_sem_juros ?? $budget->parcelas_sem_juros, $codes);
        $entrega = (bool) ($override?->entrega_domicilio ?? $budget->entrega_domicilio);
        $beneficios = $this->normalizeBenefits($override?->beneficios ?? []);
        $complemento = trim((string) ($budget->condicoes ?? ''));

        $terms = [
            'nivel' => $efetivo,
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
            'entrega_domicilio' => $entrega,
            'entrega_domicilio_label' => $entrega ? self::ENTREGA_DOMICILIO_LABEL : '',
            'entrega_domicilio_texto' => $entrega ? self::ENTREGA_DOMICILIO_TEXTO : '',
            'beneficios' => $beneficios,
            'beneficios_texto' => implode("\n", $beneficios),
            'complemento' => $complemento,
        ];

        $terms['resumo'] = $this->summaryText($terms);
        $terms['tem_conteudo'] = $formas->isNotEmpty()
            || $garantiaDias !== null
            || $entrega
            || $beneficios !== []
            || $complemento !== '';

        return $terms;
    }

    /**
     * Condições efetivas de cada nível oferecido (chave = nível). Vazio para
     * orçamento sem níveis ou já decidido.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forEachLevel(Budget $budget): array
    {
        if (! $budget->hasTiers()) {
            return [];
        }

        $budget->loadMissing(['paymentMethods', 'levelTerms', 'levelPaymentMethods']);

        $porNivel = [];
        for ($nivel = Budget::NIVEL_MINIMO; $nivel <= $budget->maxLevel(); $nivel++) {
            $porNivel[$nivel] = $this->forBudget($budget, $nivel);
        }

        return $porNivel;
    }

    /**
     * Algum nível diverge de outro neste campo? Decide se a condição pode ser
     * dita uma vez só ("em qualquer opção") ou precisa aparecer por cartão.
     *
     * @param  array<int, array<string, mixed>>  $perLevelTerms
     */
    public static function diffAcrossLevels(array $perLevelTerms, string $field): bool
    {
        return collect($perLevelTerms)
            ->map(static fn (array $terms): string => (string) ($terms[$field] ?? ''))
            ->unique()
            ->count() > 1;
    }

    /**
     * O que está GRAVADO por nível (não o efetivo): é o que o formulário usa
     * para saber o que foi personalizado, sem pré-preencher com o herdado.
     *
     * @return array<int, array{garantia_dias: ?int, garantia_label: string, parcelas_sem_juros: ?int, entrega_domicilio: ?bool, formas_pagamento: array<int, string>, beneficios: array<int, string>}>
     */
    public function overridesFor(Budget $budget): array
    {
        $budget->loadMissing(['levelTerms', 'levelPaymentMethods']);

        $overrides = [];
        foreach ($budget->levelTerms->sortBy('nivel') as $linha) {
            $nivel = (int) $linha->nivel;
            $overrides[$nivel] = [
                'garantia_dias' => $linha->garantia_dias !== null ? (int) $linha->garantia_dias : null,
                'garantia_label' => $linha->garantia_dias !== null ? Budget::warrantyLabel((int) $linha->garantia_dias) : '',
                'parcelas_sem_juros' => $linha->parcelas_sem_juros !== null ? (int) $linha->parcelas_sem_juros : null,
                'entrega_domicilio' => $linha->entrega_domicilio !== null ? (bool) $linha->entrega_domicilio : null,
                'formas_pagamento' => $budget->levelPaymentMethods
                    ->where('nivel', $nivel)
                    ->sortBy('ordem')
                    ->pluck('forma_codigo')
                    ->map(static fn (mixed $codigo): string => (string) $codigo)
                    ->values()
                    ->all(),
                'beneficios' => $this->normalizeBenefits($linha->beneficios ?? []),
            ];
        }

        return $overrides;
    }

    /**
     * Grava as personalizações por nível vindas do formulário.
     *
     * Payload indexado pelo nível (`[2 => [...], 3 => [...]]`); um item pode
     * também trazer `nivel` dentro. Campo vazio/nulo = herda; nível cujos
     * cinco campos chegam vazios perde a linha (volta a herdar tudo). Sem
     * níveis nos itens não há o que personalizar: apaga tudo.
     *
     * @param  array<int|string, mixed>  $niveisPayload
     */
    public function syncLevelOverrides(Budget $budget, array $niveisPayload): void
    {
        $budget->unsetRelation('items');
        $maxLevel = $budget->maxLevel();
        $porNivel = $this->indexPayloadByLevel($niveisPayload);

        for ($nivel = Budget::NIVEL_MINIMO; $nivel <= Budget::NIVEL_MAXIMO; $nivel++) {
            $linha = $porNivel[$nivel] ?? null;

            if ($nivel > $maxLevel || $maxLevel <= Budget::NIVEL_MINIMO || ! is_array($linha)) {
                $this->deleteLevelOverride($budget, $nivel);

                continue;
            }

            $garantiaDias = $this->normalizeWarrantyDays($linha['garantia_dias'] ?? null);
            $formasPagamento = $this->normalizeCodes(is_array($linha['formas_pagamento'] ?? null) ? $linha['formas_pagamento'] : []);
            // Parcelamento é validado contra as formas EFETIVAS do nível: se o
            // nível não trocou as formas, valem as do orçamento.
            $codigosEfetivos = $formasPagamento !== [] ? $formasPagamento : $this->baseCodes($budget);
            $parcelas = $this->normalizeInstallments($linha['parcelas_sem_juros'] ?? null, $codigosEfetivos);
            $entrega = self::normalizeTriState($linha['entrega_domicilio'] ?? null);
            $beneficios = $this->normalizeBenefits($linha['beneficios'] ?? []);

            $vazio = $garantiaDias === null
                && $parcelas === null
                && $entrega === null
                && $formasPagamento === []
                && $beneficios === [];

            if ($vazio) {
                $this->deleteLevelOverride($budget, $nivel);

                continue;
            }

            BudgetLevelTerms::query()->updateOrCreate(
                ['orcamento_id' => (int) $budget->id, 'nivel' => $nivel],
                [
                    'garantia_dias' => $garantiaDias,
                    'parcelas_sem_juros' => $parcelas,
                    'entrega_domicilio' => $entrega,
                    'beneficios' => $beneficios !== [] ? $beneficios : null,
                ]
            );

            $this->syncLevelPaymentMethods($budget, $nivel, $formasPagamento);
        }

        $budget->unsetRelation('levelTerms')->unsetRelation('levelPaymentMethods');
    }

    /**
     * Na aprovação, o efetivo do nível escolhido vira o valor do orçamento.
     *
     * Mesma ideia da poda de itens: a OS (`linkBudgetToOrder`), a baixa
     * (`suggestedWarrantyDays`) e a revisão (`cloneCommercialTerms`) leem
     * `orcamentos.*`/`orcamento_formas_pagamento` direto, então as colunas
     * base precisam ser a verdade a partir daqui. Os campos estruturados do
     * override são zerados (uma edição posterior do orçamento convertido não
     * pode ficar mascarada por eles); a lista de diferenciais não tem coluna
     * base e continua no nível, lido via forBudget(). Níveis não escolhidos
     * ficam como estão: ninguém mais resolve para eles.
     */
    public function collapseApprovedLevel(Budget $budget, int $nivel): void
    {
        // Direto pelo nível (não por resolveEffectiveLevel): neste ponto os
        // itens acima do nível já foram podados e nivel_aprovado ainda não
        // foi gravado, então hasTiers() não é confiável.
        $budget->unsetRelation('levelTerms')->unsetRelation('levelPaymentMethods')->unsetRelation('paymentMethods');
        $terms = $this->buildTerms($budget, $nivel);

        $budget->forceFill([
            'garantia_dias' => $terms['garantia_dias'],
            'parcelas_sem_juros' => $terms['parcelas_sem_juros'],
            'entrega_domicilio' => (bool) $terms['entrega_domicilio'],
        ])->save();

        $this->syncPaymentMethods($budget, array_column($terms['formas_pagamento'], 'codigo'));

        BudgetLevelPaymentMethod::query()
            ->where('orcamento_id', (int) $budget->id)
            ->where('nivel', $nivel)
            ->delete();

        $override = BudgetLevelTerms::query()
            ->where('orcamento_id', (int) $budget->id)
            ->where('nivel', $nivel)
            ->first();

        if ($override instanceof BudgetLevelTerms) {
            if ($this->normalizeBenefits($override->beneficios ?? []) === []) {
                $override->delete();
            } else {
                $override->forceFill([
                    'garantia_dias' => null,
                    'parcelas_sem_juros' => null,
                    'entrega_domicilio' => null,
                ])->save();
            }
        }

        $budget->unsetRelation('levelTerms')->unsetRelation('levelPaymentMethods');
    }

    /**
     * Sim/não/herda vindo de formulário ou API: `''`/null = herda (null);
     * true/'1'/'sim' = true; false/'0'/'nao' = false.
     */
    public static function normalizeTriState(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalizado = strtolower(trim((string) $value));

        return match ($normalizado) {
            '1', 'true', 'sim', 'on', 'yes' => true,
            '0', 'false', 'nao', 'não', 'off', 'no' => false,
            default => null,
        };
    }

    /**
     * Lista de diferenciais: uma linha por item, sem vazios/repetidos, com
     * teto de itens e tamanho.
     *
     * @param  mixed  $value  array de strings ou texto com uma linha por item
     * @return array<int, string>
     */
    public function normalizeBenefits(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(static fn (mixed $item): string => mb_substr(trim((string) $item), 0, 120))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->take(self::MAX_BENEFICIOS_POR_NIVEL)
            ->values()
            ->all();
    }

    /**
     * Depois da aprovação vale só o nível aprovado (mesmo gate de
     * Budget::hasTiers()); sem níveis não há override a consultar; antes da
     * decisão vale o nível pedido, se existir.
     */
    private function resolveEffectiveLevel(Budget $budget, ?int $nivel): ?int
    {
        $aprovado = Budget::normalizeLevel($budget->nivel_aprovado);
        if ($aprovado !== null) {
            return $aprovado;
        }

        if (! $budget->hasTiers()) {
            return null;
        }

        $solicitado = Budget::normalizeLevel($nivel);

        return $solicitado !== null && $solicitado <= $budget->maxLevel() ? $solicitado : null;
    }

    /**
     * @param  array<int|string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function indexPayloadByLevel(array $payload): array
    {
        $porNivel = [];
        foreach ($payload as $chave => $linha) {
            if (! is_array($linha)) {
                continue;
            }

            $nivel = Budget::normalizeLevel($linha['nivel'] ?? $chave);
            if ($nivel !== null) {
                $porNivel[$nivel] = $linha;
            }
        }

        return $porNivel;
    }

    private function deleteLevelOverride(Budget $budget, int $nivel): void
    {
        BudgetLevelPaymentMethod::query()
            ->where('orcamento_id', (int) $budget->id)
            ->where('nivel', $nivel)
            ->delete();

        BudgetLevelTerms::query()
            ->where('orcamento_id', (int) $budget->id)
            ->where('nivel', $nivel)
            ->delete();
    }

    /**
     * @param  array<int, string>  $codes  já normalizados; vazio = herda a base
     */
    private function syncLevelPaymentMethods(Budget $budget, int $nivel, array $codes): void
    {
        $base = BudgetLevelPaymentMethod::query()
            ->where('orcamento_id', (int) $budget->id)
            ->where('nivel', $nivel);

        if ($codes === []) {
            $base->delete();

            return;
        }

        (clone $base)->whereNotIn('forma_codigo', $codes)->delete();

        $catalog = FinanceiroFormaPagamento::catalog()->keyBy('codigo');

        foreach (array_values($codes) as $ordem => $codigo) {
            $forma = $catalog->get($codigo);

            BudgetLevelPaymentMethod::query()->updateOrCreate(
                ['orcamento_id' => (int) $budget->id, 'nivel' => $nivel, 'forma_codigo' => $codigo],
                [
                    'forma_pagamento_id' => $forma instanceof FinanceiroFormaPagamento ? (int) $forma->id : null,
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
    }

    /**
     * @return array<int, string>
     */
    private function baseCodes(Budget $budget): array
    {
        $budget->loadMissing('paymentMethods');

        return $budget->paymentMethods
            ->sortBy('ordem')
            ->pluck('forma_codigo')
            ->map(static fn (mixed $codigo): string => (string) $codigo)
            ->values()
            ->all();
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

        if (($terms['entrega_domicilio_texto'] ?? '') !== '') {
            $linhas[] = $terms['entrega_domicilio_texto'];
        }

        if (($terms['beneficios_texto'] ?? '') !== '') {
            $linhas[] = 'Diferenciais desta opção:'."\n".$terms['beneficios_texto'];
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
