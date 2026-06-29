<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\FinanceiroPrecificacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class FinanceiroPrecificacaoController extends DesktopController
{
    public function __construct(
        private readonly FinanceiroPrecificacaoService $financeiroPrecificacaoService
    ) {
    }

    public function index(Request $request): View|RedirectResponse
    {
        try {
            $precificacao = $this->financeiroPrecificacaoService->dataset();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('financeiro.precificacao.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('financeiro.precificacao.index')->with('error', 'Não foi possível carregar a precificação agora.');
        }

        return view('financeiro.precificacao', [
            'pageTitle' => 'Precificação',
            'activeTab' => (string) $request->query('tab', 'configuracao'),
            'precificacao' => $precificacao,
            'simulation' => session('precificacao_simulation'),
            'simulationType' => session('precificacao_simulation_type'),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $payload = $request->all();

        try {
            $this->financeiroPrecificacaoService->save($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()
                ->route('financeiro.precificacao.index', ['tab' => 'configuracao'])
                ->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput()
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os dados informados.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Não foi possível salvar a precificação agora. Tente novamente.');
        }

        return redirect()
            ->route('financeiro.precificacao.index', ['tab' => 'configuracao'])
            ->with('success', 'Precificação salva com sucesso.');
    }

    public function simulatePeca(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'peca_id' => ['nullable', 'integer', 'min:1'],
            'preco_custo' => ['nullable', 'numeric', 'min:0'],
            'preco_venda' => ['nullable', 'numeric', 'min:0'],
            'categoria' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $simulation = $this->financeiroPrecificacaoService->simulatePeca($validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Não foi possível simular a peça agora.');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'simulation' => $simulation,
            ]);
        }

        return redirect()
            ->route('financeiro.precificacao.index', ['tab' => 'simulador'])
            ->withInput()
            ->with('precificacao_simulation', $simulation)
            ->with('precificacao_simulation_type', 'peca')
            ->with('success', 'Simulação de peça concluída.');
    }

    public function simulateServico(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'servico_id' => ['nullable', 'integer', 'min:1'],
            'tempo_padrao_horas' => ['nullable', 'numeric', 'min:0'],
            'custo_direto_padrao' => ['nullable', 'numeric', 'min:0'],
            'valor_cadastro' => ['nullable', 'numeric', 'min:0'],
            'tipo_equipamento' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $simulation = $this->financeiroPrecificacaoService->simulateServico($validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Não foi possível simular o serviço agora.');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'simulation' => $simulation,
            ]);
        }

        return redirect()
            ->route('financeiro.precificacao.index', ['tab' => 'simulador'])
            ->withInput()
            ->with('precificacao_simulation', $simulation)
            ->with('precificacao_simulation_type', 'servico')
            ->with('success', 'Simulação de serviço concluída.');
    }

    /**
     * @param array<string, mixed>|null $details
     * @return array<string, array<int, string>>
     */
    private function formatApiErrors(ApiRequestException $exception): array
    {
        $details = $exception->details();

        if (! is_array($details)) {
            return [];
        }

        $errors = [];

        foreach ($details as $field => $messages) {
            if (! is_string($field) || ! is_array($messages)) {
                continue;
            }

            $errors[$field] = array_values(array_filter($messages, 'is_string'));
        }

        return $errors;
    }
}
