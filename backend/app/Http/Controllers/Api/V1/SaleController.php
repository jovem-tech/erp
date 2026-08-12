<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\CancelSaleRequest;
use App\Http\Requests\Api\V1\StoreSaleRequest;
use App\Models\FinanceiroConta;
use App\Models\FinanceiroContaDefault;
use App\Models\FinanceiroFormaPagamento;
use App\Models\Sale;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Auth\AdminCredentialVerifier;
use App\Services\Financeiro\FinanceiroCartaoService;
use App\Services\Sales\InsufficientStockException;
use App\Services\Sales\SaleReceiptService;
use App\Services\Sales\SaleWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Vendas de balcão (PDV) — specs/027-vendas-balcao-pdv/spec.md.
 *
 * Não há update nem destroy: venda concluída é imutável e a correção é o
 * cancelamento, que estorna estoque e financeiro.
 */
class SaleController extends BaseApiController
{
    public function __construct(
        private readonly SaleWorkflowService $saleWorkflowService,
        private readonly SaleReceiptService $saleReceiptService,
        private readonly FinanceiroCartaoService $financeiroCartaoService,
        private readonly AdminCredentialVerifier $adminCredentialVerifier
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('vendas:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $result = $this->saleWorkflowService->paginate($request->query());

        return $this->success(
            [
                'vendas' => $result['paginator']->items(),
                'summary' => $result['summary'],
                'status_options' => Sale::statusOptions(),
                'status_pagamento_options' => Sale::paymentStatusOptions(),
            ],
            meta: $this->paginationMeta($result['paginator']),
            request: $request
        );
    }

    public function show(Request $request, int $venda): JsonResponse
    {
        $this->authorize('vendas:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $sale = $this->saleWorkflowService->loadSaleOrFail($venda);

        return $this->success(
            ['venda' => $this->saleWorkflowService->mapDetail($sale)],
            request: $request
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('vendas:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        return $this->success(
            ['summary' => $this->saleWorkflowService->summarize($request->query())],
            request: $request
        );
    }

    /**
     * Catálogos do PDV numa chamada só: formas de pagamento, contas, cartões e
     * vendedores. Evita quatro round-trips na abertura da tela.
     */
    public function formData(Request $request): JsonResponse
    {
        $this->authorize('vendas:criar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        return $this->success(
            [
                'form' => [
                    'formas_pagamento' => FinanceiroFormaPagamento::options(),
                    'contas' => FinanceiroConta::query()
                        ->where('ativo', 1)
                        ->orderBy('nome')
                        ->get(['id', 'nome', 'tipo'])
                        ->map(static fn ($account): array => [
                            'id' => (int) $account->id,
                            'nome' => (string) $account->nome,
                            'tipo' => (string) $account->tipo,
                        ])->all(),
                    // Conta padrão por forma de pagamento (Financeiro > Contas).
                    // Sem isto o PDV não teria como pré-selecionar a conta, e o
                    // operador só descobriria que precisa escolher ao receber
                    // "Selecione a conta financeira..." de FinanceiroContaService
                    // depois de montar o carrinho inteiro.
                    'contas_padrao' => FinanceiroContaDefault::query()
                        ->pluck('conta_financeira_id', 'forma_pagamento')
                        ->map(static fn ($id): int => (int) $id)
                        ->all(),
                    'cartoes' => $this->financeiroCartaoService->buildActiveDataset(),
                    'vendedores' => $this->sellerOptions(),
                    'status_options' => Sale::statusOptions(),
                    'usuario_id' => (int) $user->id,
                    'data_hoje' => now()->toDateString(),
                ],
            ],
            request: $request
        );
    }

    /**
     * Busca do PDV: nome, código interno ou código de barras.
     */
    public function searchItems(Request $request): JsonResponse
    {
        $this->authorize('vendas:criar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $term = (string) $request->query('search', $request->query('q', ''));

        return $this->success(
            ['itens' => $this->saleWorkflowService->searchItems($term)],
            request: $request
        );
    }

    /**
     * Seletor de cliente do PDV (Select2 remoto).
     */
    public function clientOptions(Request $request): JsonResponse
    {
        $this->authorize('vendas:criar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $paginator = $this->saleWorkflowService->paginateClientOptions($filters);

        return $this->success(
            ['clients' => $paginator->items()],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        $this->authorize('vendas:criar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        try {
            $result = $this->saleWorkflowService->create($user, $request->validated());
        } catch (InsufficientStockException $exception) {
            // 422 com a lista completa de ofensores: o PDV destaca as linhas e
            // oferece "Vender assim mesmo", que reenvia com
            // confirmar_estoque_insuficiente = true.
            return $this->error(
                $exception->getMessage(),
                422,
                'VENDA_ESTOQUE_INSUFICIENTE',
                ['itens' => $exception->shortages()],
                request: $request
            );
        } catch (RuntimeException $exception) {
            return $this->error(
                $exception->getMessage(),
                422,
                'VENDA_INVALIDA',
                null,
                request: $request
            );
        }

        if (($result['result'] ?? '') === 'idempotency_conflict') {
            return $this->error(
                'Esta chave de criação já foi usada para outra venda.',
                409,
                'VENDA_IDEMPOTENCY_CONFLICT',
                null,
                request: $request
            );
        }

        return $this->success(
            [
                'venda' => $result['sale'],
                'idempotent_replay' => (bool) ($result['idempotent_replay'] ?? false),
            ],
            Response::HTTP_CREATED,
            request: $request
        );
    }

    public function cancel(CancelSaleRequest $request, int $venda): JsonResponse
    {
        $this->authorize('vendas:excluir');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $sale = $this->saleWorkflowService->loadSaleOrFail($venda);

        // Cancelar uma venda de outro dia mexe em caixa já conferido: exige
        // confirmação de administrador, mesmo padrão de FinanceiroController.
        if (! $this->isFromToday($sale)) {
            $email = trim((string) $request->input('admin_email', ''));
            $password = (string) $request->input('admin_password', '');

            if ($email === '' || $password === '') {
                return $this->error(
                    'Cancelar uma venda de outra data exige confirmação de um administrador.',
                    422,
                    'VENDA_ADMIN_AUTH_REQUIRED',
                    null,
                    request: $request
                );
            }

            $verification = $this->adminCredentialVerifier->verify(
                $email,
                $password,
                'venda-cancel-admin-auth',
                (string) $request->ip()
            );

            $errorResponse = $this->respondToAdminVerification(
                $verification,
                $request,
                'VENDA_ADMIN_AUTH_RATE_LIMITED',
                'VENDA_ADMIN_AUTH_INVALID'
            );

            if ($errorResponse !== null) {
                return $errorResponse;
            }
        }

        try {
            $result = $this->saleWorkflowService->cancel($user, $sale, (string) $request->input('motivo'));
        } catch (RuntimeException $exception) {
            return $this->error(
                $exception->getMessage(),
                422,
                'VENDA_CANCELAMENTO_INVALIDO',
                null,
                request: $request
            );
        }

        return $this->success(['venda' => $result['sale']], request: $request);
    }

    /**
     * Cupom da venda. Padrão 80 mm; `?formato=a4` para folha inteira.
     */
    public function receipt(Request $request, int $venda): Response
    {
        $this->authorize('vendas:visualizar');

        $sale = $this->saleWorkflowService->loadSaleOrFail($venda);

        $format = (string) $request->query('formato', '80mm');
        $format = in_array($format, ['80mm', 'a4'], true) ? $format : '80mm';

        try {
            return $this->saleReceiptService->stream($sale, $format);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                'Não foi possível gerar o comprovante desta venda.',
                422,
                'VENDA_COMPROVANTE_INDISPONIVEL',
                null,
                request: $request
            );
        }
    }

    private function isFromToday(Sale $sale): bool
    {
        return $sale->data_venda?->toDateString() === now()->toDateString();
    }

    /**
     * Vendedores sugeridos: quem está marcado como atuante em vendas na equipe.
     * Se ninguém estiver marcado, devolve os usuários ativos — não faz sentido
     * travar o PDV por causa de um cadastro de equipe incompleto.
     *
     * @return array<int, array{id: int, nome: string}>
     */
    private function sellerOptions(): array
    {
        $sellerIds = TeamMember::query()
            ->where('atua_vendas', 1)
            ->pluck('usuario_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $query = User::query()->where('ativo', 1);

        if ($sellerIds !== []) {
            $query->whereIn('id', $sellerIds);
        }

        return $query
            ->orderBy('nome')
            ->get(['id', 'nome'])
            ->map(static fn (User $user): array => [
                'id' => (int) $user->id,
                'nome' => (string) $user->nome,
            ])
            ->all();
    }
}
