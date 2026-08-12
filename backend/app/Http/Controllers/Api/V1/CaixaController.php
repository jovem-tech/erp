<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\CloseCaixaRequest;
use App\Http\Requests\Api\V1\OpenCaixaRequest;
use App\Http\Requests\Api\V1\StoreCaixaMovimentoRequest;
use App\Models\CaixaMovimento;
use App\Models\CaixaSessao;
use App\Models\FinanceiroConta;
use App\Services\Auth\AdminCredentialVerifier;
use App\Services\Caixa\CaixaReportService;
use App\Services\Caixa\CaixaSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Turnos de caixa — specs/028-caixa-sessoes/spec.md.
 */
class CaixaController extends BaseApiController
{
    public function __construct(
        private readonly CaixaSessionService $caixaSessionService,
        private readonly CaixaReportService $caixaReportService,
        private readonly AdminCredentialVerifier $adminCredentialVerifier
    ) {}

    /**
     * Estado atual do caixa: sessão aberta (se houver) e totais do turno.
     *
     * O valor esperado NÃO vai nesta resposta enquanto o caixa está aberto: a
     * conferência é cega.
     */
    public function current(Request $request): JsonResponse
    {
        $this->authorize('caixa:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        // Antes da primeira abertura não existe conta de caixa, e isso não é
        // erro: a tela mostra "nenhum caixa aberto" e oferece o botão de abrir,
        // que é o que cria a conta.
        try {
            $account = $this->caixaSessionService->resolveCashAccount();
        } catch (RuntimeException) {
            $account = null;
        }

        $session = $account !== null
            ? $this->caixaSessionService->currentSession((int) $account->id)
            : null;

        return $this->success(
            [
                'conta' => $account !== null
                    ? ['id' => (int) $account->id, 'nome' => (string) $account->nome]
                    : null,
                'sessao' => $session instanceof CaixaSessao
                    ? $this->caixaSessionService->mapDetail($session)
                    : null,
                'contas_destino' => $account !== null
                    ? $this->destinationAccounts((int) $account->id)
                    : [],
                'status_options' => CaixaSessao::statusOptions(),
            ],
            request: $request
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('caixa:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $paginator = $this->caixaSessionService->paginate($request->query());

        return $this->success(
            [
                'sessoes' => $paginator->items(),
                'status_options' => CaixaSessao::statusOptions(),
            ],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function show(Request $request, int $sessao): JsonResponse
    {
        $this->authorize('caixa:visualizar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $session = CaixaSessao::query()->find($sessao);

        if (! $session instanceof CaixaSessao) {
            return $this->error('Sessão de caixa não encontrada.', 404, 'CAIXA_SESSAO_NAO_ENCONTRADA', null, request: $request);
        }

        return $this->success(
            ['sessao' => $this->caixaSessionService->mapDetail($session)],
            request: $request
        );
    }

    public function open(OpenCaixaRequest $request): JsonResponse
    {
        $this->authorize('caixa:criar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        try {
            $session = $this->caixaSessionService->open($user, $request->validated());
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'CAIXA_ABERTURA_INVALIDA', null, request: $request);
        }

        return $this->success(
            ['sessao' => $this->caixaSessionService->mapDetail($session)],
            Response::HTTP_CREATED,
            request: $request
        );
    }

    public function storeMovement(StoreCaixaMovimentoRequest $request, int $sessao): JsonResponse
    {
        $this->authorize('caixa:editar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $session = CaixaSessao::query()->find($sessao);

        if (! $session instanceof CaixaSessao) {
            return $this->error('Sessão de caixa não encontrada.', 404, 'CAIXA_SESSAO_NAO_ENCONTRADA', null, request: $request);
        }

        try {
            $this->caixaSessionService->registerMovement($user, $session, $request->validated());
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'CAIXA_MOVIMENTO_INVALIDO', null, request: $request);
        }

        return $this->success(
            ['sessao' => $this->caixaSessionService->mapDetail($session->refresh())],
            Response::HTTP_CREATED,
            request: $request
        );
    }

    /**
     * Corrige o valor de abertura de um turno ainda aberto.
     *
     * Existe por causa da abertura automática: ela herda o fechamento anterior,
     * que pode não bater com o que está fisicamente na gaveta.
     */
    public function updateOpening(Request $request, int $sessao): JsonResponse
    {
        $this->authorize('caixa:editar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $validated = $request->validate([
            'valor_abertura' => ['required', 'numeric', 'min:0'],
        ]);

        $session = CaixaSessao::query()->find($sessao);

        if (! $session instanceof CaixaSessao) {
            return $this->error('Sessão de caixa não encontrada.', 404, 'CAIXA_SESSAO_NAO_ENCONTRADA', null, request: $request);
        }

        try {
            $session = $this->caixaSessionService->updateOpeningAmount($session, $validated['valor_abertura'], $user);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'CAIXA_ABERTURA_INVALIDA', null, request: $request);
        }

        return $this->success(
            ['sessao' => $this->caixaSessionService->mapDetail($session)],
            request: $request
        );
    }

    public function close(CloseCaixaRequest $request, int $sessao): JsonResponse
    {
        $this->authorize('caixa:editar');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $session = CaixaSessao::query()->find($sessao);

        if (! $session instanceof CaixaSessao) {
            return $this->error('Sessão de caixa não encontrada.', 404, 'CAIXA_SESSAO_NAO_ENCONTRADA', null, request: $request);
        }

        try {
            $session = $this->caixaSessionService->close($user, $session, $request->validated());
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'CAIXA_FECHAMENTO_INVALIDO', null, request: $request);
        }

        // Depois do fechamento o esperado deixa de ser segredo: a contagem já
        // foi feita e o comparativo é justamente o resultado da conferência.
        return $this->success(
            ['sessao' => $this->caixaSessionService->mapDetail($session)],
            request: $request
        );
    }

    /**
     * Reabre uma sessão fechada para correção. Exige credencial de admin: uma
     * conferência já assinada está sendo desfeita.
     */
    public function reopen(Request $request, int $sessao): JsonResponse
    {
        $this->authorize('caixa:excluir');

        $user = $this->authenticatedUser($request);
        if ($user === null) {
            return $this->unauthenticatedResponse($request);
        }

        $session = CaixaSessao::query()->find($sessao);

        if (! $session instanceof CaixaSessao) {
            return $this->error('Sessão de caixa não encontrada.', 404, 'CAIXA_SESSAO_NAO_ENCONTRADA', null, request: $request);
        }

        $email = trim((string) $request->input('admin_email', ''));
        $password = (string) $request->input('admin_password', '');

        if ($email === '' || $password === '') {
            return $this->error(
                'Reabrir um caixa fechado exige confirmação de um administrador.',
                422,
                'CAIXA_ADMIN_AUTH_REQUIRED',
                null,
                request: $request
            );
        }

        $verification = $this->adminCredentialVerifier->verify(
            $email,
            $password,
            'caixa-reopen-admin-auth',
            (string) $request->ip()
        );

        $errorResponse = $this->respondToAdminVerification(
            $verification,
            $request,
            'CAIXA_ADMIN_AUTH_RATE_LIMITED',
            'CAIXA_ADMIN_AUTH_INVALID'
        );

        if ($errorResponse !== null) {
            return $errorResponse;
        }

        try {
            $session = $this->caixaSessionService->reopen($user, $session);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'CAIXA_REABERTURA_INVALIDA', null, request: $request);
        }

        return $this->success(
            ['sessao' => $this->caixaSessionService->mapDetail($session)],
            request: $request
        );
    }

    /**
     * Relatório de fechamento. Padrão 80 mm; `?formato=a4` para folha inteira.
     */
    public function report(Request $request, int $sessao): Response
    {
        $this->authorize('caixa:visualizar');

        $session = CaixaSessao::query()->find($sessao);

        if (! $session instanceof CaixaSessao) {
            return $this->error('Sessão de caixa não encontrada.', 404, 'CAIXA_SESSAO_NAO_ENCONTRADA', null, request: $request);
        }

        $format = (string) $request->query('formato', '80mm');
        $format = in_array($format, ['80mm', 'a4'], true) ? $format : '80mm';

        try {
            return $this->caixaReportService->stream($session, $format);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                'Não foi possível gerar o relatório deste caixa.',
                422,
                'CAIXA_RELATORIO_INDISPONIVEL',
                null,
                request: $request
            );
        }
    }

    /**
     * Contas para onde a sangria pode ir — qualquer conta ativa que não seja a
     * própria gaveta.
     *
     * @return array<int, array{id: int, nome: string, tipo: string}>
     */
    private function destinationAccounts(int $cashAccountId): array
    {
        return FinanceiroConta::query()
            ->where('ativo', 1)
            ->whereKeyNot($cashAccountId)
            ->orderBy('nome')
            ->get(['id', 'nome', 'tipo'])
            ->map(static fn (FinanceiroConta $account): array => [
                'id' => (int) $account->id,
                'nome' => (string) $account->nome,
                'tipo' => (string) $account->tipo,
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function movementTypes(): array
    {
        return CaixaMovimento::types();
    }
}
