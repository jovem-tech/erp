<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\FinanceiroContaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class FinanceiroContaController extends DesktopController
{
    private const ACCOUNT_TYPES = ['caixa', 'banco', 'adquirente', 'reserva', 'carteira_digital', 'outra'];

    private const PAYMENT_METHODS = ['dinheiro', 'cartao_credito', 'cartao_debito', 'pix', 'boleto', 'transferencia'];

    public function __construct(
        private readonly FinanceiroContaService $financeiroContaService
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $month = $this->normalizedMonth($request);

        try {
            $dashboard = $this->financeiroContaService->dashboard($month);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('financeiro.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('financeiro.index')->with('error', 'Não foi possível carregar as contas financeiras agora.');
        }

        return view('financeiro.contas.index', [
            'pageTitle' => 'Contas e Saldos',
            'dashboard' => $dashboard,
            'month' => $month,
        ]);
    }

    public function consolidated(Request $request): View|RedirectResponse
    {
        $month = $this->normalizedMonth($request);

        try {
            $report = $this->financeiroContaService->consolidated($month);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('financeiro.contas.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('financeiro.contas.index')->with('error', 'NÃ£o foi possÃ­vel carregar o consolidado de contas e saldos agora.');
        }

        return view('financeiro.contas.consolidado', [
            'pageTitle' => 'Consolidado de Contas e Saldos',
            'report' => $report,
            'month' => $month,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate($this->accountRules(create: true));
        $payload = $this->normalizeMoneyPayload($payload, ['saldo_inicial']);
        $payload['considera_disponivel'] = $request->boolean('considera_disponivel');
        $payload['ativo'] = true;

        return $this->persist(
            fn () => $this->financeiroContaService->create($payload),
            'Conta financeira criada. O saldo inicial não altera o faturamento nem o DRE.'
        );
    }

    public function update(Request $request, int $conta): RedirectResponse
    {
        $payload = $request->validate($this->accountRules(create: false));
        unset($payload['saldo_inicial']);
        $payload['considera_disponivel'] = $request->boolean('considera_disponivel');
        $payload['ativo'] = $request->boolean('ativo');

        return $this->persist(
            fn () => $this->financeiroContaService->update($conta, $payload),
            'Conta financeira atualizada.'
        );
    }

    public function statement(Request $request, int $conta): View|RedirectResponse
    {
        $filters = $request->validate([
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters += [
            'data_inicio' => now()->startOfMonth()->toDateString(),
            'data_fim' => now()->toDateString(),
            'page' => 1,
            'per_page' => 30,
        ];

        try {
            $statement = $this->financeiroContaService->statement($conta, $filters);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('financeiro.contas.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('financeiro.contas.index')->with('error', 'Não foi possível carregar o extrato da conta.');
        }

        return view('financeiro.contas.extrato', [
            'pageTitle' => 'Extrato da Conta',
            'statement' => $statement,
            'filters' => $filters,
        ]);
    }

    public function adjust(Request $request, int $conta): RedirectResponse
    {
        $payload = $request->validate([
            'natureza' => ['required', Rule::in(['entrada', 'saida'])],
            'valor' => ['required', 'string', 'max:30'],
            'data_movimento' => ['required', 'date', 'before_or_equal:today'],
            'descricao' => ['required', 'string', 'min:5', 'max:255'],
            'documento_ref' => ['nullable', 'string', 'max:100'],
        ]);
        $payload = $this->normalizeMoneyPayload($payload, ['valor']);

        return $this->persist(
            fn () => $this->financeiroContaService->adjust($conta, $payload),
            'Ajuste de conciliação registrado sem impacto no faturamento ou DRE.'
        );
    }

    public function transfer(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'conta_origem_id' => ['required', 'integer', 'min:1'],
            'conta_destino_id' => ['required', 'integer', 'different:conta_origem_id'],
            'valor' => ['required', 'string', 'max:30'],
            'data_transferencia' => ['required', 'date', 'before_or_equal:today'],
            'descricao' => ['required', 'string', 'min:3', 'max:255'],
            'documento_ref' => ['nullable', 'string', 'max:100'],
        ]);
        $payload = $this->normalizeMoneyPayload($payload, ['valor']);

        return $this->persist(
            fn () => $this->financeiroContaService->transfer($payload),
            'Transferência registrada. O valor apenas mudou de conta e não entrou no DRE.'
        );
    }

    public function cancelTransfer(Request $request, int $transferencia): RedirectResponse
    {
        $payload = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->persist(
            fn () => $this->financeiroContaService->cancelTransfer($transferencia, $payload['motivo']),
            'Transferência cancelada e saldos estornados.'
        );
    }

    public function confirmCard(Request $request, int $cartao): RedirectResponse
    {
        $payload = $request->validate([
            'data_credito_efetivo' => ['required', 'date', 'before_or_equal:today'],
        ]);

        return $this->persist(
            fn () => $this->financeiroContaService->confirmCard($cartao, $payload['data_credito_efetivo']),
            'Crédito confirmado: o valor líquido agora está disponível na conta.'
        );
    }

    /** @return array<string, array<int, mixed>> */
    private function accountRules(bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';

        return [
            'nome' => [$required, 'string', 'max:100'],
            'tipo' => [$required, Rule::in(self::ACCOUNT_TYPES)],
            'instituicao' => ['nullable', 'string', 'max:100'],
            'data_inicio_controle' => [$required, 'date', 'before_or_equal:today'],
            'saldo_inicial' => [$create ? 'required' : 'nullable', 'string', 'max:30'],
            'considera_disponivel' => ['nullable', 'boolean'],
            'ativo' => ['nullable', 'boolean'],
            'cor' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'formas_padrao' => ['nullable', 'array'],
            'formas_padrao.*' => ['string', Rule::in(self::PAYMENT_METHODS)],
        ];
    }

    private function persist(callable $callback, string $success): RedirectResponse
    {
        try {
            $callback();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('financeiro.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Não foi possível concluir a operação agora.');
        }

        return back()->with('success', $success);
    }

    private function normalizedMonth(Request $request): string
    {
        $month = trim((string) $request->query('mes', now()->format('Y-m')));

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
            ? $month
            : now()->format('Y-m');
    }
}
