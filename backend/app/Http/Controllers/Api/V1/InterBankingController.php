<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FinanceiroConta;
use App\Services\Integrations\Inter\InterBankingService;
use App\Services\Integrations\Inter\InterCredentials;
use App\Services\Integrations\Inter\InterException;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saldo e extrato bancario (Banco Inter), somente leitura.
 */
class InterBankingController extends Controller
{
    public function __construct(
        private readonly InterBankingService $banking,
        private readonly InterCredentials $credentials,
    ) {
    }

    /** Estado da integracao — sem segredo nenhum no payload. */
    public function status(Request $request): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        return ApiResponse::success(['inter' => $this->credentials->resumo()], request: $request);
    }

    public function saldo(Request $request, FinanceiroConta $conta): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        if (($erro = $this->recusarContaNaoVinculada($conta, $request)) !== null) {
            return $erro;
        }

        try {
            return ApiResponse::success(
                ['saldo' => $this->banking->saldo($request->boolean('atualizar'))],
                request: $request
            );
        } catch (InterException $e) {
            return $this->respostaDeFalha($e, $request);
        }
    }

    public function extrato(Request $request, FinanceiroConta $conta): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        if (($erro = $this->recusarContaNaoVinculada($conta, $request)) !== null) {
            return $erro;
        }

        $validado = $request->validate([
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date'],
        ]);

        $ate = isset($validado['ate']) ? CarbonImmutable::parse($validado['ate']) : CarbonImmutable::today();
        $de = isset($validado['de']) ? CarbonImmutable::parse($validado['de']) : $ate->subDays(30);

        try {
            return ApiResponse::success(['extrato' => $this->banking->extrato($de, $ate)], request: $request);
        } catch (InterException $e) {
            return $this->respostaDeFalha($e, $request);
        }
    }

    /**
     * Saldo interno x saldo do banco.
     *
     * Endpoint de LEITURA: nunca cria ajuste. Corrigir divergencia e' decisao
     * humana, pelo fluxo de ajuste que ja existe, com autor registrado.
     */
    public function conciliacao(Request $request, FinanceiroConta $conta): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        if (($erro = $this->recusarContaNaoVinculada($conta, $request)) !== null) {
            return $erro;
        }

        try {
            return ApiResponse::success(
                ['conciliacao' => $this->banking->conciliacao($conta, $request->boolean('atualizar'))],
                request: $request
            );
        } catch (InterException $e) {
            return $this->respostaDeFalha($e, $request);
        }
    }

    private function recusarContaNaoVinculada(FinanceiroConta $conta, Request $request): ?JsonResponse
    {
        if (mb_strtolower(trim((string) ($conta->integracao_provider ?? ''))) === 'inter') {
            return null;
        }

        return ApiResponse::error(
            'Esta conta financeira nao esta vinculada ao Banco Inter.',
            422,
            'INTER_CONTA_NAO_VINCULADA',
            null,
            [],
            $request
        );
    }

    private function respostaDeFalha(InterException $e, Request $request): JsonResponse
    {
        // Falha temporaria (rede, 5xx, 429) e' 503: dizer 422 faria a tela
        // sugerir "confira os dados" quando o problema e' do banco e some
        // sozinho. O inverso tambem vale — por isso o codigo distingue os tres
        // casos, em vez de jogar tudo em INTER_INDISPONIVEL.
        $status = $e->ehFalhaTemporaria() ? 503 : 422;

        $codigo = match (true) {
            $e->ehCredencialInvalida() => 'INTER_CREDENCIAL_INVALIDA',
            $e->origemLocal => 'INTER_REQUISICAO_INVALIDA',
            default => 'INTER_INDISPONIVEL',
        };

        return ApiResponse::error($e->getMessage(), $status, $codigo, null, [], $request);
    }
}
