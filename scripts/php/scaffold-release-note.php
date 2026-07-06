<?php

declare(strict_types=1);

$options = getopt('', ['version:', 'slug:', 'title:', 'date::']);

if (!isset($options['version'], $options['slug'], $options['title'])) {
    fwrite(
        STDERR,
        "Uso: php scripts/php/scaffold-release-note.php --version=3.1.9 --slug=assunto --title=\"Titulo\" [--date=2026-06-25]" . PHP_EOL
    );
    exit(1);
}

$root = dirname(__DIR__, 2);
$docsDir = $root . DIRECTORY_SEPARATOR . 'documentacao' . DIRECTORY_SEPARATOR . '07-novas-implementacoes';
$historyFile = $docsDir . DIRECTORY_SEPARATOR . 'historico-de-versoes.md';
$date = $options['date'] ?? (new DateTimeImmutable('now'))->format('Y-m-d');
$slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) $options['slug']));
$slug = trim((string) $slug, '-');
$version = (string) $options['version'];
$title = trim((string) $options['title']);

if ($slug === '') {
    throw new InvalidArgumentException('O slug nao pode ficar vazio.');
}

if (!is_dir($docsDir) && !mkdir($docsDir, 0777, true) && !is_dir($docsDir)) {
    throw new RuntimeException("Nao foi possivel criar {$docsDir}");
}

$noteFilename = "{$date}-{$slug}.md";
$notePath = $docsDir . DIRECTORY_SEPARATOR . $noteFilename;

if (!file_exists($notePath)) {
    $note = <<<MD
# {$title}

## Contexto

- versao: `{$version}`
- data: `{$date}`
- ambiente-alvo: `Ubuntu VPS`

## Entrega

- [preencher resumo tecnico da entrega]

## Impactos

- [listar contratos, modulos, banco, deploy e seguranca]

## Validacao

- [listar testes, comandos e verificacoes executadas]
MD;

    file_put_contents($notePath, $note . PHP_EOL);
    echo "OK  documentacao/07-novas-implementacoes/{$noteFilename}" . PHP_EOL;
} else {
    echo "OK  documento ja existente: documentacao/07-novas-implementacoes/{$noteFilename}" . PHP_EOL;
}

$historyHeader = "## v{$version} - {$date}";
$historyEntry = $historyHeader . PHP_EOL . PHP_EOL .
    "- nota tecnica criada em `documentacao/07-novas-implementacoes/{$noteFilename}`" . PHP_EOL .
    "- [preencher bullets resumidos da release]" . PHP_EOL . PHP_EOL;

$historyContent = file_exists($historyFile)
    ? (string) file_get_contents($historyFile)
    : '# Historico de versoes' . PHP_EOL . PHP_EOL;

if (!str_contains($historyContent, $historyHeader)) {
    // Insere logo apos a primeira linha (o titulo "# ..."), qualquer que seja
    // o texto exato dele (acentuado ou nao) — nao assumir um prefixo fixo aqui,
    // pois o titulo real do arquivo pode ter sido digitado sem acentos.
    $firstLineEnd = strpos($historyContent, PHP_EOL);
    if ($firstLineEnd !== false && str_starts_with(ltrim($historyContent), '#')) {
        $titleLine = substr($historyContent, 0, $firstLineEnd);
        $rest = ltrim(substr($historyContent, $firstLineEnd + strlen(PHP_EOL)));
        $historyContent = $titleLine . PHP_EOL . PHP_EOL . $historyEntry . $rest;
    } else {
        $historyContent = rtrim($historyContent) . PHP_EOL . PHP_EOL . $historyEntry;
    }

    file_put_contents($historyFile, $historyContent);
    echo "OK  documentacao/07-novas-implementacoes/historico-de-versoes.md" . PHP_EOL;
} else {
    echo "OK  historico ja contem v{$version}" . PHP_EOL;
}
