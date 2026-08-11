<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O bloco de condições comerciais do orçamento é um contrato entre a view e o
 * JS: a Blade emite marcadores (`data-budget-*`) que `orcamentos-form.js`
 * consulta para mostrar/esconder campos e para montar a revisão final.
 *
 * Renomear um marcador de um lado sem o outro não quebra teste de request
 * nenhum — a tela simplesmente para de reagir, ou a revisão final volta a
 * exibir "Nao informado". Estes testes prendem os dois lados juntos.
 */
class BudgetCommercialTermsAssetsTest extends TestCase
{
    private function desktopPath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.ltrim($relative, '/');
    }

    private function formView(): string
    {
        return (string) file_get_contents($this->desktopPath('resources/views/orcamentos/form.blade.php'));
    }

    private function formScript(): string
    {
        return (string) file_get_contents($this->desktopPath('public/assets/js/orcamentos-form.js'));
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function markerProvider(): array
    {
        return [
            // Bloco no formulário.
            ['data-budget-terms'],
            ['data-budget-payment-method'],
            ['data-budget-installments'],
            ['data-budget-installments-wrapper'],
            ['data-budget-pix-preview'],
            // Rótulo isolado da chave: mantém o badge "Principal" e o texto de
            // estado vazio fora do que vai para a revisão final.
            ['data-budget-pix-key'],
            // Destino na revisão final.
            ['data-budget-review-terms'],
        ];
    }

    #[DataProvider('markerProvider')]
    public function test_marker_exists_in_both_the_view_and_the_script(string $marker): void
    {
        $this->assertStringContainsString(
            $marker,
            $this->formView(),
            sprintf('O marcador "%s" sumiu de orcamentos/form.blade.php.', $marker)
        );

        $this->assertStringContainsString(
            $marker,
            $this->formScript(),
            sprintf('O marcador "%s" não é consultado por orcamentos-form.js.', $marker)
        );
    }

    public function test_payment_methods_field_is_submitted_as_an_array_with_an_empty_marker(): void
    {
        $view = $this->formView();

        $this->assertStringContainsString(
            'name="formas_pagamento[]"',
            $view,
            'As formas de pagamento precisam ser enviadas como array.'
        );

        // Sem o marcador vazio, desmarcar tudo omite o campo do request e o
        // backend preserva a seleção anterior em vez de limpá-la.
        $this->assertMatchesRegularExpression(
            '/<input type="hidden" name="formas_pagamento\[\]" value="">/',
            $view,
            'O marcador vazio de formas_pagamento[] é obrigatório para permitir desmarcar tudo.'
        );
    }

    public function test_draft_state_keeps_multi_value_groups_as_a_list(): void
    {
        $script = $this->formScript();

        // Sem o tratamento de grupos "[]", todas as caixas colapsariam na mesma
        // chave do rascunho e a seleção seria perdida ao restaurar.
        $this->assertStringContainsString(
            "endsWith('[]')",
            $script,
            'collectState precisa tratar grupos de múltipla escolha como lista.'
        );

        $this->assertStringContainsString(
            'Array.isArray(value)',
            $script,
            'restoreState precisa reaplicar listas de valores nas caixas do grupo.'
        );
    }

    public function test_warranty_terms_offered_in_the_form_match_the_backend_list(): void
    {
        $view = $this->formView();

        // A lista é fechada no backend (Budget::WARRANTY_TERMS) e o select é
        // montado a partir do catálogo — a view não pode passar a chumbar
        // prazos próprios.
        $this->assertStringContainsString('name="garantia_dias"', $view);
        $this->assertStringContainsString('$warrantyOptions', $view);
        $this->assertStringNotContainsString('value="45"', $view);
    }
}
