<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\FinanceiroCartaoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class FinanceiroCartaoController extends DesktopController
{
    public function __construct(
        private readonly FinanceiroCartaoService $financeiroCartaoService
    ) {
    }

    public function index(Request $request): View|RedirectResponse
    {
        try {
            $dataset = $this->financeiroCartaoService->dataset();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('financeiro.cartoes.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('financeiro.cartoes.index')->with('error', 'Não foi possível carregar Cartões e Taxas agora.');
        }

        return view('financeiro.cartoes', [
            'pageTitle' => 'Cartões e Taxas',
            'activeTab' => (string) $request->query('tab', 'operadoras'),
            'cartoes' => $dataset['cartoes'] ?? [],
            'simulador' => $dataset['cartoes']['simulador_catalogo'] ?? [],
            'gateway' => $dataset['gateway'] ?? [],
        ]);
    }

    public function help(): View|RedirectResponse
    {
        try {
            $dataset = $this->financeiroCartaoService->dataset();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('financeiro.cartoes.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('financeiro.cartoes.index')->with('error', 'Não foi possível abrir a ajuda agora.');
        }

        return view('financeiro.cartoes-help', [
            'pageTitle' => 'Ajuda de Cartões e Taxas',
            'cartoes' => $dataset['cartoes'] ?? [],
            'gateway' => $dataset['gateway'] ?? [],
        ]);
    }

    public function simulate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'valor_bruto' => ['required', 'numeric', 'min:0.01'],
            'operadora_id' => ['required', 'integer', 'min:1'],
            'bandeira_id' => ['nullable', 'integer', 'min:1'],
            'modalidade' => ['nullable', 'string', 'max:20'],
            'forma_pagamento' => ['nullable', 'string', 'max:30'],
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        try {
            $simulation = $this->financeiroCartaoService->simulate($validated);
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->jsonFailure($exception->getMessage() ?: 'Não foi possível simular o recebimento agora.', $exception->statusCode() > 0 ? $exception->statusCode() : 422, $exception->details());
        } catch (Throwable $exception) {
            report($exception);

            return $this->jsonFailure('Não foi possível simular o recebimento agora.', 500);
        }

        return response()->json([
            'success' => true,
            'simulation' => $simulation,
        ]);
    }

    public function saveOperadora(Request $request): RedirectResponse
    {
        $validated = $this->validateOperadora($request);
        $tab = 'operadoras';
        $id = (int) ($validated['id'] ?? 0);
        unset($validated['id']);

        return $this->persist(
            fn () => $id > 0
                ? $this->financeiroCartaoService->updateOperadora($id, $validated)
                : $this->financeiroCartaoService->saveOperadora($validated),
            'Operadora salva com sucesso.',
            $tab,
            'financeiro.cartoes.index'
        );
    }

    public function destroyOperadora(Request $request, int $operadora): RedirectResponse
    {
        return $this->persist(
            fn () => $this->financeiroCartaoService->deleteOperadora($operadora),
            'Operadora desativada com sucesso.',
            'operadoras',
            'financeiro.cartoes.index'
        );
    }

    public function saveBandeira(Request $request): RedirectResponse
    {
        $validated = $this->validateBandeira($request);
        $tab = 'bandeiras';
        $id = (int) ($validated['id'] ?? 0);
        unset($validated['id']);

        return $this->persist(
            fn () => $id > 0
                ? $this->financeiroCartaoService->updateBandeira($id, $validated)
                : $this->financeiroCartaoService->saveBandeira($validated),
            'Bandeira salva com sucesso.',
            $tab,
            'financeiro.cartoes.index'
        );
    }

    public function destroyBandeira(Request $request, int $bandeira): RedirectResponse
    {
        return $this->persist(
            fn () => $this->financeiroCartaoService->deleteBandeira($bandeira),
            'Bandeira desativada com sucesso.',
            'bandeiras',
            'financeiro.cartoes.index'
        );
    }

    public function saveTaxa(Request $request): RedirectResponse
    {
        $validated = $this->validateTaxa($request);
        $tab = 'taxas';
        $id = (int) ($validated['id'] ?? 0);
        unset($validated['id']);

        return $this->persist(
            fn () => $id > 0
                ? $this->financeiroCartaoService->updateTaxa($id, $validated)
                : $this->financeiroCartaoService->saveTaxa($validated),
            'Taxa salva com sucesso.',
            $tab,
            'financeiro.cartoes.index'
        );
    }

    public function destroyTaxa(Request $request, int $taxa): RedirectResponse
    {
        return $this->persist(
            fn () => $this->financeiroCartaoService->deleteTaxa($taxa),
            'Taxa desativada com sucesso.',
            'taxas',
            'financeiro.cartoes.index'
        );
    }

    public function saveGatewayTaxa(Request $request): RedirectResponse
    {
        $validated = $this->validateGatewayTaxa($request);
        $tab = 'gateway';
        $id = (int) ($validated['id'] ?? 0);
        unset($validated['id']);

        return $this->persist(
            fn () => $id > 0
                ? $this->financeiroCartaoService->updateGatewayTaxa($id, $validated)
                : $this->financeiroCartaoService->saveGatewayTaxa($validated),
            'Taxa online salva com sucesso.',
            $tab,
            'financeiro.cartoes.index'
        );
    }

    public function destroyGatewayTaxa(Request $request, int $gatewayTaxa): RedirectResponse
    {
        return $this->persist(
            fn () => $this->financeiroCartaoService->deleteGatewayTaxa($gatewayTaxa),
            'Taxa online desativada com sucesso.',
            'gateway',
            'financeiro.cartoes.index'
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persist(callable $action, string $successMessage, string $tab, string $routeName): RedirectResponse
    {
        try {
            $action();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route($routeName, ['tab' => $tab])->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return redirect()->route($routeName, ['tab' => $tab])->with('error', 'Verifique os dados informados.');
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route($routeName, ['tab' => $tab])->with('error', 'Não foi possível salvar agora. Tente novamente.');
        }

        return redirect()->route($routeName, ['tab' => $tab])->with('success', $successMessage);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateOperadora(Request $request): array
    {
        return $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'nome' => ['required', 'string', 'max:100'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'ordem_exibicao' => ['nullable', 'integer', 'min:0'],
            'prazo_padrao_dias' => ['nullable', 'integer', 'min:0'],
            'ativo' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBandeira(Request $request): array
    {
        return $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'nome' => ['required', 'string', 'max:80'],
            'ordem_exibicao' => ['nullable', 'integer', 'min:0'],
            'ativo' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTaxa(Request $request): array
    {
        return $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'operadora_id' => ['required', 'integer', 'min:1'],
            'bandeira_id' => ['nullable', 'integer', 'min:1'],
            'modalidade' => ['required', 'string', 'in:credito,debito'],
            'parcelas_inicial' => ['required', 'integer', 'min:1', 'max:24'],
            'parcelas_final' => ['required', 'integer', 'min:1', 'max:24'],
            'taxa_percentual' => ['required', 'numeric', 'min:0'],
            'taxa_fixa' => ['required', 'numeric', 'min:0'],
            'prazo_recebimento_dias' => ['required', 'integer', 'min:0'],
            'observacoes' => ['nullable', 'string', 'max:255'],
            'ativo' => ['nullable', 'boolean'],
        ], [
            'parcelas_final.gte' => 'A parcela final deve ser maior ou igual à parcela inicial.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateGatewayTaxa(Request $request): array
    {
        return $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'provider' => ['required', 'string', 'max:40', 'in:asaas,mercado_pago'],
            'modalidade' => ['required', 'string', 'max:40'],
            'taxa_percentual' => ['nullable', 'numeric', 'min:0'],
            'taxa_fixa' => ['nullable', 'numeric', 'min:0'],
            'ordem_exibicao' => ['nullable', 'integer', 'min:0'],
            'observacoes' => ['nullable', 'string', 'max:255'],
            'ativo' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonFailure(string $message, int $status = 422, ?array $details = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'details' => $details,
        ], $status);
    }
}

