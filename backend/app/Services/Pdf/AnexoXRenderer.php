<?php

namespace App\Services\Pdf;

use App\Services\Fiscal\AnexoXLayout;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * PDFs do Anexo X — Relatório Mensal das Receitas Brutas (Res. CGSN 140/2018).
 *
 * Vive aqui porque `app/Services/Pdf/` é o único namespace autorizado a chamar
 * dompdf — regra do `PdfEngineGuardTest`, que existe para não surgirem
 * geradores paralelos espalhados pela aplicação.
 *
 * **Por que não é um tipo do `PdfTemplateRegistry`**, como os documentos da OS:
 * o Anexo X tem forma definida em norma. Expô-lo na tela de Modelos PDF
 * convidaria alguém a editar o layout de um documento entregue ao fisco — e um
 * formulário da Receita com campo removido ou renomeado não é questão de gosto.
 * Mesmo argumento do `NfseDanfseRenderer`. Por isso o layout é fixo, num Blade
 * versionado junto do código.
 *
 * **São dois PDFs separados, e isso é requisito, não organização.** O
 * formulário sai sozinho, exatamente como a norma o desenha. A relação de
 * documentos fiscais emitidos — que a última cláusula do próprio formulário
 * manda anexar — é um segundo arquivo. Anexada, não embutida: nada se
 * acrescenta ao corpo do Anexo X.
 */
class AnexoXRenderer
{
    public function __construct(private readonly AnexoXLayout $layout) {}

    /**
     * O formulário oficial, e nada mais.
     *
     * @param  array<string, mixed>  $relatorio  saída de `AnexoXService::relatorio()`
     * @return string bytes do PDF
     */
    public function renderFormulario(array $relatorio): string
    {
        $this->prepararCacheDeFontes();

        return Pdf::loadView('pdf.anexo-x', [
            'anexo' => $this->layout->montar($relatorio),
        ])->setPaper('a4')->output();
    }

    /**
     * Bloco anual: uma folha por mês do ano-calendário.
     *
     * Cada folha é o MESMO formulário do PDF mensal — os dois incluem
     * `pdf.partials.anexo-x-formulario`. Duplicar o Blade produziria, na
     * primeira correção feita só de um lado, uma folha divergente entregue ao
     * fisco como se fosse o formulário.
     *
     * @param  array<int, array<string, mixed>>  $relatorios  doze relatórios, de janeiro a dezembro
     * @return string bytes do PDF
     */
    public function renderFormularioAnual(array $relatorios): string
    {
        $this->prepararCacheDeFontes();

        return Pdf::loadView('pdf.anexo-x-anual', [
            'anexos' => array_map(fn (array $relatorio): array => $this->layout->montar($relatorio), $relatorios),
        ])->setPaper('a4')->output();
    }

    /**
     * @param  array<string, mixed>  $dados  saída de `AnexoXService::documentosEmitidosNoMes()`
     * @return string bytes do PDF
     */
    public function renderRelacaoDocumentos(array $dados): string
    {
        $this->prepararCacheDeFontes();

        return Pdf::loadView('pdf.anexo-x-documentos', [
            'relacao' => $this->layout->montarRelacaoDeDocumentos($dados),
        ])->setPaper('a4')->output();
    }

    /**
     * Garante que exista o diretório onde o dompdf grava as métricas das fontes.
     *
     * O dompdf não cria esse diretório: ele tenta `fopen(..., 'w+')` no arquivo
     * `.ufm` e estoura `ErrorException` se a pasta não existir. Como
     * `storage/fonts` não vem no repositório, **o primeiro PDF de um deploy
     * novo falharia** — e falharia na hora em que alguém precisa entregar o
     * relatório ao contador, não num teste.
     */
    private function prepararCacheDeFontes(): void
    {
        foreach (['font_dir', 'font_cache'] as $chave) {
            $diretorio = (string) config('dompdf.options.'.$chave);

            if ($diretorio !== '' && ! is_dir($diretorio)) {
                @mkdir($diretorio, 0775, true);
            }
        }
    }
}
