<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\View;

/**
 * Renderiza o schema declarativo (cabecalho/corpo/rodape) para HTML pronto
 * para o dompdf. Nenhuma query acontece aqui: o DocumentContext chega
 * completo da factory. Todo texto passa pelo PdfVariableResolver (escape
 * por padrão); os partials blade recebem strings já resolvidas/escapadas e
 * só cuidam de markup.
 */
class PdfTemplateRenderer
{
    /**
     * Teto de segurança para recursão de condicional/colunas — independente
     * dos limites (3/2) que o PdfSchemaValidator exige no publish. Rascunhos
     * não publicados não passam por essa validação (preview é deliberadamente
     * permissivo), então esse teto evita recursão sem limite/estouro de
     * memória ao pré-visualizar um schema fora do padrão.
     */
    private const MAX_RENDER_DEPTH = 12;

    /**
     * Blocos que anunciam o que vem depois e, sozinhos no pé da página,
     * quebram a leitura ("GARANTIA" na página 1 e o texto na 2).
     *
     * @var array<int, string>
     */
    private const HEADING_TYPES = ['cabecalho_secao', 'titulo', 'subtitulo'];

    /**
     * Até quantas linhas uma tabela é considerada "curta" e viaja inteira.
     * Acima disso ela cabe em mais de meia página e dividi-la é o certo.
     */
    private const SHORT_TABLE_ROWS = 12;

    public function __construct(
        private readonly PdfVariableResolver $resolver
    ) {
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $context
     * @param array<string, mixed> $descriptor
     * @param string $formato 'a4' | '80mm'
     */
    public function render(array $schema, array $context, array $descriptor, string $formato = 'a4'): string
    {
        $formato = $formato === '80mm' ? '80mm' : 'a4';
        $pagina = is_array($schema['pagina'] ?? null) ? $schema['pagina'] : [];

        $areas = [];
        foreach (PdfSchemaValidator::AREAS as $area) {
            $blocks = is_array($schema[$area] ?? null) ? $schema[$area] : [];
            $areas[$area] = $this->renderBlocks($blocks, $context, $descriptor, $formato, 0);
        }

        return View::make('pdf-engine.document', [
            'formato' => $formato,
            'fonte' => trim((string) ($pagina['fonte'] ?? '')) ?: 'DejaVu Sans',
            'margens' => is_array($pagina['margens'] ?? null) ? $pagina['margens'] : [],
            'cabecalhoHtml' => $areas['cabecalho'],
            'corpoHtml' => $areas['corpo'],
            'rodapeHtml' => $areas['rodape'],
        ])->render();
    }

    /**
     * @param array<int, mixed> $blocks
     * @param array<string, mixed> $context
     * @param array<string, mixed> $descriptor
     */
    private function renderBlocks(array $blocks, array $context, array $descriptor, string $formato, int $depth): string
    {
        $blocks = array_values(array_filter($blocks, 'is_array'));
        $html = '';

        for ($index = 0; $index < count($blocks); $index++) {
            $block = $blocks[$index];
            $rendered = $this->renderBlock($block, $context, $descriptor, $formato, $depth);

            if ($rendered === '') {
                continue;
            }

            // Cabeçalho de seção nunca fica órfão no fim da página: ele é
            // amarrado ao primeiro bloco visível que vier depois. Vale para
            // TODOS os documentos, inclusive modelos criados no editor — é
            // regra do motor, não de um template específico.
            if ($this->isHeading($block)) {
                [$companionHtml, $nextIndex] = $this->renderKeepCompanion(
                    $blocks,
                    $index + 1,
                    $context,
                    $descriptor,
                    $formato,
                    $depth
                );

                $html .= '<div class="pdfe-keep">'.$rendered.$companionHtml.'</div>';
                $index = $nextIndex - 1;

                continue;
            }

            $html .= $rendered;
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function isHeading(array $block): bool
    {
        return in_array(strtolower(trim((string) ($block['tipo'] ?? ''))), self::HEADING_TYPES, true);
    }

    /**
     * O bloco carrega um cabeçalho em qualquer profundidade? Se carrega, ele
     * abre a própria seção e não pode ser absorvido pela anterior.
     *
     * @param  array<mixed>  $node
     */
    private function containsHeading(array $node): bool
    {
        foreach ($node as $value) {
            if (! is_array($value)) {
                continue;
            }

            if ($this->isHeading($value) || $this->containsHeading($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Renderiza a seção inteira que pertence ao cabeçalho: tudo até o próximo
     * cabeçalho (ou o fim da lista).
     *
     * Prender só o primeiro bloco seguinte não bastava — "Condições de
     * pagamento" saía com uma linha na página 1 e o resto (parcelamento e a
     * tabela de chave Pix) na página 2. A unidade de leitura é a seção inteira.
     *
     * Seção maior que uma página continua quebrando: o dompdf trata
     * `page-break-inside: avoid` como preferência, então o cabeçalho desce
     * junto do começo do conteúdo e o excedente segue nas páginas seguintes.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $descriptor
     * @return array{0: string, 1: int} HTML da seção e índice do próximo bloco a processar
     */
    private function renderKeepCompanion(
        array $blocks,
        int $index,
        array $context,
        array $descriptor,
        string $formato,
        int $depth
    ): array {
        $html = '';
        $total = count($blocks);
        $temConteudo = false;

        while ($index < $total) {
            $block = $blocks[$index];

            // Uma seção termina onde a próxima começa. "Próxima seção" é todo
            // bloco que traz um cabeçalho — direto (cabecalho_secao) ou dentro
            // de si (um `condicional` que abre a própria seção). Já um
            // condicional SEM cabeçalho é conteúdo desta seção mesmo (ex.: a
            // linha de parcelamento e a tabela de chave Pix dentro de
            // "Condições de pagamento") e entra no grupo.
            //
            // Sem essa distinção, "Itens do orçamento" engolia todas as seções
            // seguintes num grupo só e jogava o documento inteiro para a
            // página seguinte.
            if ($temConteudo && ($this->isHeading($block) || $this->containsHeading($block))) {
                break;
            }

            $rendered = $this->renderBlock($block, $context, $descriptor, $formato, $depth);
            $index++;

            // Bloco invisível neste formato (ex.: só a4) não entra no grupo.
            if ($rendered === '') {
                continue;
            }

            $html .= $rendered;

            if (! $this->isHeading($block)) {
                $temConteudo = true;
            }
        }

        return [$html, $index];
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     * @param array<string, mixed> $descriptor
     */
    private function renderBlock(array $block, array $context, array $descriptor, string $formato, int $depth): string
    {
        $visivelEm = is_array($block['visivel_em'] ?? null) ? $block['visivel_em'] : PdfSchemaValidator::PAPERS;
        if (! in_array($formato, $visivelEm, true)) {
            return '';
        }

        $tipo = strtolower(trim((string) ($block['tipo'] ?? '')));
        $variableTypes = is_array($descriptor['variables'] ?? null) ? $descriptor['variables'] : [];

        return match ($tipo) {
            'condicional' => $this->renderConditional($block, $context, $descriptor, $formato, $depth),
            'colunas' => $this->renderColumns($block, $context, $descriptor, $formato, $depth),
            'tabela' => $this->renderTable($block, $context, $descriptor, $formato),
            'tabela_totais' => $this->renderTotalsTable($block, $context, $variableTypes),
            'lista' => $this->renderList($block, $context, $descriptor),
            'imagem' => $this->renderImage($block, $context),
            'fotos_entrada' => $this->renderPhotoGallery($context),
            'grade_campos' => $this->renderFieldGrid($block, $context, $variableTypes, $formato),
            'texto_rico' => $this->partial('texto-rico', [
                'html' => \App\Support\TemplateHtmlSanitizer::sanitize(
                    $this->resolver->resolveRichText((string) ($block['html'] ?? ''), $context, $variableTypes)
                ),
            ]),
            'titulo', 'subtitulo', 'cabecalho_secao', 'paragrafo', 'observacoes' => $this->partial($tipo, [
                'texto' => $this->resolver->resolveText((string) ($block['texto'] ?? ''), $context, $variableTypes),
                'alinhamento' => $this->alignment($block),
                'borda' => (bool) ($block['borda'] ?? true),
            ]),
            'campo' => $this->partial('campo', [
                'rotulo' => $this->resolver->resolveText((string) ($block['rotulo'] ?? ''), $context, $variableTypes),
                'valor' => $this->resolver->resolveText((string) ($block['valor'] ?? ''), $context, $variableTypes),
            ]),
            'divisor' => $this->partial('divisor', [
                'espessura' => max(1, (int) ($block['espessura'] ?? 1)),
            ]),
            'espacador' => $this->partial('espacador', [
                'altura' => max(1, min(60, (int) ($block['altura_mm'] ?? 6))),
            ]),
            'assinatura' => $this->partial('assinatura', [
                'rotulos' => array_slice(array_values(array_filter(array_map(
                    fn ($rotulo): string => $this->resolver->resolveText((string) $rotulo, $context, $variableTypes),
                    is_array($block['rotulos'] ?? null) ? $block['rotulos'] : []
                ), static fn (string $rotulo): bool => $rotulo !== '')), 0, 2),
                'linhaData' => (bool) ($block['linha_data'] ?? false),
                'imagens' => [
                    (string) ($context['assinaturas']['responsavel']['imagem'] ?? ''),
                    (string) ($context['assinaturas']['cliente']['imagem'] ?? ''),
                ],
                'detalhes' => [
                    is_array($context['assinaturas']['responsavel'] ?? null)
                        ? $context['assinaturas']['responsavel']
                        : [],
                    is_array($context['assinaturas']['cliente'] ?? null)
                        ? $context['assinaturas']['cliente']
                        : [],
                ],
            ]),
            'botao_link' => $this->renderLinkButton($block, $context, $variableTypes, $formato),
            'quebra_pagina' => $formato === '80mm' ? '' : '<div style="page-break-before: always;"></div>',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     * @param array<string, mixed> $descriptor
     */
    /**
     * Botão de ação (ex.: aprovar orçamento). O destino sai de uma variável do
     * contexto e só vira link clicável se for http(s) — assim um template não
     * consegue transformar o botão em `javascript:` ou `file:` apontando uma
     * variável qualquer da allowlist.
     *
     * No cupom 80mm não existe clique: o endereço é impresso por extenso, que é
     * o que o cliente consegue usar num papel térmico.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $context
     * @param  array<string, string>  $variableTypes
     */
    private function renderLinkButton(array $block, array $context, array $variableTypes, string $formato): string
    {
        $url = trim((string) $this->resolver->lookup(
            strtolower(trim((string) ($block['variavel'] ?? ''))),
            $context
        ));

        if (! $this->isSafeUrl($url)) {
            $url = '';
        }

        $texto = $this->resolver->resolveText((string) ($block['texto'] ?? ''), $context, $variableTypes);
        $legenda = $this->resolver->resolveText((string) ($block['legenda'] ?? ''), $context, $variableTypes);

        if (trim(strip_tags($texto)) === '' && $url === '') {
            return '';
        }

        return $this->partial('botao-link', [
            'url' => $this->resolver->escape($url),
            'texto' => $texto,
            'legenda' => $legenda,
            'alinhamento' => $this->alignment($block),
            // Papel térmico não clica: imprime o endereço.
            'mostrarUrl' => $formato === '80mm',
        ]);
    }

    private function isSafeUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x1f\s]/', $url) === 1) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && trim((string) parse_url($url, PHP_URL_HOST)) !== '';
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     * @param array<string, mixed> $descriptor
     */
    private function renderConditional(array $block, array $context, array $descriptor, string $formato, int $depth): string
    {
        if ($depth >= self::MAX_RENDER_DEPTH) {
            return '';
        }

        $se = is_array($block['se'] ?? null) ? $block['se'] : [];
        $value = $this->resolver->lookup(strtolower(trim((string) ($se['variavel'] ?? ''))), $context);
        $operador = strtolower(trim((string) ($se['operador'] ?? 'preenchido')));
        $expected = (string) ($se['valor'] ?? '');

        $matches = match ($operador) {
            'preenchido' => $value !== null && trim((string) $value) !== '',
            'vazio' => $value === null || trim((string) $value) === '',
            'igual' => trim((string) $value) === trim($expected),
            'diferente' => trim((string) $value) !== trim($expected),
            default => false,
        };

        if (! $matches) {
            return '';
        }

        $children = is_array($block['blocos'] ?? null) ? $block['blocos'] : [];

        return $this->renderBlocks($children, $context, $descriptor, $formato, $depth + 1);
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     * @param array<string, mixed> $descriptor
     */
    private function renderColumns(array $block, array $context, array $descriptor, string $formato, int $depth): string
    {
        if ($depth >= self::MAX_RENDER_DEPTH) {
            return '';
        }

        $columns = is_array($block['colunas'] ?? null) ? $block['colunas'] : [];
        if ($columns === []) {
            return '';
        }

        // 80mm: colunas viram empilhamento vertical (largura não comporta).
        if ($formato === '80mm') {
            $html = '';
            foreach ($columns as $column) {
                $html .= $this->renderBlocks(is_array($column) ? $column : [], $context, $descriptor, $formato, $depth + 1);
            }

            return $html;
        }

        $cells = array_map(
            fn ($column): string => $this->renderBlocks(is_array($column) ? $column : [], $context, $descriptor, $formato, $depth + 1),
            $columns
        );

        return $this->partial('colunas', [
            'celulas' => $cells,
            'larguras' => $this->columnWidths($block, count($cells)),
        ]);
    }

    /**
     * @param array<string, mixed> $block
     * @return array<int, float>
     */
    private function columnWidths(array $block, int $columnCount): array
    {
        if ($columnCount <= 0) {
            return [];
        }

        $configured = is_array($block['larguras'] ?? null) ? array_values($block['larguras']) : [];
        if (count($configured) === $columnCount) {
            $widths = array_map(static fn ($width): float => is_numeric($width) ? (float) $width : 0.0, $configured);
            $total = array_sum($widths);

            $hasInvalidWidth = false;
            foreach ($widths as $width) {
                if ($width <= 0) {
                    $hasInvalidWidth = true;
                    break;
                }
            }

            if ($total > 0 && ! $hasInvalidWidth) {
                return array_map(static fn (float $width): float => ($width / $total) * 100, $widths);
            }
        }

        return array_fill(0, $columnCount, 100 / $columnCount);
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     * @param array<string, mixed> $descriptor
     */
    private function renderTable(array $block, array $context, array $descriptor, string $formato): string
    {
        $variableTypes = is_array($descriptor['variables'] ?? null) ? $descriptor['variables'] : [];
        $collections = is_array($descriptor['collections'] ?? null) ? $descriptor['collections'] : [];
        $columnsConfig = is_array($block['colunas'] ?? null) ? $block['colunas'] : [];
        $fonte = strtolower(trim((string) ($block['fonte'] ?? '')));

        $headers = [];
        foreach ($columnsConfig as $column) {
            $headers[] = [
                'rotulo' => $this->resolver->escape((string) (is_array($column) ? ($column['rotulo'] ?? '') : '')),
                'alinhamento' => $this->alignment(is_array($column) ? $column : []),
                'largura' => is_array($column) && isset($column['largura']) ? max(4, min(80, (int) $column['largura'])) : null,
            ];
        }

        $rows = [];

        if ($fonte !== '') {
            $collectionTypes = is_array($collections[$fonte] ?? null) ? $collections[$fonte] : [];
            $records = $this->resolver->lookup($fonte, $context);
            $records = is_array($records) ? $records : [];

            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }

                $cells = [];
                foreach ($columnsConfig as $column) {
                    $campo = strtolower(trim((string) (is_array($column) ? ($column['campo'] ?? '') : '')));
                    $formatoCelula = strtolower(trim((string) (is_array($column) ? ($column['formato'] ?? '') : '')))
                        ?: (string) ($collectionTypes[$campo] ?? '');
                    $cells[] = [
                        'valor' => nl2br($this->resolver->escape(
                            $this->resolver->format($record[$campo] ?? null, $formatoCelula)
                        ), false),
                        'alinhamento' => $this->alignment(is_array($column) ? $column : []),
                    ];
                }

                $rows[] = $cells;
            }
        } else {
            foreach ($block['linhas_estaticas'] ?? [] as $staticRow) {
                if (! is_array($staticRow)) {
                    continue;
                }

                $cells = [];
                $cellIndex = 0;
                foreach ($staticRow as $cell) {
                    $column = $columnsConfig[$cellIndex] ?? [];
                    $cells[] = [
                        'valor' => $this->resolver->resolveText((string) $cell, $context, $variableTypes),
                        'alinhamento' => $this->alignment(is_array($column) ? $column : []),
                    ];
                    $cellIndex++;
                }

                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            $vazio = trim((string) ($block['vazio_texto'] ?? ''));

            return $vazio !== ''
                ? $this->partial('observacoes', ['texto' => $this->resolver->escape($vazio), 'alinhamento' => 'esquerda', 'borda' => true])
                : '';
        }

        $totais = [];
        foreach ($block['totais'] ?? [] as $total) {
            if (! is_array($total)) {
                continue;
            }

            $totais[] = $this->prepareTotalLine($total, $context, $variableTypes);
        }

        return $this->partial('tabela', [
            'headers' => $headers,
            'rows' => $rows,
            'totais' => $totais,
            'repetirCabecalho' => (bool) ($block['repetir_cabecalho'] ?? true),
            // Tabela curta cabe inteira numa página: mantém-se indivisível para
            // o cabeçalho nunca ficar sozinho no pé, com as linhas na página
            // seguinte. Tabela longa continua quebrando (e repetindo o thead),
            // porque forçá-la inteira só empurraria o problema adiante.
            'manterJunta' => (count($rows) + count($totais)) <= self::SHORT_TABLE_ROWS,
        ]);
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     * @param array<string, string> $variableTypes
     */
    private function renderTotalsTable(array $block, array $context, array $variableTypes): string
    {
        $linhas = [];
        foreach ($block['linhas'] ?? [] as $linha) {
            if (! is_array($linha)) {
                continue;
            }

            $linhas[] = $this->prepareTotalLine($linha, $context, $variableTypes);
        }

        return $linhas === [] ? '' : $this->partial('tabela-totais', ['linhas' => $linhas]);
    }

    /**
     * @param array<string, mixed> $linha
     * @param array<string, mixed> $context
     * @param array<string, string> $variableTypes
     * @return array{rotulo: string, valor: string, destaque: bool}
     */
    private function prepareTotalLine(array $linha, array $context, array $variableTypes): array
    {
        $path = strtolower(trim((string) ($linha['variavel'] ?? '')));
        $formato = strtolower(trim((string) ($linha['formato'] ?? ''))) ?: (string) ($variableTypes[$path] ?? 'moeda');

        return [
            'rotulo' => $this->resolver->escape((string) ($linha['rotulo'] ?? '')),
            'valor' => $this->resolver->escape($this->resolver->format($this->resolver->lookup($path, $context), $formato)),
            'destaque' => (bool) ($linha['destaque'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     * @param array<string, mixed> $descriptor
     */
    private function renderList(array $block, array $context, array $descriptor): string
    {
        $variableTypes = is_array($descriptor['variables'] ?? null) ? $descriptor['variables'] : [];
        $fonte = strtolower(trim((string) ($block['fonte'] ?? '')));
        $itens = [];

        if ($fonte !== '') {
            $campo = strtolower(trim((string) ($block['campo'] ?? 'descricao')));
            $records = $this->resolver->lookup($fonte, $context);

            foreach (is_array($records) ? $records : [] as $record) {
                $valor = is_array($record) ? trim((string) ($record[$campo] ?? '')) : '';
                if ($valor !== '') {
                    $itens[] = $this->resolver->escape($valor);
                }
            }
        } else {
            foreach ($block['itens_estaticos'] ?? [] as $item) {
                $resolved = $this->resolver->resolveText((string) $item, $context, $variableTypes);
                if (trim($resolved) !== '') {
                    $itens[] = $resolved;
                }
            }
        }

        if ($itens === []) {
            $vazio = trim((string) ($block['vazio_texto'] ?? ''));

            return $vazio !== ''
                ? $this->partial('observacoes', ['texto' => $this->resolver->escape($vazio), 'alinhamento' => 'esquerda', 'borda' => true])
                : '';
        }

        return $this->partial('lista', [
            'itens' => $itens,
            'numerada' => strtolower(trim((string) ($block['estilo'] ?? ''))) === 'numerada',
        ]);
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     */
    private function renderImage(array $block, array $context): string
    {
        $token = strtolower(trim((string) ($block['token'] ?? ''), '() '));

        // Somente fontes internas, previamente convertidas para data URI.
        // A gramática do template não aceita URL nem caminho de arquivo.
        $source = match ($token) {
            'logo_empresa' => (string) $this->resolver->lookup('empresa.logo_base64', $context),
            'foto_equipamento_principal' => (string) $this->resolver->lookup('equipamento.foto_principal_base64', $context),
            default => '',
        };

        if ($source === '' || ! str_starts_with($source, 'data:image/')) {
            return '';
        }

        return $this->partial('imagem', [
            'src' => $source,
            'larguraMax' => max(24, min(400, (int) ($block['largura_max'] ?? 140))),
            'alinhamento' => $this->alignment($block),
        ]);
    }

    /**
     * Até 4 fotos de recepção (check-in) da OS, lado a lado. Fonte fixa
     * (nunca configurável no schema) — a própria factory de contexto já
     * decide quais fotos existem e as converte para data URI; aqui só
     * filtramos por segurança (nunca confiar em algo que não seja data URI
     * de imagem) e limitamos a 4.
     *
     * @param array<string, mixed> $context
     */
    private function renderPhotoGallery(array $context): string
    {
        $fotos = $this->resolver->lookup('os.fotos_entrada', $context);
        $fotos = is_array($fotos)
            ? array_values(array_filter(
                $fotos,
                static fn ($foto): bool => is_string($foto) && str_starts_with($foto, 'data:image/')
            ))
            : [];

        if ($fotos === []) {
            return '';
        }

        return $this->partial('fotos-entrada', ['fotos' => array_slice($fotos, 0, 4)]);
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     * @param array<string, string> $variableTypes
     */
    private function renderFieldGrid(array $block, array $context, array $variableTypes, string $formato): string
    {
        $campos = [];
        foreach ($block['campos'] ?? [] as $campo) {
            if (! is_array($campo)) {
                continue;
            }

            $campos[] = [
                'rotulo' => $this->resolver->resolveText((string) ($campo['rotulo'] ?? ''), $context, $variableTypes),
                'valor' => $this->resolver->resolveText((string) ($campo['valor'] ?? ''), $context, $variableTypes),
            ];
        }

        if ($campos === []) {
            return '';
        }

        return $this->partial('grade-campos', [
            'campos' => $campos,
            // Recibos térmicos precisam de um par rótulo/valor por linha.
            // No A4 preservamos a densidade configurada pelo editor.
            'colunas' => $formato === '80mm'
                ? 1
                : max(1, min(4, (int) ($block['colunas'] ?? 2))),
        ]);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function alignment(array $block): string
    {
        return match (strtolower(trim((string) ($block['alinhamento'] ?? '')))) {
            'centro' => 'center',
            'direita' => 'right',
            default => 'left',
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function partial(string $name, array $data): string
    {
        return View::make('pdf-engine.blocks.' . $name, $data)->render();
    }
}
