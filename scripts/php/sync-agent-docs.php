<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$docsRoot = $root . DIRECTORY_SEPARATOR . 'documentacao';
$aiDocsRoot = $docsRoot . DIRECTORY_SEPARATOR . '04-governanca-ai';
$specsRoot = $root . DIRECTORY_SEPARATOR . 'specs';
$sharedVersionFile = $root . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . 'version.php';
$openApiFile = $root . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'openapi.yaml';

ensureDir($aiDocsRoot);

$appVersion = file_exists($sharedVersionFile) ? require $sharedVersionFile : 'indefinida';
$apiVersion = extractYamlScalar($openApiFile, 'version') ?? 'indefinida';
$generatedAt = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);

$categories = collectDocumentation($docsRoot);
$specs = collectSpecs($specsRoot);
$sources = [
    'agentes' => [
        'AGENTS.md',
        'documentacao/04-governanca-ai/operacao-para-agentes.md',
        'documentacao/04-governanca-ai/manifesto-do-sistema.md',
    ],
    'arquitetura' => [
        'documentacao/00-visao-geral/arquitetura-alvo.md',
        'documentacao/03-arquitetura-tecnica/README.md',
        'backend/openapi.yaml',
    ],
    'governanca' => [
        '.specify/memory/constitution.md',
        'specs/',
        'documentacao/07-novas-implementacoes/historico-de-versoes.md',
    ],
];

$payload = [
    'generated_at' => $generatedAt,
    'system' => [
        'name' => 'sistema-erp',
        'app_version' => $appVersion,
        'api_version' => $apiVersion,
        'production_target' => 'Ubuntu VPS',
        'development_target' => 'Ubuntu Server LAN - BANCADA-02 (192.168.1.100)',
    ],
    'architecture' => [
        'backend' => 'Laravel 13.x em backend/ como fonte unica de verdade',
        'desktop' => 'Laravel/Blade em frontends/desktop consumindo apenas a API central',
        'mobile' => 'Next.js em frontends/mobile consumindo a mesma API central',
    ],
    'documentation_categories' => $categories,
    'specs' => $specs,
    'sources_of_truth' => $sources,
];

$manifestMd = buildManifestMarkdown($payload);
$manifestPath = $aiDocsRoot . DIRECTORY_SEPARATOR . 'manifesto-do-sistema.md';
$jsonPath = $aiDocsRoot . DIRECTORY_SEPARATOR . 'contexto-sistema.json';

file_put_contents($manifestPath, $manifestMd);
file_put_contents(
    $jsonPath,
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
);

echo "OK  documentacao/04-governanca-ai/manifesto-do-sistema.md" . PHP_EOL;
echo "OK  documentacao/04-governanca-ai/contexto-sistema.json" . PHP_EOL;

function ensureDir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException("Nao foi possivel criar o diretorio: {$path}");
    }
}

function extractYamlScalar(string $file, string $key): ?string
{
    if (!file_exists($file)) {
        return null;
    }

    $content = (string) file_get_contents($file);

    if ($key === 'version' && preg_match('/^info:\s*$(.*?)^[a-zA-Z_][a-zA-Z0-9_]*:\s*$/ms', $content, $section)) {
        if (preg_match('/^\s*version:\s*(.+)$/m', $section[1], $matches)) {
            return trim($matches[1], " \t\n\r\0\x0B\"'");
        }
    }

    $pattern = '/^\s*' . preg_quote($key, '/') . ':\s*(.+)$/m';
    if (preg_match($pattern, $content, $matches)) {
        return trim($matches[1], " \t\n\r\0\x0B\"'");
    }

    return null;
}

function collectDocumentation(string $docsRoot): array
{
    if (!is_dir($docsRoot)) {
        return [];
    }

    $categories = [];
    $iterator = new DirectoryIterator($docsRoot);
    foreach ($iterator as $item) {
        if ($item->isDot() || !$item->isDir()) {
            continue;
        }

        $categoryName = $item->getFilename();
        $files = [];
        $categoryIterator = new DirectoryIterator($item->getPathname());
        foreach ($categoryIterator as $doc) {
            if ($doc->isDot() || $doc->isDir()) {
                continue;
            }

            if (strtolower($doc->getExtension()) !== 'md') {
                continue;
            }

            $files[] = [
                'file' => 'documentacao/' . $categoryName . '/' . $doc->getFilename(),
                'title' => extractMarkdownTitle($doc->getPathname()) ?? $doc->getBasename('.md'),
            ];
        }

        usort($files, static fn (array $left, array $right): int => strcmp($left['file'], $right['file']));

        $categories[] = [
            'directory' => 'documentacao/' . $categoryName,
            'files' => $files,
        ];
    }

    usort($categories, static fn (array $left, array $right): int => strcmp($left['directory'], $right['directory']));

    return $categories;
}

function collectSpecs(string $specsRoot): array
{
    if (!is_dir($specsRoot)) {
        return [];
    }

    $specs = [];
    $iterator = new DirectoryIterator($specsRoot);
    foreach ($iterator as $item) {
        if ($item->isDot() || !$item->isDir()) {
            continue;
        }

        $path = $item->getPathname();
        $specTitle = extractMarkdownTitle($path . DIRECTORY_SEPARATOR . 'spec.md') ?? $item->getFilename();
        $specs[] = [
            'directory' => 'specs/' . $item->getFilename(),
            'title' => $specTitle,
            'artifacts' => [
                'spec' => file_exists($path . DIRECTORY_SEPARATOR . 'spec.md'),
                'plan' => file_exists($path . DIRECTORY_SEPARATOR . 'plan.md'),
                'tasks' => file_exists($path . DIRECTORY_SEPARATOR . 'tasks.md'),
                'research' => file_exists($path . DIRECTORY_SEPARATOR . 'research.md'),
                'quickstart' => file_exists($path . DIRECTORY_SEPARATOR . 'quickstart.md'),
                'analysis' => file_exists($path . DIRECTORY_SEPARATOR . 'analysis.md'),
                'data_model' => file_exists($path . DIRECTORY_SEPARATOR . 'data-model.md'),
                'contracts' => is_dir($path . DIRECTORY_SEPARATOR . 'contracts'),
                'checklists' => is_dir($path . DIRECTORY_SEPARATOR . 'checklists'),
            ],
        ];
    }

    usort($specs, static fn (array $left, array $right): int => strcmp($left['directory'], $right['directory']));

    return $specs;
}

function extractMarkdownTitle(string $file): ?string
{
    if (!file_exists($file)) {
        return null;
    }

    $handle = fopen($file, 'rb');
    if ($handle === false) {
        return null;
    }

    try {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (str_starts_with($line, '# ')) {
                return trim(substr($line, 2));
            }
        }
    } finally {
        fclose($handle);
    }

    return null;
}

function buildManifestMarkdown(array $payload): string
{
    $lines = [];
    $lines[] = '# Manifesto do Sistema';
    $lines[] = '';
    $lines[] = 'Gerado automaticamente por `scripts/php/sync-agent-docs.php`.';
    $lines[] = '';
    $lines[] = '- Gerado em: `' . $payload['generated_at'] . '`';
    $lines[] = '- Versao do sistema: `' . $payload['system']['app_version'] . '`';
    $lines[] = '- Versao da API: `' . $payload['system']['api_version'] . '`';
    $lines[] = '- Ambiente oficial de producao: `' . $payload['system']['production_target'] . '`';
    $lines[] = '- Ambiente local de referencia: `' . $payload['system']['development_target'] . '`';
    $lines[] = '';
    $lines[] = '## Arquitetura resumida';
    $lines[] = '';
    $lines[] = '- Backend: ' . $payload['architecture']['backend'];
    $lines[] = '- Desktop: ' . $payload['architecture']['desktop'];
    $lines[] = '- Mobile: ' . $payload['architecture']['mobile'];
    $lines[] = '';
    $lines[] = '## Fontes de verdade';
    $lines[] = '';
    foreach ($payload['sources_of_truth'] as $group => $paths) {
        $lines[] = '### ' . strtoupper((string) $group);
        $lines[] = '';
        foreach ($paths as $path) {
            $lines[] = '- `' . $path . '`';
        }
        $lines[] = '';
    }

    $lines[] = '## Categorias documentais';
    $lines[] = '';
    foreach ($payload['documentation_categories'] as $category) {
        $lines[] = '### `' . $category['directory'] . '`';
        $lines[] = '';
        foreach ($category['files'] as $file) {
            $lines[] = '- `' . $file['file'] . '` - ' . $file['title'];
        }
        $lines[] = '';
    }

    $lines[] = '## Inventario de specs';
    $lines[] = '';
    foreach ($payload['specs'] as $spec) {
        $present = [];
        foreach ($spec['artifacts'] as $artifact => $exists) {
            if ($exists) {
                $present[] = $artifact;
            }
        }

        $lines[] = '- `' . $spec['directory'] . '` - ' . $spec['title'] . ' | artefatos: ' . implode(', ', $present);
    }
    $lines[] = '';

    return implode(PHP_EOL, $lines) . PHP_EOL;
}
