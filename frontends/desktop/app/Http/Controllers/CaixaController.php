<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\CaixaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Throwable;

/**
 * Turnos de caixa — specs/028-caixa-sessoes/spec.md.
 */
class CaixaController extends DesktopController
{
    public function __construct(
        private readonly CaixaService $caixaService
    ) {
    }

    /**
     * Tela do turno: caixa fechado mostra o botão de abrir; aberto mostra
     * totais, movimentos e as ações de sangria, suprimento e fechamento.
     */
    public function index(): View|RedirectResponse
    {
        try {
            $current = $this->caixaService->current();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            $current = [];
        }

        return view('caixa.index', [
            'pageTitle' => 'Caixa',
            'conta' => $current['conta'] ?? null,
            'sessao' => $current['sessao'] ?? null,
            'contasDestino' => $current['contas_destino'] ?? [],
        ]);
    }

    public function history(Request $request): View|RedirectResponse
    {
        $filters = [
            'status' => trim((string) $request->query('status', '')),
            'operador_id' => (int) $request->query('operador_id', 0),
            'data_inicio' => trim((string) $request->query('data_inicio', '')),
            'data_fim' => trim((string) $request->query('data_fim', '')),
            'com_diferenca' => $request->boolean('com_diferenca'),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        try {
            $result = $this->caixaService->paginate(array_filter(
                $filters,
                static fn ($value): bool => $value !== '' && $value !== 0 && $value !== false
            ));
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            $result = ['items' => [], 'pagination' => []];
        }

        return view('caixa.historico', [
            'pageTitle' => 'Histórico de caixa',
            'sessoes' => $result['items'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
        ]);
    }

    public function show(int $sessao): View|RedirectResponse
    {
        try {
            $session = $this->caixaService->find($sessao);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('caixa.historico')->with('error', 'Não foi possível carregar este caixa.');
        }

        if ((int) ($session['id'] ?? 0) <= 0) {
            return redirect()->route('caixa.historico')->with('error', 'Sessão de caixa não encontrada.');
        }

        return view('caixa.show', [
            'pageTitle' => 'Caixa #'.$session['id'],
            'sessao' => $session,
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'valor_abertura' => ['required', 'string', 'max:20'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
        ], [], ['valor_abertura' => 'valor de abertura']);

        return $this->run(
            fn () => $this->caixaService->open($this->normalizeMoneyPayload($validated, ['valor_abertura'])),
            'Caixa aberto.'
        );
    }

    public function storeMovement(Request $request, int $sessao): RedirectResponse
    {
        $validated = $request->validate([
            'tipo' => ['required', 'string', 'in:sangria,suprimento'],
            'valor' => ['required', 'string', 'max:20'],
            'motivo' => ['required', 'string', 'min:3', 'max:255'],
            'conta_destino_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'motivo.required' => 'Descreva o motivo do movimento.',
        ], [
            'valor' => 'valor',
            'motivo' => 'motivo',
            'conta_destino_id' => 'conta de destino',
        ]);

        $label = $validated['tipo'] === 'sangria' ? 'Sangria registrada.' : 'Suprimento registrado.';

        return $this->run(
            fn () => $this->caixaService->storeMovement($sessao, $this->normalizeMoneyPayload($validated, ['valor'])),
            $label
        );
    }

    public function updateOpening(Request $request, int $sessao): RedirectResponse
    {
        $validated = $request->validate([
            'valor_abertura' => ['required', 'string', 'max:20'],
        ], [], ['valor_abertura' => 'valor de abertura']);

        return $this->run(
            fn () => $this->caixaService->updateOpening($sessao, $this->normalizeMoneyPayload($validated, ['valor_abertura'])),
            'Valor de abertura corrigido.'
        );
    }

    public function close(Request $request, int $sessao): RedirectResponse
    {
        $validated = $request->validate([
            'valor_informado' => ['required', 'string', 'max:20'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
        ], [
            'valor_informado.required' => 'Informe o valor contado na gaveta.',
        ], ['valor_informado' => 'valor contado']);

        try {
            $session = $this->caixaService->close($sessao, $this->normalizeMoneyPayload($validated, ['valor_informado']));
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Não foi possível fechar o caixa agora. Tente novamente.');
        }

        // O comparativo só existe depois da contagem — é o resultado da
        // conferência, e é para a tela de detalhe que o operador vai vê-lo.
        return redirect()
            ->route('caixa.show', $sessao)
            ->with('success', 'Caixa fechado. Confira a diferença apurada abaixo.');
    }

    public function reopen(Request $request, int $sessao): RedirectResponse
    {
        $validated = $request->validate([
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'max:255'],
        ], [], [
            'admin_email' => 'e-mail do administrador',
            'admin_password' => 'senha do administrador',
        ]);

        try {
            $this->caixaService->reopen($sessao, $validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('caixa.show', $sessao)->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('caixa.show', $sessao)->with('error', 'Não foi possível reabrir este caixa.');
        }

        return redirect()->route('caixa.index')->with('success', 'Caixa reaberto.');
    }

    public function report(Request $request, int $sessao): Response|RedirectResponse
    {
        $format = (string) $request->query('formato', '80mm');
        $format = in_array($format, ['80mm', 'a4'], true) ? $format : '80mm';

        try {
            $download = $this->caixaService->report($sessao, $format);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('caixa.show', $sessao)->with('error', 'Não foi possível gerar o relatório.');
        }

        return response($download['body'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="caixa-'.$sessao.'.pdf"',
        ]);
    }

    public function help(): View
    {
        return view('caixa.help', ['pageTitle' => 'Ajuda do caixa']);
    }

    /**
     * Executa a ação e traduz as falhas da API no padrão de flash do desktop.
     *
     * @param  callable(): mixed  $action
     */
    private function run(callable $action, string $successMessage): RedirectResponse
    {
        try {
            $action();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('caixa.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Não foi possível concluir a operação agora. Tente novamente.');
        }

        return redirect()->route('caixa.index')->with('success', $successMessage);
    }
}
