<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\UpsertBudgetRequest;
use App\Services\Auth\AdminCredentialVerifier;
use App\Services\Budgets\BudgetApprovalService;
use App\Services\Budgets\BudgetWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class BudgetController extends BaseApiController
{
    public function __construct(
        private readonly BudgetWorkflowService $budgetWorkflowService,
        private readonly BudgetApprovalService $budgetApprovalService,
        private readonly AdminCredentialVerifier $adminCredentialVerifier
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('orcamentos:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $result = $this->budgetWorkflowService->paginateForUser($user, $request->query());

        return $this->success(
            [
                'budgets' => $result['paginator']->items(),
                'summary' => $result['summary'],
                'status_options' => $result['status_options'],
            ],
            meta: $this->paginationMeta($result['paginator']),
            request: $request
        );
    }

    public function formData(Request $request): JsonResponse
    {
        $this->authorize('orcamentos:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        return $this->success(
            [
                'form' => $this->budgetWorkflowService->formData($user, $request->query()),
            ],
            request: $request
        );
    }

    public function show(Request $request, int $budget): JsonResponse
    {
        $this->authorize('orcamentos:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $result = $this->budgetWorkflowService->showForUser($user, $budget);

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(
                ['budget' => $result['budget'] ?? null],
                request: $request
            ),
            'not_found' => $this->error(
                'Orçamento não encontrado.',
                404,
                'BUDGET_NOT_FOUND',
                null,
                request: $request
            ),
            default => $this->error(
                'Falha ao carregar o orçamento.',
                500,
                'BUDGET_SHOW_FAILED',
                null,
                request: $request
            ),
        };
    }

    public function store(UpsertBudgetRequest $request): JsonResponse
    {
        $this->authorize('orcamentos:criar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $validated = $request->validated();
        $adminCheck = $this->verifyAdminIfProvided($validated, $request);
        if (! $adminCheck['ok']) {
            return $adminCheck['response'];
        }

        $payload = Arr::except($validated, ['admin_email', 'admin_password']);
        $result = $this->budgetWorkflowService->createBudget($user, $payload, $adminCheck['admin']);

        if (($result['result'] ?? 'ok') === 'requires_admin_confirmation') {
            return $this->error(
                'Esta OS está encerrada. Confirme com credenciais de administrador para vincular um orçamento a ela.',
                409,
                'BUDGET_CLOSED_OS_ADMIN_REQUIRED',
                null,
                request: $request
            );
        }

        return $this->success(
            ['budget' => $result],
            201,
            request: $request
        );
    }

    public function update(UpsertBudgetRequest $request, int $budget): JsonResponse
    {
        $this->authorize('orcamentos:editar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $validated = $request->validated();
        $adminCheck = $this->verifyAdminIfProvided($validated, $request);
        if (! $adminCheck['ok']) {
            return $adminCheck['response'];
        }

        $payload = Arr::except($validated, ['admin_email', 'admin_password']);
        $result = $this->budgetWorkflowService->updateBudget($budget, $user, $payload, $adminCheck['admin']);

        return match ($result['result'] ?? 'ok') {
            'not_found' => $this->error(
                'Orçamento não encontrado.',
                404,
                'BUDGET_NOT_FOUND',
                null,
                request: $request
            ),
            'requires_admin_confirmation' => $this->error(
                'Esta OS está encerrada. Confirme com credenciais de administrador para editar o orçamento.',
                409,
                'BUDGET_CLOSED_OS_ADMIN_REQUIRED',
                null,
                request: $request
            ),
            default => $this->success(
                ['budget' => $result],
                request: $request
            ),
        };
    }

    public function destroy(Request $request, int $budget): JsonResponse
    {
        $this->authorize('orcamentos:excluir');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $result = $this->budgetWorkflowService->deleteBudget($budget, $user);

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(
                ['deleted' => true],
                request: $request
            ),
            'not_found' => $this->error(
                'Orçamento não encontrado.',
                404,
                'BUDGET_NOT_FOUND',
                null,
                request: $request
            ),
            default => $this->error(
                'Falha ao excluir o orçamento.',
                500,
                'BUDGET_DELETE_FAILED',
                null,
                request: $request
            ),
        };
    }

    public function sendForApproval(Request $request, int $budget): JsonResponse
    {
        $this->authorize('orcamentos:editar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $result = $this->budgetApprovalService->dispatchForApproval($budget, $user, [
            'mensagem' => $request->input('mensagem'),
        ]);

        return match ($result['result'] ?? 'error') {
            'ok' => $this->success(
                ['dispatch' => $result['dispatch'] ?? []],
                request: $request
            ),
            'not_found' => $this->error(
                'Orçamento não encontrado.',
                404,
                'BUDGET_NOT_FOUND',
                null,
                request: $request
            ),
            'validation_error' => $this->error(
                (string) ($result['message'] ?? 'Existem pendências que impedem o envio para aprovação.'),
                422,
                'BUDGET_APPROVAL_VALIDATION',
                $result['details'] ?? null,
                request: $request
            ),
            default => $this->error(
                (string) ($result['message'] ?? 'Falha ao enviar o orçamento para aprovação.'),
                422,
                'BUDGET_APPROVAL_FAILED',
                $result['details'] ?? null,
                request: $request
            ),
        };
    }

    /**
     * Verifica credenciais de administrador quando o payload as inclui —
     * campos usados apenas para autorizar edição/criação de orçamento numa OS
     * já encerrada (ver BudgetWorkflowService::isOrderClosed()). Se nenhum
     * dos dois campos vier preenchido, retorna ok sem exigir nada (o guard de
     * OS encerrada por si só decide se a confirmação é necessária).
     *
     * @param array<string, mixed> $validated
     * @return array{ok: bool, admin?: ?\App\Models\User, response?: JsonResponse}
     */
    private function verifyAdminIfProvided(array $validated, Request $request): array
    {
        $email = trim((string) ($validated['admin_email'] ?? ''));
        $password = (string) ($validated['admin_password'] ?? '');

        if ($email === '' || $password === '') {
            return ['ok' => true, 'admin' => null];
        }

        $verification = $this->adminCredentialVerifier->verify(
            $email,
            $password,
            'budget-edit-admin-auth',
            (string) $request->ip()
        );

        if (($verification['error'] ?? null) === 'rate_limited') {
            return [
                'ok' => false,
                'response' => $this->error(
                    'Muitas tentativas de verificação de administrador. Aguarde um pouco e tente novamente.',
                    429,
                    'BUDGET_ADMIN_AUTH_RATE_LIMITED',
                    ['retry_after' => $verification['retry_after'] ?? null],
                    request: $request
                ),
            ];
        }

        if (! ($verification['ok'] ?? false)) {
            return [
                'ok' => false,
                'response' => $this->error(
                    'Credenciais de administrador inválidas.',
                    422,
                    'BUDGET_ADMIN_AUTH_INVALID',
                    null,
                    request: $request
                ),
            ];
        }

        return ['ok' => true, 'admin' => $verification['admin']];
    }
}
