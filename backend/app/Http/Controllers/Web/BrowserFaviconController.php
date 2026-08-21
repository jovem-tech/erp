<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Company\CompanyProfileService;
use Illuminate\Http\Response;
use Throwable;

/**
 * /favicon.ico — o icone que o navegador busca sozinho quando a resposta nao
 * tem <head> para declarar <link rel="icon">: PDF de orcamento aberto na aba,
 * arquivo de documento compartilhado, download. Servir a logo da empresa aqui
 * faz essas abas seguirem a mesma marca das telas HTML (ver
 * resources/views/partials/favicon.blade.php).
 *
 * E' rota, e nao arquivo estatico em public/, porque a logo muda em
 * Configuracoes > Empresa: um .ico gravado no disco envelheceria na primeira
 * troca de marca.
 *
 * Nunca falha: sem logo cadastrada (ou sem GD para gerar o .ico) devolve o
 * icone padrao do ERP.
 */
class BrowserFaviconController extends Controller
{
    public function __construct(
        private readonly CompanyProfileService $companyProfileService
    ) {
    }

    public function __invoke(): Response
    {
        try {
            $ico = $this->companyProfileService->resolveFaviconIco();
        } catch (Throwable) {
            $ico = null;
        }

        if ($ico === null) {
            $ico = (string) file_get_contents(public_path('assets/img/favicon-default.ico'));
        }

        return response($ico, 200, [
            'Content-Type' => 'image/x-icon',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename=favicon.ico',
            'Cache-Control' => 'public, no-cache, must-revalidate',
        ]);
    }
}
