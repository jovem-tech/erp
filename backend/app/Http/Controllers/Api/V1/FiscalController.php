<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Fiscal\CertificadoA1Installer;
use App\Services\Fiscal\ProntidaoFiscalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preparacao para a emissao fiscal (spec 041).
 *
 * Por enquanto so' expoe o diagnostico de prontidao. A emissao em si e' das
 * fases 042-044 e nao passa por aqui.
 */
class FiscalController extends BaseApiController
{
    public function __construct(
        private readonly ProntidaoFiscalService $prontidao,
        private readonly CertificadoA1Installer $certificados
    ) {}

    /**
     * Instala o certificado A1 enviado pela tela.
     *
     * Autoriza por `configuracoes:editar`: o certificado é da empresa, não de
     * uma OS — e quem troca certificado é quem administra o sistema.
     */
    public function instalarCertificado(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        $request->validate([
            'arquivo' => ['required', 'file', 'max:10240'],
            'senha' => ['required', 'string', 'max:255'],
        ], [], ['arquivo' => 'arquivo do certificado', 'senha' => 'senha do certificado']);

        return $this->success(
            ['certificado' => $this->certificados->instalar($request->file('arquivo'), (string) $request->input('senha'))],
            request: $request
        );
    }

    public function removerCertificado(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        return $this->success(['certificado' => $this->certificados->remover()], request: $request);
    }

    public function prontidao(Request $request): JsonResponse
    {
        // Autoriza por `clientes` porque nesta fatia o relatorio so' fala de
        // cliente, e quem preenche o cadastro e' quem precisa ver o que falta.
        // Quando peca, servico e empresa entrarem (fatia 2), esta escolha volta
        // a mesa: o relatorio passara' a cruzar modulos.
        $this->authorize('clientes:visualizar');

        return $this->success($this->prontidao->verificar(), request: $request);
    }
}
