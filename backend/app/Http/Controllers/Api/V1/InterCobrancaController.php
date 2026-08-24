<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Financeiro;
use App\Models\Inter\InterCobranca;
use App\Services\Integrations\Inter\InterCobrancaService;
use App\Services\Integrations\Inter\InterException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cobranca Pix de um titulo do financeiro.
 */
class InterCobrancaController extends Controller
{
    public function __construct(
        private readonly InterCobrancaService $cobrancas,
    ) {
    }

    public function show(Request $request, Financeiro $financeiro): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        $cobranca = $this->cobrancas->cobrancaAtivaDe($financeiro);

        return ApiResponse::success([
            'cobranca' => $cobranca instanceof InterCobranca ? $this->serializar($cobranca) : null,
        ], request: $request);
    }

    public function store(Request $request, Financeiro $financeiro): JsonResponse
    {
        $this->authorize('financeiro:criar');

        try {
            $cobranca = $this->cobrancas->emitir($financeiro, $request->user());
        } catch (InterException $e) {
            return $this->falha($e, $request);
        }

        return ApiResponse::success(['cobranca' => $this->serializar($cobranca)], 201, request: $request);
    }

    public function destroy(Request $request, Financeiro $financeiro): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $cobranca = $this->cobrancas->cobrancaAtivaDe($financeiro);

        if (! $cobranca instanceof InterCobranca) {
            return ApiResponse::error(
                'Nao ha cobranca Pix ativa para este titulo.',
                404,
                'INTER_COBRANCA_NAO_ENCONTRADA',
                null,
                [],
                $request
            );
        }

        try {
            $cobranca = $this->cobrancas->cancelar($cobranca, $request->user());
        } catch (InterException $e) {
            return $this->falha($e, $request);
        }

        return ApiResponse::success(['cobranca' => $this->serializar($cobranca)], request: $request);
    }

    /** @return array<string, mixed> */
    private function serializar(InterCobranca $cobranca): array
    {
        return [
            'id' => (int) $cobranca->id,
            'txid' => $cobranca->txid,
            'status' => $cobranca->status,
            'valor' => round((float) $cobranca->valor, 2),
            'valor_liquidado' => $cobranca->valorLiquidado(),
            'quitada' => $cobranca->estaQuitada(),
            'expira_em' => $cobranca->expira_em?->toIso8601String(),
            // O QR e' renderizado a partir do copia-e-cola; nao guardamos imagem.
            'pix_copia_e_cola' => $cobranca->pix_copia_e_cola,
            'financeiro_id' => $cobranca->financeiro_id,
            'os_id' => $cobranca->os_id,
            'criada_em' => $cobranca->created_at?->toIso8601String(),
        ];
    }

    private function falha(InterException $e, Request $request): JsonResponse
    {
        $status = $e->ehFalhaTemporaria() ? 503 : 422;

        $codigo = match (true) {
            $e->ehCredencialInvalida() => 'INTER_CREDENCIAL_INVALIDA',
            $e->origemLocal => 'INTER_REQUISICAO_INVALIDA',
            default => 'INTER_INDISPONIVEL',
        };

        return ApiResponse::error($e->getMessage(), $status, $codigo, null, [], $request);
    }
}
