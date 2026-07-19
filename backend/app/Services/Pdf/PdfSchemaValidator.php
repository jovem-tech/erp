<?php

namespace App\Services\Pdf;

/**
 * Valida o schema declarativo de um template contra o catálogo de blocos e
 * a allowlist de variáveis/coleções do tipo documental. A publicação é
 * bloqueada (422) enquanto houver qualquer erro — inclusive placeholder
 * desconhecido, formatador inválido, fonte de tabela não permitida ou
 * profundidade excessiva de aninhamento.
 */
class PdfSchemaValidator
{
    public const AREAS = ['cabecalho', 'corpo', 'rodape'];

    public const BLOCK_TYPES = [
        'titulo', 'subtitulo', 'cabecalho_secao', 'paragrafo', 'texto_rico',
        'campo', 'grade_campos', 'colunas', 'tabela', 'tabela_totais', 'lista',
        'imagem', 'fotos_entrada', 'divisor', 'espacador', 'assinatura', 'observacoes',
        'quebra_pagina', 'condicional',
    ];

    public const PAPERS = ['a4', '80mm'];

    public const ORIENTATIONS = ['retrato', 'paisagem'];

    public const CONDITION_OPERATORS = ['preenchido', 'vazio', 'igual', 'diferente'];

    private const MAX_CONDITIONAL_DEPTH = 3;

    private const MAX_COLUMNS_DEPTH = 2;

    private const MAX_BLOCKS_TOTAL = 120;

    private const MAX_SIGNATURE_LABEL_LENGTH = 240;

    public function __construct(
        private readonly PdfVariableResolver $resolver
    ) {
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $descriptor descritor do tipo no PdfTemplateRegistry
     * @return array<int, string> lista de erros (vazia = válido)
     */
    public function validate(array $schema, array $descriptor): array
    {
        $errors = [];

        $pagina = is_array($schema['pagina'] ?? null) ? $schema['pagina'] : [];
        $papel = strtolower(trim((string) ($pagina['papel'] ?? 'a4')));
        if (! in_array($papel, self::PAPERS, true)) {
            $errors[] = sprintf('Papel inválido: "%s" (permitidos: %s).', $papel, implode(', ', self::PAPERS));
        }

        $orientacao = strtolower(trim((string) ($pagina['orientacao'] ?? 'retrato')));
        if (! in_array($orientacao, self::ORIENTATIONS, true)) {
            $errors[] = sprintf('Orientação inválida: "%s".', $orientacao);
        }

        $totalBlocks = 0;
        foreach (self::AREAS as $area) {
            $blocks = $schema[$area] ?? null;
            if (! is_array($blocks)) {
                $errors[] = sprintf('Área obrigatória ausente ou inválida: "%s".', $area);

                continue;
            }

            $this->validateBlocks($blocks, $descriptor, $area, 0, 0, $totalBlocks, $errors);
        }

        if ($totalBlocks > self::MAX_BLOCKS_TOTAL) {
            $errors[] = sprintf('Template excede o limite de %d blocos (%d).', self::MAX_BLOCKS_TOTAL, $totalBlocks);
        }

        return $errors;
    }

    /**
     * @param array<int, mixed> $blocks
     * @param array<string, mixed> $descriptor
     * @param array<int, string> $errors
     */
    private function validateBlocks(
        array $blocks,
        array $descriptor,
        string $area,
        int $conditionalDepth,
        int $columnsDepth,
        int &$totalBlocks,
        array &$errors
    ): void {
        foreach ($blocks as $index => $block) {
            $totalBlocks++;
            $label = sprintf('%s#%d', $area, $index + 1);

            if (! is_array($block)) {
                $errors[] = sprintf('Bloco inválido em %s.', $label);

                continue;
            }

            $tipo = strtolower(trim((string) ($block['tipo'] ?? '')));
            if (! in_array($tipo, self::BLOCK_TYPES, true)) {
                $errors[] = sprintf('Tipo de bloco desconhecido em %s: "%s".', $label, $tipo);

                continue;
            }

            $visivelEm = $block['visivel_em'] ?? null;
            if ($visivelEm !== null) {
                if (! is_array($visivelEm) || array_diff($visivelEm, self::PAPERS) !== []) {
                    $errors[] = sprintf('visivel_em inválido em %s (permitidos: a4, 80mm).', $label);
                }
            }

            match ($tipo) {
                'condicional' => $this->validateConditional($block, $descriptor, $label, $area, $conditionalDepth, $columnsDepth, $totalBlocks, $errors),
                'colunas' => $this->validateColumns($block, $descriptor, $label, $area, $conditionalDepth, $columnsDepth, $totalBlocks, $errors),
                'tabela' => $this->validateTable($block, $descriptor, $label, $errors),
                'tabela_totais' => $this->validateTotalsTable($block, $descriptor, $label, $errors),
                'lista' => $this->validateList($block, $descriptor, $label, $errors),
                'imagem' => $this->validateImage($block, $label, $errors),
                'grade_campos' => $this->validateFieldGrid($block, $descriptor, $label, $errors),
                'assinatura' => $this->validateSignature($block, $descriptor, $label, $errors),
                default => $this->validateTextualBlock($block, $descriptor, $label, $errors),
            };
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $descriptor
     * @param array<int, string> $errors
     */
    private function validateConditional(
        array $block,
        array $descriptor,
        string $label,
        string $area,
        int $conditionalDepth,
        int $columnsDepth,
        int &$totalBlocks,
        array &$errors
    ): void {
        if ($conditionalDepth + 1 > self::MAX_CONDITIONAL_DEPTH) {
            $errors[] = sprintf('Condicional excede a profundidade máxima (%d) em %s.', self::MAX_CONDITIONAL_DEPTH, $label);

            return;
        }

        $se = is_array($block['se'] ?? null) ? $block['se'] : [];
        $variavel = strtolower(trim((string) ($se['variavel'] ?? '')));
        $operador = strtolower(trim((string) ($se['operador'] ?? '')));

        if ($variavel === '' || ! $this->isKnownVariable($variavel, $descriptor)) {
            $errors[] = sprintf('Variável desconhecida na condição de %s: "%s".', $label, $variavel);
        }

        if (! in_array($operador, self::CONDITION_OPERATORS, true)) {
            $errors[] = sprintf('Operador de condição inválido em %s: "%s".', $label, $operador);
        }

        $children = is_array($block['blocos'] ?? null) ? $block['blocos'] : [];
        if ($children === []) {
            $errors[] = sprintf('Condicional sem blocos internos em %s.', $label);
        }

        $this->validateBlocks($children, $descriptor, $area, $conditionalDepth + 1, $columnsDepth, $totalBlocks, $errors);
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $descriptor
     * @param array<int, string> $errors
     */
    private function validateColumns(
        array $block,
        array $descriptor,
        string $label,
        string $area,
        int $conditionalDepth,
        int $columnsDepth,
        int &$totalBlocks,
        array &$errors
    ): void {
        if ($columnsDepth + 1 > self::MAX_COLUMNS_DEPTH) {
            $errors[] = sprintf('Bloco colunas excede a profundidade máxima (%d) em %s.', self::MAX_COLUMNS_DEPTH, $label);

            return;
        }

        $columns = is_array($block['colunas'] ?? null) ? $block['colunas'] : [];
        if (count($columns) < 1 || count($columns) > 3) {
            $errors[] = sprintf('Bloco colunas em %s deve ter entre 1 e 3 colunas.', $label);

            return;
        }

        $widths = $block['larguras'] ?? null;
        if ($widths !== null) {
            if (! is_array($widths) || count($widths) !== count($columns)) {
                $errors[] = sprintf('Larguras do bloco colunas em %s devem corresponder à quantidade de colunas.', $label);
            } else {
                $totalWidth = 0.0;
                foreach ($widths as $width) {
                    if (! is_numeric($width) || (float) $width <= 0) {
                        $errors[] = sprintf('Cada largura do bloco colunas em %s deve ser um número positivo.', $label);
                        $totalWidth = 0.0;
                        break;
                    }

                    $totalWidth += (float) $width;
                }

                if ($totalWidth > 0 && abs($totalWidth - 100.0) > 0.01) {
                    $errors[] = sprintf('As larguras do bloco colunas em %s devem totalizar 100%%.', $label);
                }
            }
        }

        foreach ($columns as $column) {
            $this->validateBlocks(
                is_array($column) ? $column : [],
                $descriptor,
                $area,
                $conditionalDepth,
                $columnsDepth + 1,
                $totalBlocks,
                $errors
            );
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $descriptor
     * @param array<int, string> $errors
     */
    private function validateTable(array $block, array $descriptor, string $label, array &$errors): void
    {
        $collections = is_array($descriptor['collections'] ?? null) ? $descriptor['collections'] : [];
        $fonte = strtolower(trim((string) ($block['fonte'] ?? '')));
        $linhasEstaticas = is_array($block['linhas_estaticas'] ?? null) ? $block['linhas_estaticas'] : null;
        $columns = is_array($block['colunas'] ?? null) ? $block['colunas'] : [];

        if ($columns === []) {
            $errors[] = sprintf('Tabela sem colunas em %s.', $label);
        }

        if ($fonte === '' && $linhasEstaticas === null) {
            $errors[] = sprintf('Tabela em %s precisa de "fonte" (coleção) ou "linhas_estaticas".', $label);

            return;
        }

        if ($fonte !== '' && $linhasEstaticas !== null) {
            $errors[] = sprintf('Tabela em %s não pode ter "fonte" e "linhas_estaticas" ao mesmo tempo.', $label);
        }

        if ($fonte !== '') {
            $collectionColumns = $collections[$fonte] ?? null;
            if (! is_array($collectionColumns)) {
                $errors[] = sprintf('Fonte de tabela não permitida em %s: "%s".', $label, $fonte);

                return;
            }

            foreach ($columns as $column) {
                $campo = strtolower(trim((string) (is_array($column) ? ($column['campo'] ?? '') : '')));
                if ($campo === '' || ! array_key_exists($campo, $collectionColumns)) {
                    $errors[] = sprintf('Coluna desconhecida na fonte "%s" em %s: "%s".', $fonte, $label, $campo);
                }
            }

            return;
        }

        // Linhas estáticas: células podem conter {{variáveis}} — valida os tokens.
        foreach ($linhasEstaticas as $rowIndex => $row) {
            if (! is_array($row)) {
                $errors[] = sprintf('Linha estática inválida em %s (linha %d).', $label, (int) $rowIndex + 1);

                continue;
            }

            foreach ($row as $cell) {
                $this->validateTokensInText((string) $cell, $descriptor, $label, $errors);
            }
        }

        foreach ($block['totais'] ?? [] as $total) {
            $this->validateTotalLine(is_array($total) ? $total : [], $descriptor, $label, $errors);
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $descriptor
     * @param array<int, string> $errors
     */
    private function validateTotalsTable(array $block, array $descriptor, string $label, array &$errors): void
    {
        $linhas = is_array($block['linhas'] ?? null) ? $block['linhas'] : [];
        if ($linhas === []) {
            $errors[] = sprintf('Tabela de totais sem linhas em %s.', $label);
        }

        foreach ($linhas as $linha) {
            $this->validateTotalLine(is_array($linha) ? $linha : [], $descriptor, $label, $errors);
        }
    }

    /**
     * @param array<string, mixed> $linha
     * @param array<string, mixed> $descriptor
     * @param array<int, string> $errors
     */
    private function validateTotalLine(array $linha, array $descriptor, string $label, array &$errors): void
    {
        $variavel = strtolower(trim((string) ($linha['variavel'] ?? '')));
        if ($variavel === '' || ! $this->isKnownVariable($variavel, $descriptor)) {
            $errors[] = sprintf('Variável desconhecida em linha de totais de %s: "%s".', $label, $variavel);
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $descriptor
     * @param array<int, string> $errors
     */
    private function validateList(array $block, array $descriptor, string $label, array &$errors): void
    {
        $collections = is_array($descriptor['collections'] ?? null) ? $descriptor['collections'] : [];
        $fonte = strtolower(trim((string) ($block['fonte'] ?? '')));
        $itensEstaticos = is_array($block['itens_estaticos'] ?? null) ? $block['itens_estaticos'] : null;

        if ($fonte === '' && $itensEstaticos === null) {
            $errors[] = sprintf('Lista em %s precisa de "fonte" ou "itens_estaticos".', $label);

            return;
        }

        if ($fonte !== '') {
            if (! array_key_exists($fonte, $collections)) {
                $errors[] = sprintf('Fonte de lista não permitida em %s: "%s".', $label, $fonte);

                return;
            }

            $campo = strtolower(trim((string) ($block['campo'] ?? 'descricao')));
            if (! array_key_exists($campo, $collections[$fonte])) {
                $errors[] = sprintf('Campo desconhecido na fonte "%s" da lista em %s: "%s".', $fonte, $label, $campo);
            }

            return;
        }

        foreach ($itensEstaticos as $item) {
            $this->validateTokensInText((string) $item, $descriptor, $label, $errors);
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int, string> $errors
     */
    private function validateImage(array $block, string $label, array &$errors): void
    {
        $token = trim((string) ($block['token'] ?? ''));
        $normalized = strtolower(trim($token, '() '));

        if ($normalized === '' || ! in_array($normalized, PdfTemplateRegistry::IMAGE_TOKENS, true)) {
            $errors[] = sprintf(
                'Token de imagem inválido em %s: "%s" (permitidos: %s). URLs externas não são suportadas.',
                $label,
                $token,
                implode(', ', PdfTemplateRegistry::IMAGE_TOKENS)
            );
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $descriptor
     * @param array<int, string> $errors
     */
    private function validateFieldGrid(array $block, array $descriptor, string $label, array &$errors): void
    {
        $colunas = (int) ($block['colunas'] ?? 2);
        if ($colunas < 1 || $colunas > 4) {
            $errors[] = sprintf('grade_campos em %s deve ter entre 1 e 4 colunas.', $label);
        }

        $campos = is_array($block['campos'] ?? null) ? $block['campos'] : [];
        if ($campos === []) {
            $errors[] = sprintf('grade_campos sem campos em %s.', $label);
        }

        foreach ($campos as $campo) {
            if (! is_array($campo)) {
                continue;
            }

            $this->validateTokensInText((string) ($campo['rotulo'] ?? ''), $descriptor, $label, $errors);
            $this->validateTokensInText((string) ($campo['valor'] ?? ''), $descriptor, $label, $errors);
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $descriptor
     * @param array<int, string> $errors
     */
    private function validateSignature(array $block, array $descriptor, string $label, array &$errors): void
    {
        $labels = $block['rotulos'] ?? null;
        if (! is_array($labels) || count($labels) < 1 || count($labels) > 2) {
            $errors[] = sprintf('Assinatura em %s deve ter entre 1 e 2 rótulos.', $label);

            return;
        }

        foreach ($labels as $index => $signatureLabel) {
            if (! is_string($signatureLabel) || trim($signatureLabel) === '') {
                $errors[] = sprintf('Rótulo %d da assinatura em %s é inválido.', (int) $index + 1, $label);

                continue;
            }

            if (mb_strlen($signatureLabel) > self::MAX_SIGNATURE_LABEL_LENGTH) {
                $errors[] = sprintf(
                    'Rótulo %d da assinatura em %s excede %d caracteres.',
                    (int) $index + 1,
                    $label,
                    self::MAX_SIGNATURE_LABEL_LENGTH
                );
            }

            $this->validateTokensInText($signatureLabel, $descriptor, $label, $errors);
        }

        if (array_key_exists('linha_data', $block) && ! is_bool($block['linha_data'])) {
            $errors[] = sprintf('linha_data da assinatura em %s deve ser booleana.', $label);
        }
    }

    /**
     * Blocos textuais simples: titulo, subtitulo, cabecalho_secao, paragrafo,
     * texto_rico, campo, assinatura, observacoes, divisor, espacador,
     * quebra_pagina.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $descriptor
     * @param array<int, string> $errors
     */
    private function validateTextualBlock(array $block, array $descriptor, string $label, array &$errors): void
    {
        foreach (['texto', 'rotulo', 'valor', 'html'] as $field) {
            if (isset($block[$field])) {
                $this->validateTokensInText((string) $block[$field], $descriptor, $label, $errors);
            }
        }
    }

    /**
     * @param array<string, mixed> $descriptor
     * @param array<int, string> $errors
     */
    private function validateTokensInText(string $text, array $descriptor, string $label, array &$errors): void
    {
        foreach ($this->resolver->extractTokens($text) as $token) {
            if (! $this->isKnownVariable($token['path'], $descriptor)) {
                $errors[] = sprintf('Variável desconhecida: %s (bloco %s).', $token['path'], $label);
            }

            if ($token['formatter'] !== '' && ! in_array($token['formatter'], PdfVariableResolver::FORMATTERS, true)) {
                $errors[] = sprintf('Formatador inválido: %s (bloco %s).', $token['formatter'], $label);
            }
        }

        // Tokens de imagem ((...)) fora do bloco imagem não são suportados.
        if (preg_match_all('/\(\(\s*([a-z0-9_:]+)\s*\)\)/i', $text, $matches)) {
            foreach ($matches[1] as $imageToken) {
                $errors[] = sprintf(
                    'Token de imagem "((%s))" só é permitido em bloco do tipo imagem (bloco %s).',
                    strtolower((string) $imageToken),
                    $label
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private function isKnownVariable(string $path, array $descriptor): bool
    {
        $variables = is_array($descriptor['variables'] ?? null) ? $descriptor['variables'] : [];

        return array_key_exists(strtolower(trim($path)), $variables);
    }
}
