<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\UpsertBudgetRequest;
use App\Services\Budgets\BudgetApprovalService;
use App\Services\Budgets\BudgetWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetController extends BaseApiController
{
    public function __construct(
        private readonly BudgetWorkflowService $budgetWorkflowService,
        private readonly BudgetApprovalService $budgetApprovalService
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

        $result = $this->budgetWorkflowService->createBudget($user, $request->validated());

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

        $result = $this->budgetWorkflowService->updateBudget($budget, $user, $request->validated());

        return match ($result['result'] ?? 'ok') {
            'ok' => $this->success(
                ['budget' => $result],
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
                'Falha ao atualizar o orçamento.',
                500,
                'BUDGET_UPDATE_FAILED',
                null,
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
}
