<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\VendaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Throwable;

/**
 * Vendas de balcão (PDV) — specs/027-vendas-balcao-pdv/spec.md.
 *
 * Sem edit/update: venda concluída é imutável, a correção é o cancelamento.
 */
class VendaController extends DesktopController
{
    public function __construct(
        private readonly VendaService $vendaService
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => trim((string) $request->query('status', '')),
            'status_pagamento' => trim((string) $request->query('status_pagamento', '')),
            'vendedor_id' => (int) $request->query('vendedor_id', 0),
            'forma_pagamento' => trim((string) $request->query('forma_pagamento', '')),
            'data_inicio' => trim((string) $request->query('data_inicio', '')),
            'data_fim' => trim((string) $request->query('data_fim', '')),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        try {
            $result = $this->vendaService->paginate(array_filter(
                $filters,
                static fn ($value): bool => $value !== '' && $value !== 0
            ));
        } catch (ApiAuthenticationException $exception) {
            return $this->redirectToLogin($exception);
        } catch (Throwable $exception) {
            report($exception);

            $result = [
                'items' => [],
                'pagination' => [],
                'summary' => [],
                'status_options' => [],
                'status_pagamento_options' => [],
            ];
        }

        return view('vendas.index', [
            'pageTitle' => 'Vendas',
            'vendas' => $result['items'],
            'pagination' => $result['pagination'],
            'summary' => $result['summary'],
            'statusOptions' => $result['status_options'],
            'statusPagamentoOptions' => $result['status_pagamento_options'],
            'filters' => $filters,
        ]);
    }

    /**
     * Tela do PDV.
     */
    public function create(): View|RedirectResponse
    {
        try {
            $form = $this->vendaService->formData();
        } catch (ApiAuthenticationException $exception) {
            return $this->redirectToLogin($exception);
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('vendas.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('vendas.index')
                ->with('error', 'Não foi possível abrir o PDV agora. Tente novamente.');
        }

        return view('vendas.pdv', [
            'pageTitle' => 'Nova venda',
            'form' => $form,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedSalePayload($request);

        try {
            $result = $this->vendaService->create($payload);
        } catch (ApiAuthenticationException $exception) {
            return $this->redirectToLogin($exception);
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('vendas.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput()
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Não foi possível concluir a venda agora. Tente novamente.');
        }

        $sale = is_array($result['venda'] ?? null) ? $result['venda'] : [];
        $saleId = (int) ($sale['id'] ?? 0);

        if ($saleId <= 0) {
            return redirect()->route('vendas.index')->with('success', 'Venda registrada.');
        }

        return redirect()
            ->route('vendas.show', $saleId)
            ->with('success', 'Venda '.($sale['numero'] ?? '').' concluída.');
    }

    public function show(int $venda): View|RedirectResponse
    {
        try {
            $sale = $this->vendaService->find($venda);
        } catch (ApiAuthenticationException $exception) {
            return $this->redirectToLogin($exception);
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('vendas.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('vendas.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('vendas.index')
                ->with('error', 'Não foi possível carregar esta venda agora.');
        }

        if (($sale['id'] ?? 0) <= 0) {
            return redirect()->route('vendas.index')->with('error', 'Venda não encontrada.');
        }

        return view('vendas.show', [
            'pageTitle' => 'Venda '.($sale['numero'] ?? ''),
            'venda' => $sale,
        ]);
    }

    public function cancel(Request $request, int $venda): RedirectResponse
    {
        $validated = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:2000'],
            'admin_email' => ['nullable', 'string', 'email', 'max:255'],
            'admin_password' => ['nullable', 'string', 'max:255'],
        ], [], [
            'motivo' => 'motivo do cancelamento',
            'admin_email' => 'e-mail do administrador',
            'admin_password' => 'senha do administrador',
        ]);

        try {
            $this->vendaService->cancel($venda, $validated);
        } catch (ApiAuthenticationException $exception) {
            return $this->redirectToLogin($exception);
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('vendas.show', $venda)->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()
                ->route('vendas.show', $venda)
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('vendas.show', $venda)
                ->with('error', 'Não foi possível cancelar a venda agora. Tente novamente.');
        }

        return redirect()
            ->route('vendas.show', $venda)
            ->with('success', 'Venda cancelada. Estoque e financeiro foram estornados.');
    }

    public function receipt(Request $request, int $venda): Response|RedirectResponse
    {
        $format = (string) $request->query('formato', '80mm');
        $format = in_array($format, ['80mm', 'a4'], true) ? $format : '80mm';

        try {
            $download = $this->vendaService->receipt($venda, $format);
        } catch (ApiAuthenticationException $exception) {
            return $this->redirectToLogin($exception);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('vendas.show', $venda)
                ->with('error', 'Não foi possível gerar o comprovante desta venda.');
        }

        return response($download['body'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="venda-'.$venda.'.pdf"',
        ]);
    }

    /**
     * Busca de itens do PDV (produto, código interno ou código de barras).
     */
    public function searchItems(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $term = trim((string) ($validated['search'] ?? $validated['q'] ?? ''));

        try {
            $items = $this->vendaService->searchItems($term);
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->jsonFailure(
                $exception->getMessage(),
                $exception->statusCode() > 0 ? $exception->statusCode() : 422,
                $exception->details()
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->jsonFailure('Não foi possível pesquisar os itens agora.', 500);
        }

        return response()->json(['success' => true, 'itens' => $items]);
    }

    /**
     * Seletor de cliente do PDV, no formato que o Select2 remoto espera.
     */
    public function searchClients(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $page = max(1, (int) ($validated['page'] ?? 1));

        try {
            $result = $this->vendaService->clientOptions([
                'q' => trim((string) ($validated['q'] ?? $validated['search'] ?? '')),
                'page' => $page,
                'per_page' => max(1, min(20, (int) ($validated['per_page'] ?? 15))),
            ]);
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            report($exception);

            return $this->jsonFailure('Não foi possível pesquisar os clientes agora.', 500);
        }

        $items = array_values(array_filter(array_map(static function (array $client): array {
            $id = (int) ($client['id'] ?? 0);
            $name = trim((string) ($client['nome_razao'] ?? ''));
            $phone = trim((string) ($client['telefone1'] ?? ''));

            return [
                'id' => $id,
                'text' => implode(' - ', array_values(array_filter([
                    $name !== '' ? $name : ($id > 0 ? 'Cliente #'.$id : ''),
                    $phone,
                ]))),
                'name' => $name,
                'phone' => $phone,
                'documento' => trim((string) ($client['cpf_cnpj'] ?? '')),
            ];
        }, (array) ($result['items'] ?? [])), static fn (array $item): bool => $item['id'] > 0));

        $pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : [];
        $currentPage = (int) ($pagination['current_page'] ?? $page);
        $lastPage = (int) ($pagination['last_page'] ?? $currentPage);

        return response()->json([
            'success' => true,
            'results' => $items,
            'pagination' => ['more' => $currentPage < $lastPage],
        ]);
    }

    public function help(): View
    {
        return view('vendas.help', ['pageTitle' => 'Ajuda de vendas']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedSalePayload(Request $request): array
    {
        $validated = $request->validate([
            'creation_request_id' => ['nullable', 'uuid'],
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'cliente_nome_avulso' => ['nullable', 'string', 'max:160'],
            'cliente_documento_avulso' => ['nullable', 'string', 'max:20'],
            'telefone_contato' => ['nullable', 'string', 'max:30'],
            'vendedor_id' => ['nullable', 'integer', 'min:1'],
            'data_venda' => ['nullable', 'date'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'confirmar_estoque_insuficiente' => ['nullable', 'boolean'],
            'desconto' => ['nullable', 'string', 'max:20'],
            'desconto_tipo' => ['nullable', 'string', 'in:valor,percentual'],
            'desconto_percentual' => ['nullable', 'string', 'max:20'],
            'acrescimo' => ['nullable', 'string', 'max:20'],
            'acrescimo_tipo' => ['nullable', 'string', 'in:valor,percentual'],
            'acrescimo_percentual' => ['nullable', 'string', 'max:20'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.tipo_item' => ['required', 'string', 'in:peca,servico,avulso'],
            'itens.*.referencia_id' => ['nullable', 'integer', 'min:1'],
            'itens.*.descricao' => ['nullable', 'string', 'max:255'],
            'itens.*.quantidade' => ['required', 'string', 'max:20'],
            'itens.*.valor_unitario' => ['nullable', 'string', 'max:20'],
            'itens.*.desconto' => ['nullable', 'string', 'max:20'],
            'itens.*.desconto_tipo' => ['nullable', 'string', 'in:valor,percentual'],
            'itens.*.desconto_percentual' => ['nullable', 'string', 'max:20'],
            'itens.*.baixa_estoque' => ['nullable', 'boolean'],
            'itens.*.observacoes' => ['nullable', 'string', 'max:1000'],
            'pagamentos' => ['nullable', 'array'],
            'pagamentos.*.forma_pagamento' => ['required', 'string', 'max:40'],
            'pagamentos.*.valor' => ['required', 'string', 'max:20'],
            'pagamentos.*.valor_recebido' => ['nullable', 'string', 'max:20'],
            'pagamentos.*.conta_financeira_id' => ['nullable', 'integer', 'min:1'],
            'pagamentos.*.operadora_id' => ['nullable', 'integer', 'min:1'],
            'pagamentos.*.bandeira_id' => ['nullable', 'integer', 'min:1'],
            'pagamentos.*.modalidade' => ['nullable', 'string', 'in:credito,debito'],
            'pagamentos.*.parcelas' => ['nullable', 'integer', 'min:1', 'max:99'],
        ], [
            'itens.required' => 'Inclua ao menos um item na venda.',
            'itens.min' => 'Inclua ao menos um item na venda.',
        ], [
            'cliente_id' => 'cliente',
            'vendedor_id' => 'vendedor',
            'data_venda' => 'data da venda',
        ]);

        // Os campos monetários chegam com máscara pt-BR ("1.234,56"): o backend
        // valida como `numeric`, então precisam virar ponto-decimal antes.
        $payload = $this->normalizeMoneyPayload(
            $validated,
            ['desconto', 'acrescimo'],
            [
                'itens' => ['valor_unitario', 'desconto'],
                'pagamentos' => ['valor', 'valor_recebido'],
            ]
        );

        $payload = $this->normalizeDecimalPayload(
            $payload,
            ['desconto_percentual', 'acrescimo_percentual'],
            ['itens' => ['quantidade', 'desconto_percentual']]
        );

        return $payload;
    }

    private function redirectToLogin(ApiAuthenticationException $exception): RedirectResponse
    {
        return redirect()->route('login')->with('error', $exception->getMessage());
    }

    /**
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

    /**
     * @param array<string, mixed>|null $details
     */
    private function jsonFailure(string $message, int $status, ?array $details = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $details ?? [],
        ], $status);
    }
}
