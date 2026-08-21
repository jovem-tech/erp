<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\CancelFinanceiroCartaoCreditoInvoiceRequest;
use App\Http\Requests\Api\V1\PayFinanceiroCartaoCreditoInvoiceRequest;
use App\Http\Requests\Api\V1\StoreForgottenFinanceiroCartaoCreditoExpenseRequest;
use App\Http\Requests\Api\V1\UpsertFinanceiroCartaoCreditoRequest;
use App\Models\FinanceiroCartaoCredito;
use App\Services\Auth\AdminCredentialVerifier;
use App\Services\Financeiro\FinanceiroCartaoCreditoService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Cartões de crédito da assistência (usados para COMPRAR).
 *
 * Não confundir com FinanceiroCartaoController, que trata de operadora/
 * bandeira/taxa da maquininha — RECEBER do cliente. Vive sob a permissão de
 * contas_saldos porque a tela mora dentro de "Contas e Saldos".
 */
class FinanceiroCartaoCreditoController extends BaseApiController
{
    public function __construct(
        private readonly FinanceiroCartaoCreditoService $cartaoCreditoService,
        private readonly AdminCredentialVerifier $adminCredentialVerifier
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('contas_saldos:visualizar');

        try {
            $cartoes = $this->cartaoCreditoService->dashboard();
        } catch (QueryException $exception) {
            return $this->databaseFailure($exception, $request, 'FINANCEIRO_CARTOES_CREDITO_QUERY_FAILED');
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível consultar os cartões de crédito.', 500, 'FINANCEIRO_CARTOES_CREDITO_QUERY_FAILED', request: $request);
        }

        return $this->success(['cartoes' => $cartoes], request: $request);
    }

    public function store(UpsertFinanceiroCartaoCreditoRequest $request): JsonResponse
    {
        $this->authorize('contas_saldos:criar');

        try {
            $cartao = $this->cartaoCreditoService->create(
                $request->validated(),
                $this->authenticatedUser($request)?->id
            );
        } catch (QueryException $exception) {
            return $this->databaseFailure($exception, $request, 'FINANCEIRO_CARTAO_CREDITO_SAVE_FAILED');
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_CARTAO_CREDITO_SAVE_FAILED', request: $request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível salvar o cartão de crédito.', 500, 'FINANCEIRO_CARTAO_CREDITO_SAVE_FAILED', request: $request);
        }

        return $this->success(['cartao' => $cartao], 201, request: $request);
    }

    public function update(UpsertFinanceiroCartaoCreditoRequest $request, FinanceiroCartaoCredito $cartaoCredito): JsonResponse
    {
        $this->authorize('contas_saldos:editar');

        try {
            $cartao = $this->cartaoCreditoService->update(
                $cartaoCredito,
                $request->validated(),
                $this->authenticatedUser($request)?->id
            );
        } catch (QueryException $exception) {
            return $this->databaseFailure($exception, $request, 'FINANCEIRO_CARTAO_CREDITO_SAVE_FAILED');
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_CARTAO_CREDITO_SAVE_FAILED', request: $request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível salvar o cartão de crédito.', 500, 'FINANCEIRO_CARTAO_CREDITO_SAVE_FAILED', request: $request);
        }

        return $this->success(['cartao' => $cartao], request: $request);
    }

    /**
     * Prévia do vencimento da fatura para uma data de compra — alimenta o
     * campo somente-leitura do formulário de despesa, usando o mesmo cálculo
     * que roda no save, para a tela nunca mostrar uma data diferente da que
     * será gravada.
     */
    public function previewFatura(Request $request, FinanceiroCartaoCredito $cartaoCredito): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        $dataCompra = trim((string) $request->query('data_compra', ''));

        try {
            $referencia = $dataCompra !== '' ? CarbonImmutable::parse($dataCompra) : CarbonImmutable::today();
        } catch (Throwable) {
            return $this->error('Data da compra inválida.', 422, 'FINANCEIRO_CARTAO_CREDITO_PREVIEW_FAILED', request: $request);
        }

        $ciclo = $this->cartaoCreditoService->resolveInvoiceCycle($cartaoCredito, $referencia);

        // Alimenta o "min" do campo de data da compra e o aviso do formulário:
        // compra não entra em fatura já paga (ver FinanceiroService::
        // resolveClassification(), que barra de verdade no save).
        $ciclo['compra_minima'] = $this->cartaoCreditoService->minimumPurchaseDate($cartaoCredito);
        $ciclo['fatura_paga'] = $this->cartaoCreditoService->isInvoiceSettled(
            $cartaoCredito,
            $ciclo['data_vencimento']
        );

        return $this->success(['fatura' => $ciclo], request: $request);
    }

    public function faturas(Request $request, FinanceiroCartaoCredito $cartaoCredito): JsonResponse
    {
        $this->authorize('contas_saldos:visualizar');

        $filtros = [
            'mes' => $request->query('mes'),
            'vencimento_de' => $request->query('vencimento_de'),
            'vencimento_ate' => $request->query('vencimento_ate'),
            'situacao' => $request->query('situacao'),
        ];

        try {
            $faturas = $this->cartaoCreditoService->invoiceList($cartaoCredito, $filtros);
            // A fatura atual sai da lista SEM filtro: é o indicador do topo da
            // tela e não pode sumir só porque o usuário filtrou por outro mês.
            $faturaAtual = $this->cartaoCreditoService->currentInvoice(
                $this->cartaoCreditoService->invoiceList($cartaoCredito)
            );
        } catch (QueryException $exception) {
            return $this->databaseFailure($exception, $request, 'FINANCEIRO_CARTAO_CREDITO_FATURAS_FAILED');
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível consultar as faturas deste cartão.', 500, 'FINANCEIRO_CARTAO_CREDITO_FATURAS_FAILED', request: $request);
        }

        return $this->success([
            'cartao' => $cartaoCredito,
            'faturas' => $faturas,
            'fatura_atual' => $faturaAtual,
        ], request: $request);
    }

    public function faturaShow(Request $request, FinanceiroCartaoCredito $cartaoCredito, string $dataVencimento): JsonResponse
    {
        $this->authorize('contas_saldos:visualizar');

        try {
            $detalhe = $this->cartaoCreditoService->invoiceDetail($cartaoCredito, $dataVencimento);
        } catch (QueryException $exception) {
            return $this->databaseFailure($exception, $request, 'FINANCEIRO_CARTAO_CREDITO_FATURA_FAILED');
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível consultar esta fatura.', 500, 'FINANCEIRO_CARTAO_CREDITO_FATURA_FAILED', request: $request);
        }

        return $this->success($detalhe, request: $request);
    }

    public function faturaPagar(
        PayFinanceiroCartaoCreditoInvoiceRequest $request,
        FinanceiroCartaoCredito $cartaoCredito,
        string $dataVencimento
    ): JsonResponse {
        $this->authorize('contas_saldos:editar');

        try {
            $resultado = $this->cartaoCreditoService->payInvoice(
                $cartaoCredito,
                $dataVencimento,
                $request->validated()
            );
        } catch (QueryException $exception) {
            return $this->databaseFailure($exception, $request, 'FINANCEIRO_CARTAO_CREDITO_FATURA_PAGAR_FAILED');
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_CARTAO_CREDITO_FATURA_PAGAR_FAILED', request: $request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível baixar esta fatura.', 500, 'FINANCEIRO_CARTAO_CREDITO_FATURA_PAGAR_FAILED', request: $request);
        }

        return $this->success(['resultado' => $resultado], request: $request);
    }

    public function faturaDespesaEsquecida(
        StoreForgottenFinanceiroCartaoCreditoExpenseRequest $request,
        FinanceiroCartaoCredito $cartaoCredito,
        string $dataVencimento
    ): JsonResponse {
        $this->authorize('contas_saldos:editar');
        $this->authorize('financeiro:criar');

        try {
            $despesa = $this->cartaoCreditoService->registerForgottenExpense(
                $cartaoCredito,
                $dataVencimento,
                $request->validated()
            );
        } catch (QueryException $exception) {
            return $this->databaseFailure($exception, $request, 'FINANCEIRO_CARTAO_CREDITO_DESPESA_ESQUECIDA_FAILED');
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_CARTAO_CREDITO_DESPESA_ESQUECIDA_FAILED', request: $request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível lançar a despesa nesta fatura.', 500, 'FINANCEIRO_CARTAO_CREDITO_DESPESA_ESQUECIDA_FAILED', request: $request);
        }

        return $this->success(['lancamento' => $despesa], 201, request: $request);
    }

    public function faturaCancelarBaixa(
        CancelFinanceiroCartaoCreditoInvoiceRequest $request,
        FinanceiroCartaoCredito $cartaoCredito,
        string $dataVencimento
    ): JsonResponse {
        $this->authorize('contas_saldos:editar');

        // Estorno apaga movimentos de dinheiro já conciliado — exige confirmação
        // de administrador, igual a excluir/cancelar um lançamento
        // (FinanceiroController::destroy()/cancel()). Sem requiredAbility de
        // propósito: mantém a MESMA regra desses fluxos irmãos (perfil='admin'),
        // para o admin que autoriza uma exclusão autorizar também um estorno.
        // Ver AdminCredentialVerifier::isAuthorizedAdministrator().
        $validated = $request->validated();

        $verification = $this->adminCredentialVerifier->verify(
            (string) $validated['admin_email'],
            (string) $validated['admin_password'],
            'financeiro-cartao-credito-estorno-admin-auth',
            (string) ($request->ip() ?: 'unknown')
        );

        $errorResponse = $this->respondToAdminVerification(
            $verification,
            $request,
            'FINANCEIRO_ADMIN_AUTH_RATE_LIMITED',
            'FINANCEIRO_ADMIN_AUTH_INVALID'
        );

        if ($errorResponse !== null) {
            return $errorResponse;
        }

        try {
            $resultado = $this->cartaoCreditoService->cancelInvoicePayment($cartaoCredito, $dataVencimento);
        } catch (QueryException $exception) {
            return $this->databaseFailure($exception, $request, 'FINANCEIRO_CARTAO_CREDITO_FATURA_ESTORNO_FAILED');
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'FINANCEIRO_CARTAO_CREDITO_FATURA_ESTORNO_FAILED', request: $request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível cancelar a baixa desta fatura.', 500, 'FINANCEIRO_CARTAO_CREDITO_FATURA_ESTORNO_FAILED', request: $request);
        }

        return $this->success(['resultado' => $resultado], request: $request);
    }
}
