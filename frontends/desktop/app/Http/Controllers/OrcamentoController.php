<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\OrcamentoService;
use App\Support\DesktopSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class OrcamentoController extends DesktopController
{
    public function __construct(
        private readonly OrcamentoService $orcamentoService
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => trim((string) $request->query('status', '')),
            'tipo' => trim((string) $request->query('tipo', '')),
            'origem' => trim((string) $request->query('origem', '')),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $result = $this->orcamentoService->paginate(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== 0
        ));

        return view('orcamentos.index', [
            'pageTitle' => 'Orçamentos',
            'budgets' => $result['items'],
            'pagination' => $result['pagination'],
            'summary' => $result['summary'],
            'statusOptions' => $result['status_options'],
            'filters' => $filters,
        ]);
    }

    public function help(): View
    {
        return view('orcamentos.help', [
            'pageTitle' => 'Ajuda de orçamentos',
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        try {
            $form = $this->orcamentoService->formData([
                'cliente_id' => (int) $request->query('cliente_id', 0),
                'os_id' => (int) $request->query('os_id', 0),
            ]);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('orcamentos.index')->with('error', 'Não foi possível carregar o formulário de orçamento agora.');
        }

        return view('orcamentos.create', [
            'pageTitle' => 'Novo orçamento',
            'budget' => [],
            'form' => $form,
            'quickCatalogs' => $this->quickCatalogConfig(),
            'isEditMode' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedBudgetPayload($request);

        try {
            $budget = $this->orcamentoService->create($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->all())
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->all())
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do orçamento.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->all())
                ->with('error', 'Não foi possível criar o orçamento agora. Tente novamente.');
        }

        return redirect()
            ->route('orcamentos.show', $budget['id'] ?? 0)
            ->with('success', 'Orçamento criado com sucesso.');
    }

    public function show(int $orcamento): View|RedirectResponse
    {
        try {
            $budget = $this->orcamentoService->find($orcamento);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('orcamentos.index')->with('error', 'Não foi possível carregar o orçamento.');
        }

        if ($budget === []) {
            abort(404);
        }

        return view('orcamentos.show', [
            'pageTitle' => 'Detalhe do orçamento',
            'budget' => $budget,
        ]);
    }

    public function edit(int $orcamento): View|RedirectResponse
    {
        try {
            $budget = $this->orcamentoService->find($orcamento);
            $form = $this->orcamentoService->formData([
                'cliente_id' => (int) ($budget['cliente']['id'] ?? $budget['cliente_id'] ?? 0),
                'os_id' => (int) ($budget['os']['id'] ?? $budget['os_id'] ?? 0),
            ]);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('orcamentos.index')->with('error', 'Não foi possível carregar a edição do orçamento.');
        }

        if ($budget === []) {
            abort(404);
        }

        return view('orcamentos.edit', [
            'pageTitle' => 'Editar orçamento',
            'budget' => $budget,
            'form' => $form,
            'quickCatalogs' => $this->quickCatalogConfig(),
            'isEditMode' => true,
        ]);
    }

    public function update(Request $request, int $orcamento): RedirectResponse
    {
        $payload = $this->validatedBudgetPayload($request);

        try {
            $budget = $this->orcamentoService->update($orcamento, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->all())
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->all())
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do orçamento.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->all())
                ->with('error', 'Não foi possível atualizar o orçamento agora. Tente novamente.');
        }

        return redirect()
            ->route('orcamentos.show', $budget['id'] ?? $orcamento)
            ->with('success', 'Orçamento atualizado com sucesso.');
    }

    public function destroy(int $orcamento): RedirectResponse
    {
        try {
            $this->orcamentoService->destroy($orcamento);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('orcamentos.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('orcamentos.index')->with('error', 'Não foi possível excluir o orçamento agora.');
        }

        return redirect()
            ->route('orcamentos.index')
            ->with('success', 'Orçamento excluído com sucesso.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedBudgetPayload(Request $request): array
    {
        $validated = $request->validate([
            'numero' => ['nullable', 'string', 'max:50'],
            'versao' => ['nullable', 'integer', 'min:1'],
            'tipo_orcamento' => ['required', 'string', Rule::in(['previo', 'assistencia'])],
            'status' => ['nullable', 'string', Rule::in([
                'rascunho',
                'pendente_envio',
                'enviado',
                'aguardando_resposta',
                'aguardando_pacote',
                'pacote_aprovado',
                'pendente',
                'aprovado',
                'reenviar_orcamento',
                'pendente_abertura_os',
                'rejeitado',
                'vencido',
                'cancelado',
                'convertido',
            ])],
            'origem' => ['nullable', 'string', Rule::in(['manual', 'os', 'conversa', 'cliente'])],
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'cliente_nome_avulso' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => ! $request->filled('cliente_id'))],
            'telefone_contato' => ['nullable', 'string', 'max:30'],
            'email_contato' => ['nullable', 'email', 'max:255'],
            'os_id' => ['nullable', 'integer', 'min:1'],
            'equipamento_id' => ['nullable', 'integer', 'min:1'],
            'titulo' => ['nullable', 'string', 'max:255'],
            'validade_dias' => ['nullable', 'integer', 'min:0'],
            'validade_data' => ['nullable', 'date'],
            'prazo_execucao' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string'],
            'condicoes' => ['nullable', 'string'],
            'subtotal' => ['nullable', 'numeric'],
            'desconto' => ['nullable', 'numeric'],
            'acrescimo' => ['nullable', 'numeric'],
            'total' => ['nullable', 'numeric'],
            'itens' => ['nullable', 'array'],
            'itens.*.tipo_item' => ['required_with:itens', 'string', Rule::in(['servico', 'peca'])],
            'itens.*.referencia_id' => ['nullable', 'integer', 'min:1'],
            'itens.*.descricao' => ['nullable', 'string', 'max:255'],
            'itens.*.quantidade' => ['nullable', 'numeric', 'min:0'],
            'itens.*.valor_unitario' => ['nullable', 'numeric', 'min:0'],
            'itens.*.desconto' => ['nullable', 'numeric', 'min:0'],
            'itens.*.acrescimo' => ['nullable', 'numeric', 'min:0'],
            'itens.*.observacoes' => ['nullable', 'string'],
            'itens.*.modo_precificacao' => ['nullable', 'string', 'max:50'],
        ], [], [
            'numero' => 'número',
            'versao' => 'versão',
            'tipo_orcamento' => 'tipo de orçamento',
            'status' => 'status',
            'origem' => 'origem',
            'cliente_id' => 'cliente',
            'cliente_nome_avulso' => 'nome do cliente avulso',
            'telefone_contato' => 'telefone de contato',
            'email_contato' => 'e-mail de contato',
            'os_id' => 'OS',
            'equipamento_id' => 'equipamento',
            'titulo' => 'título',
            'validade_dias' => 'validade em dias',
            'validade_data' => 'validade',
            'prazo_execucao' => 'prazo de execução',
            'observacoes' => 'observações',
            'condicoes' => 'condições',
            'subtotal' => 'subtotal',
            'desconto' => 'desconto',
            'acrescimo' => 'acréscimo',
            'total' => 'total',
        ]);

        $validated['itens'] = collect($validated['itens'] ?? [])
            ->filter(function (array $item): bool {
                $haystack = implode('', array_map(static fn ($value): string => trim((string) $value), $item));

                return trim($haystack) !== '';
            })
            ->values()
            ->all();

        return $validated;
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
     * @return array<string, array<string, mixed>>
     */
    private function quickCatalogConfig(): array
    {
        return [
            'service' => [
                'enabled' => DesktopSession::can('servicos', 'criar'),
                'label' => 'Serviço',
                'title' => 'Cadastro rápido de serviço',
                'submit_label' => 'Cadastrar serviço',
                'store_url' => route('servicos.quick.store'),
                'full_url' => route('servicos.create'),
            ],
            'part' => [
                'enabled' => DesktopSession::can('estoque', 'criar'),
                'label' => 'Peça',
                'title' => 'Cadastro rápido de peça',
                'submit_label' => 'Cadastrar peça',
                'store_url' => route('estoque.quick.store'),
                'full_url' => route('estoque.create'),
            ],
        ];
    }
}
