<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\FinanceiroCartaoCreditoService;
use App\Services\FinanceiroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Cartões de crédito da assistência — os usados para COMPRAR. A tela vive
 * dentro de "Contas e Saldos" (aba própria), por isso as rotas ficam sob
 * /financeiro/contas/cartoes-credito e usam a permissão contas_saldos.
 *
 * Não confundir com FinanceiroCartaoController (Cartões e Taxas), que é o
 * catálogo de operadora/bandeira/taxa da maquininha — receber do cliente.
 */
class FinanceiroCartaoCreditoController extends DesktopController
{
    public function __construct(
        private readonly FinanceiroCartaoCreditoService $cartaoCreditoService,
        private readonly FinanceiroService $financeiroService
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate($this->rules());
        $payload['ativo'] = true;

        return $this->persist(
            fn () => $this->cartaoCreditoService->create($payload),
            'Cartão de crédito cadastrado.'
        );
    }

    public function update(Request $request, int $cartaoCredito): RedirectResponse
    {
        $payload = $request->validate($this->rules());
        $payload['ativo'] = $request->boolean('ativo');

        return $this->persist(
            fn () => $this->cartaoCreditoService->update($cartaoCredito, $payload),
            'Cartão de crédito atualizado.'
        );
    }

    public function faturas(Request $request, int $cartaoCredito): View|RedirectResponse
    {
        $filtros = [
            'mes' => trim((string) $request->query('mes', '')),
            'vencimento_de' => trim((string) $request->query('vencimento_de', '')),
            'vencimento_ate' => trim((string) $request->query('vencimento_ate', '')),
            'situacao' => trim((string) $request->query('situacao', '')),
        ];

        try {
            $dados = $this->cartaoCreditoService->faturas(
                $cartaoCredito,
                array_filter($filtros, static fn (string $valor): bool => $valor !== '')
            );
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('financeiro.contas.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('financeiro.contas.index')->with('error', 'Não foi possível carregar as faturas deste cartão agora.');
        }

        return view('financeiro.cartoes-credito.faturas', [
            'pageTitle' => 'Faturas do cartão',
            'cartao' => $dados['cartao'] ?? [],
            'faturas' => $dados['faturas'] ?? [],
            'faturaAtual' => is_array($dados['fatura_atual'] ?? null) ? $dados['fatura_atual'] : null,
            'filtros' => $filtros,
            // Alimenta o modal "Pagar fatura" das linhas elegíveis.
            'accountDataset' => $this->accountDataset(),
        ]);
    }

    public function faturaShow(int $cartaoCredito, string $dataVencimento): View|RedirectResponse
    {
        try {
            $dados = $this->cartaoCreditoService->fatura($cartaoCredito, $dataVencimento);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('financeiro.contas.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('financeiro.contas.index')->with('error', 'Não foi possível carregar esta fatura agora.');
        }

        return view('financeiro.cartoes-credito.fatura-show', [
            'pageTitle' => 'Fatura do cartão',
            'cartao' => $dados['cartao'] ?? [],
            'fatura' => $dados['fatura'] ?? [],
            'despesas' => $dados['despesas'] ?? [],
            'accountDataset' => $this->accountDataset(),
        ]);
    }

    public function faturaPagar(Request $request, int $cartaoCredito, string $dataVencimento): RedirectResponse
    {
        $payload = $request->validate([
            'data_pagamento' => ['nullable', 'date'],
            'forma_pagamento' => ['nullable', 'string', 'max:40'],
            'conta_financeira_id' => ['nullable', 'integer'],
            'observacoes' => ['nullable', 'string'],
        ]);

        try {
            $resultado = $this->cartaoCreditoService->pagarFatura($cartaoCredito, $dataVencimento, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('financeiro.contas.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Não foi possível baixar esta fatura agora.');
        }

        $baixadas = (int) ($resultado['succeeded_count'] ?? 0);
        $falhas = (int) ($resultado['failed_count'] ?? 0);
        $valor = number_format((float) ($resultado['valor_baixado'] ?? 0), 2, ',', '.');

        $mensagem = sprintf(
            'Fatura baixada: %d despesa%s liquidada%s (R$ %s).',
            $baixadas,
            $baixadas === 1 ? '' : 's',
            $baixadas === 1 ? '' : 's',
            $valor
        );

        if ($falhas > 0) {
            // Baixa parcial não é erro: as demais despesas foram liquidadas e
            // o usuário precisa saber exatamente quais ficaram para trás.
            $motivos = collect($resultado['failed'] ?? [])
                ->map(static fn (array $item): string => trim((string) ($item['descricao'] ?? '#'.($item['financeiro_id'] ?? ''))).' — '.($item['reason'] ?? ''))
                ->implode('; ');

            return back()->with('error', $mensagem.sprintf(' %d não pôde ser baixada: %s', $falhas, $motivos));
        }

        return back()->with('success', $mensagem);
    }

    /**
     * Despesa que o banco cobrou numa fatura já paga mas que ninguém registrou.
     * Entra já quitada (ver FinanceiroCartaoCreditoService::registerForgottenExpense()
     * na API) — por isso o formulário não pergunta status nem forma de pagamento.
     */
    public function faturaDespesaEsquecida(Request $request, int $cartaoCredito): RedirectResponse
    {
        $payload = $request->validate([
            'data_vencimento' => ['required', 'date_format:Y-m-d'],
            'categoria' => ['required', 'string', 'max:50'],
            'descricao' => ['required', 'string', 'max:255'],
            'valor' => ['required', 'numeric', 'min:0.01'],
            'data_compra' => ['required', 'date'],
            'dre_fixo_mensal' => ['nullable', 'boolean'],
            'observacoes' => ['nullable', 'string'],
        ], [], [
            'categoria' => 'categoria',
            'descricao' => 'descrição',
            'valor' => 'valor',
            'data_compra' => 'data da compra',
            'data_vencimento' => 'fatura',
        ]);

        $dataVencimento = $payload['data_vencimento'];
        unset($payload['data_vencimento']);

        try {
            $this->cartaoCreditoService->lancarDespesaEsquecida($cartaoCredito, $dataVencimento, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('financeiro.contas.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Não foi possível lançar a despesa nesta fatura agora.');
        }

        return back()->with('success', sprintf(
            'Despesa lançada na fatura de %s, já quitada junto com ela.',
            \Illuminate\Support\Carbon::parse($dataVencimento)->format('d/m/Y')
        ));
    }

    public function faturaCancelarBaixa(Request $request, int $cartaoCredito, string $dataVencimento): RedirectResponse
    {
        // Estorno de dinheiro já conciliado: o backend exige credencial de
        // administrador (ver FinanceiroCartaoCreditoController::faturaCancelarBaixa
        // na API). Aqui só encaminhamos o que o modal coletou.
        $payload = $request->validate([
            'admin_email' => ['required', 'string', 'email'],
            'admin_password' => ['required', 'string'],
        ], [], [
            'admin_email' => 'e-mail do administrador',
            'admin_password' => 'senha do administrador',
        ]);

        try {
            $resultado = $this->cartaoCreditoService->cancelarBaixaFatura($cartaoCredito, $dataVencimento, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('financeiro.contas.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível cancelar a baixa desta fatura agora.');
        }

        $estornadas = (int) ($resultado['despesas_estornadas'] ?? 0);
        $valor = number_format((float) ($resultado['valor_estornado'] ?? 0), 2, ',', '.');

        return back()->with('success', sprintf(
            'Baixa cancelada: %s a ficar pendente. Valor estornado: R$ %s.',
            $estornadas === 1 ? '1 despesa voltou' : $estornadas.' despesas voltaram',
            $valor
        ));
    }

    /**
     * Prévia do vencimento da fatura consumida pelo formulário de despesa.
     * É um proxy para o backend de propósito: o cálculo do ciclo tem que
     * acontecer num lugar só, senão a tela mostraria uma data e o save
     * gravaria outra.
     */
    public function preverFatura(Request $request, int $cartaoCredito): JsonResponse
    {
        $dataCompra = trim((string) $request->query('data_compra', ''));

        try {
            $fatura = $this->cartaoCreditoService->preverFatura($cartaoCredito, $dataCompra);
        } catch (Throwable $exception) {
            return response()->json(['error' => 'Não foi possível calcular o vencimento da fatura.'], 422);
        }

        return response()->json(['fatura' => $fatura]);
    }

    /**
     * Contas de Contas e Saldos para o modal de baixa da fatura. Falha aqui
     * não pode derrubar a tela — sem contas o modal ainda funciona, só não
     * oferece em qual conta debitar.
     *
     * @return array<string, mixed>
     */
    private function accountDataset(): array
    {
        try {
            return $this->financeiroService->catalogo()['contas_financeiras']
                ?? ['contas' => [], 'contas_padrao' => [], 'tipos' => []];
        } catch (Throwable $exception) {
            report($exception);

            return ['contas' => [], 'contas_padrao' => [], 'tipos' => []];
        }
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:100'],
            'instituicao' => ['nullable', 'string', 'max:100'],
            'conta_financeira_id' => ['nullable', 'integer', 'min:1'],
            'final_cartao' => ['nullable', 'string', 'max:4'],
            'dia_fechamento' => ['required', 'integer', 'between:1,31'],
            'dia_vencimento' => ['required', 'integer', 'between:1,31'],
            'cor' => ['nullable', 'string', 'max:7'],
            'observacoes' => ['nullable', 'string'],
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
}
