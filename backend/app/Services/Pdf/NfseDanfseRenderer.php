<?php

namespace App\Services\Pdf;

use App\Services\Fiscal\DanfseLayout;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * DANFSe gerado a partir do XML da NFS-e, conforme a Nota Técnica nº 008.
 *
 * Vive aqui porque `app/Services/Pdf/` é o único namespace autorizado a chamar
 * dompdf — regra do `PdfEngineGuardTest`, que existe para não surgirem
 * geradores paralelos espalhados pela aplicação.
 *
 * **Por que não é um tipo do `PdfTemplateRegistry`**, como os documentos da OS:
 * o DANFSe tem forma definida em norma e o conteúdo vem do XML devolvido pelo
 * Ambiente Nacional. Expô-lo na tela de Modelos PDF convidaria alguém a editar
 * o layout de um documento fiscal — e um DANFSe alterado que chega ao cliente
 * é problema com o fisco, não questão de gosto. Por isso o layout é fixo, num
 * Blade versionado junto do código.
 *
 * **Este DANFSe é o documento, não um sucedâneo dele.** A própria NT-008 diz
 * que serve "de base para a geração do DANFSe por meios de softwares de emissão
 * de NFS-e, ERPs e sistemas fiscais, motivo pelo qual a API de geração do
 * DANFSe será sobrestada em 1º de julho de 2026" — gerar aqui é o caminho
 * previsto. Quando o PDF do portal foi anexado, ele continua tendo precedência
 * na tela por ser o arquivo que o operador já tem em mãos.
 */
class NfseDanfseRenderer
{
    public function __construct(private readonly DanfseLayout $layout) {}

    /**
     * @param  array<string, mixed>  $nota  dados lidos do XML guardado
     * @param  string|null  $marcaDagua  "CANCELADA" ou "SUBSTITUÍDA" (itens 2.5.1 e 2.5.2)
     * @return string bytes do PDF
     */
    public function render(array $nota, ?string $marcaDagua = null): string
    {
        $this->prepararCacheDeFontes();

        return Pdf::loadView('pdf.nfse-danfse', [
            'danfse' => $this->layout->montar($nota, $marcaDagua),
            'logo' => $this->logo(),
            'fontes' => $this->fontes(),
        ])->setPaper('a4')->output();
    }

    /**
     * Garante que exista o diretório onde o dompdf grava as métricas das fontes.
     *
     * O dompdf não cria esse diretório: ele tenta `fopen(..., 'w+')` no arquivo
     * `.ufm` e estoura `ErrorException` se a pasta não existir. Como
     * `storage/fonts` não vem no repositório, **o primeiro DANFSe de um deploy
     * novo falharia** — e falharia na emissão de uma nota real, não num teste.
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

    /**
     * Caminhos das fontes que o Blade declara em `@font-face`.
     *
     * O item 2.4 da NT-008 exige **Arial** nos títulos e **Microsoft Sans
     * Serif** nos conteúdos. As duas são proprietárias da Microsoft e não podem
     * ser redistribuídas com o sistema, então o que vai embutido é a
     * **Liberation Sans**, que é metricamente compatível com a Arial — mesma
     * largura de avanço glifo a glifo.
     *
     * Isso não é detalhe estético: os tamanhos de campo do item 2.4.5 (77, 167,
     * 1297 caracteres...) pressupõem a métrica da Arial, e uma fonte mais larga
     * quebraria linha em outro lugar e empurraria o documento para a segunda
     * página — que a norma proíbe.
     *
     * Quem tiver licença das fontes da Microsoft coloca os arquivos em
     * `resources/fonts/danfse/` com os nomes abaixo e eles passam a ter
     * precedência, sem mexer em código. Ver o LEIA-ME daquele diretório.
     *
     * @return array<string, string> papel => caminho absoluto do arquivo
     */
    private function fontes(): array
    {
        $preferencias = [
            // Títulos (labels), item 2.4.1 e 2.4.2.
            'titulo' => ['Arial.ttf', 'LiberationSans-Regular.ttf'],
            'titulo_negrito' => ['Arial-Bold.ttf', 'LiberationSans-Bold.ttf'],
            // Conteúdo dos campos, item 2.4.4.
            'conteudo' => ['MicrosoftSansSerif.ttf', 'LiberationSans-Regular.ttf'],
            'conteudo_negrito' => ['MicrosoftSansSerif-Bold.ttf', 'LiberationSans-Bold.ttf'],
        ];

        $fontes = [];

        foreach ($preferencias as $papel => $candidatos) {
            foreach ($candidatos as $arquivo) {
                $caminho = resource_path('fonts/danfse/'.$arquivo);

                if (is_readable($caminho)) {
                    // Caminho de arquivo, e nao data URI: o `chroot` do dompdf e'
                    // o base_path do backend, entao `resources/` esta' liberado,
                    // e sao ~800 KB de fonte que nao precisam atravessar o HTML
                    // a cada emissao.
                    $fontes[$papel] = $caminho;

                    continue 2;
                }
            }
        }

        return $fontes;
    }

    /**
     * Logomarca oficial da NFS-e, exigida no canto esquerdo do cabeçalho
     * (item 2.4.3), embutida no HTML.
     *
     * Vai como `data:` URI em vez de caminho de arquivo porque o dompdf roda
     * com acesso remoto e a caminhos locais restrito — e um cabeçalho sem a
     * logomarca é DANFSe fora do modelo.
     */
    private function logo(): string
    {
        $caminho = resource_path('images/danfse/nfse-logo.png');

        if (! is_readable($caminho)) {
            return '';
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($caminho));
    }
}
