<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\FinanceiroService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class FinanceiroCatalogController extends DesktopController
{
    public function __construct(
        private readonly FinanceiroService $financeiroService
    ) {
    }

    public function index(): View
    {
        $catalogo = $this->financeiroService->catalogo();

        return view('financeiro.configuracoes', [
            'pageTitle' => 'Configurações financeiras',
            'categorias' => $catalogo['categorias'],
            'dreGrupos' => $catalogo['dre_grupos'],
            'dreSubgrupos' => $catalogo['dre_subgrupos'],
            'comissoesTecnicos' => $catalogo['comissoes_tecnicos'],
            'comissaoPercentualPadrao' => $catalogo['comissao_percentual_padrao'],
            'formasPagamento' => $catalogo['formas_pagamento'],
        ]);
    }

    public function saveFormaPagamento(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'nome' => ['required', 'string', 'max:60'],
            'is_cartao' => ['nullable', 'boolean'],
            'ordem_exibicao' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'nome' => 'nome da forma de pagamento',
        ]);

        $id = (int) ($validated['id'] ?? 0);
        unset($validated['id']);
        $validated['is_cartao'] = $request->boolean('is_cartao');
        $validated['ativo'] = $request->boolean('ativo', true);

        return $this->saveCatalogItem(
            fn () => $id > 0
                ? $this->financeiroService->updateFormaPagamento($id, $validated)
                : $this->financeiroService->createFormaPagamento($validated),
            'Forma de pagamento salva com sucesso.'
        );
    }

    public function deleteFormaPagamento(int $formaPagamento): RedirectResponse
    {
        return $this->deleteCatalogItem(
            fn () => $this->financeiroService->destroyFormaPagamento($formaPagamento),
            'Forma de pagamento excluída com sucesso.'
        );
    }

    public function saveComissao(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'tecnico_id' => ['required', 'integer', 'min:1'],
            'percentual_padrao' => ['required', 'numeric', 'min:0', 'max:100'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        $id = (int) ($validated['id'] ?? 0);
        unset($validated['id']);
        $validated['ativo'] = $request->boolean('ativo', true);

        return $this->saveCatalogItem(
            fn () => $id > 0
                ? $this->financeiroService->updateComissao($id, $validated)
                : $this->financeiroService->createComissao($validated),
            'Comissão do técnico salva com sucesso.'
        );
    }

    public function deleteComissao(int $comissao): RedirectResponse
    {
        return $this->deleteCatalogItem(fn () => $this->financeiroService->destroyComissao($comissao), 'Comissão do técnico excluída com sucesso.');
    }

    public function saveComissaoPadrao(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'percentual_padrao' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        return $this->saveCatalogItem(
            fn () => $this->financeiroService->updateComissaoPadrao((float) $validated['percentual_padrao']),
            'Percentual padrão de comissão atualizado com sucesso.'
        );
    }

    public function saveCategoria(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'nome' => ['required', 'string', 'max:100'],
            'tipo' => ['required', 'string', 'in:receber,pagar,ambos'],
            'dre_grupo_id' => ['nullable', 'integer', 'min:1'],
            'dre_subgrupo_id' => ['nullable', 'integer', 'min:1'],
            'impacta_dre_padrao' => ['nullable', 'boolean'],
            'impacta_fluxo_caixa_padrao' => ['nullable', 'boolean'],
            'dre_fixo_mensal_padrao' => ['nullable', 'boolean'],
            'ordem_exibicao' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        $id = (int) ($validated['id'] ?? 0);
        unset($validated['id']);
        $validated['impacta_dre_padrao'] = $request->boolean('impacta_dre_padrao');
        $validated['impacta_fluxo_caixa_padrao'] = $request->boolean('impacta_fluxo_caixa_padrao');
        $validated['dre_fixo_mensal_padrao'] = $request->boolean('dre_fixo_mensal_padrao');
        $validated['ativo'] = $request->boolean('ativo', true);

        return $this->saveCatalogItem(
            fn () => $id > 0
                ? $this->financeiroService->updateCategoria($id, $validated)
                : $this->financeiroService->createCategoria($validated),
            'Categoria financeira salva com sucesso.'
        );
    }

    public function deleteCategoria(int $categoria): RedirectResponse
    {
        return $this->deleteCatalogItem(fn () => $this->financeiroService->destroyCategoria($categoria), 'Categoria financeira excluída com sucesso.');
    }

    public function saveGrupo(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'nome' => ['required', 'string', 'max:80'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'ordem_exibicao' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        $id = (int) ($validated['id'] ?? 0);
        unset($validated['id']);
        $validated['ativo'] = $request->boolean('ativo', true);

        return $this->saveCatalogItem(
            fn () => $id > 0
                ? $this->financeiroService->updateGrupo($id, $validated)
                : $this->financeiroService->createGrupo($validated),
            'Grupo DRE salvo com sucesso.'
        );
    }

    public function deleteGrupo(int $grupo): RedirectResponse
    {
        return $this->deleteCatalogItem(fn () => $this->financeiroService->destroyGrupo($grupo), 'Grupo DRE excluído com sucesso.');
    }

    public function saveSubgrupo(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'grupo_id' => ['required', 'integer', 'min:1'],
            'nome' => ['required', 'string', 'max:100'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'ordem_exibicao' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        $id = (int) ($validated['id'] ?? 0);
        unset($validated['id']);
        $validated['ativo'] = $request->boolean('ativo', true);

        return $this->saveCatalogItem(
            fn () => $id > 0
                ? $this->financeiroService->updateSubgrupo($id, $validated)
                : $this->financeiroService->createSubgrupo($validated),
            'Subgrupo DRE salvo com sucesso.'
        );
    }

    public function deleteSubgrupo(int $subgrupo): RedirectResponse
    {
        return $this->deleteCatalogItem(fn () => $this->financeiroService->destroySubgrupo($subgrupo), 'Subgrupo DRE excluído com sucesso.');
    }

    private function saveCatalogItem(callable $action, string $successMessage): RedirectResponse
    {
        try {
            $action();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('financeiro.configuracoes')->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return redirect()->route('financeiro.configuracoes')->with('error', 'Verifique os dados informados.');
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('financeiro.configuracoes')->with('error', 'Não foi possível salvar agora. Tente novamente.');
        }

        return redirect()->route('financeiro.configuracoes')->with('success', $successMessage);
    }

    private function deleteCatalogItem(callable $action, string $successMessage): RedirectResponse
    {
        try {
            $action();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('financeiro.configuracoes')->with('error', $exception->getMessage());
        }

        return redirect()->route('financeiro.configuracoes')->with('success', $successMessage);
    }
}
