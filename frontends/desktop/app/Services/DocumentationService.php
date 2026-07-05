<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Leitura segura da documentacao oficial do repositorio (pasta `documentacao/`
 * na raiz do monorepo) para exibicao na aba Documentacao de Configuracoes do
 * Sistema.
 *
 * Seguranca: somente arquivos `.md` resolvidos por caminho canonico dentro da
 * raiz de documentacao (ou da whitelist de arquivos da raiz do repo) podem ser
 * lidos. O Markdown e renderizado com `html_input=escape` e links inseguros
 * bloqueados.
 */
class DocumentationService
{
    /**
     * Arquivos da raiz do monorepo liberados para leitura, alem da pasta
     * `documentacao/`.
     *
     * @var array<string, string>
     */
    private const ROOT_FILES = [
        'README.md' => 'Visao geral do repositorio',
        'VERSIONING.md' => 'Protocolo de versionamento',
        'CHANGELOG.md' => 'Changelog (protocolo 4 posicoes)',
        'AGENTS.md' => 'Guia para agentes de IA',
    ];

    /**
     * Rotulos amigaveis por categoria da pasta documentacao/.
     *
     * @var array<string, string>
     */
    private const CATEGORY_LABELS = [
        '00-visao-geral' => 'Visao geral',
        '01-fundacao' => 'Fundacao',
        '02-infraestrutura-ambientes' => 'Infraestrutura e ambientes',
        '03-arquitetura-tecnica' => 'Arquitetura tecnica',
        '04-governanca-ai' => 'Governanca de IA',
        '07-novas-implementacoes' => 'Novas implementacoes',
        '10-deploy' => 'Deploy e operacao',
    ];

    public function repositoryRoot(): string
    {
        $configured = (string) config('desktop.docs_repository_root', '');

        if ($configured !== '' && is_dir($configured)) {
            return rtrim($configured, '/\\');
        }

        return dirname(base_path(), 2);
    }

    public function docsRoot(): string
    {
        return $this->repositoryRoot() . DIRECTORY_SEPARATOR . 'documentacao';
    }

    /**
     * Arvore de navegacao agrupada por categoria.
     *
     * @return array<int, array{key: string, label: string, items: array<int, array{path: string, title: string}>}>
     */
    public function tree(): array
    {
        $groups = [];

        $rootItems = [];
        foreach (self::ROOT_FILES as $file => $fallbackTitle) {
            $absolute = $this->repositoryRoot() . DIRECTORY_SEPARATOR . $file;
            if (is_file($absolute)) {
                $rootItems[] = [
                    'path' => 'raiz/' . $file,
                    'title' => $this->extractTitle($absolute) ?? $fallbackTitle,
                ];
            }
        }

        if ($rootItems !== []) {
            $groups[] = ['key' => 'raiz', 'label' => 'Raiz do repositorio', 'items' => $rootItems];
        }

        $docsRoot = $this->docsRoot();

        if (! is_dir($docsRoot)) {
            return $groups;
        }

        $readme = $docsRoot . DIRECTORY_SEPARATOR . 'README.md';
        if (is_file($readme)) {
            $groups[] = [
                'key' => 'indice',
                'label' => 'Indice geral',
                'items' => [[
                    'path' => 'README.md',
                    'title' => $this->extractTitle($readme) ?? 'Indice da documentacao',
                ]],
            ];
        }

        $directories = glob($docsRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        sort($directories);

        foreach ($directories as $directory) {
            $key = basename($directory);
            $items = [];

            foreach ($this->markdownFilesIn($directory) as $absolute) {
                $relative = $key . '/' . ltrim(str_replace('\\', '/', substr($absolute, strlen($directory))), '/');
                $items[] = [
                    'path' => $relative,
                    'title' => $this->extractTitle($absolute) ?? basename($absolute),
                ];
            }

            if ($items !== []) {
                $groups[] = [
                    'key' => $key,
                    'label' => self::CATEGORY_LABELS[$key] ?? $key,
                    'items' => $items,
                ];
            }
        }

        return $groups;
    }

    /**
     * Le e renderiza um documento como HTML seguro.
     *
     * @return array{path: string, title: string, html: string}|null
     */
    public function read(string $relativePath): ?array
    {
        $absolute = $this->resolve($relativePath);

        if ($absolute === null) {
            return null;
        }

        $content = (string) file_get_contents($absolute);

        return [
            'path' => $relativePath,
            'title' => $this->extractTitle($absolute) ?? basename($absolute),
            'html' => $this->render($content, $relativePath),
        ];
    }

    /**
     * Resolve um caminho relativo da UI para um arquivo permitido.
     */
    private function resolve(string $relativePath): ?string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));

        if ($relativePath === '' || str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
            return null;
        }

        if (! str_ends_with(strtolower($relativePath), '.md')) {
            return null;
        }

        if (str_starts_with($relativePath, 'raiz/')) {
            $file = substr($relativePath, strlen('raiz/'));

            if (! array_key_exists($file, self::ROOT_FILES)) {
                return null;
            }

            $absolute = $this->repositoryRoot() . DIRECTORY_SEPARATOR . $file;

            return is_file($absolute) ? $absolute : null;
        }

        $docsRoot = realpath($this->docsRoot());

        if ($docsRoot === false) {
            return null;
        }

        $absolute = realpath($this->docsRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        if ($absolute === false || ! str_starts_with($absolute, $docsRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($absolute) ? $absolute : null;
    }

    private function render(string $markdown, string $currentPath): string
    {
        $html = (string) Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 32,
        ]);

        // Reescreve links relativos para outros .md como links internos do viewer.
        return (string) preg_replace_callback(
            '/href="([^"]+\.md)(#[^"]*)?"/i',
            function (array $matches) use ($currentPath): string {
                $target = $matches[1];

                if (preg_match('#^(https?:)?//#i', $target) === 1) {
                    return $matches[0];
                }

                $resolved = $this->resolveRelativeDocPath($currentPath, $target);

                if ($resolved === null) {
                    return 'href="#" data-doc-unavailable="1"';
                }

                $url = route('configurations.system.index', ['tab' => 'documentacao', 'doc' => $resolved]);

                return 'href="' . e($url) . '"';
            },
            $html
        );
    }

    /**
     * Resolve um link relativo entre documentos para o formato de caminho da UI.
     */
    private function resolveRelativeDocPath(string $currentPath, string $target): ?string
    {
        $target = str_replace('\\', '/', $target);

        if (str_starts_with($currentPath, 'raiz/')) {
            $baseSegments = [];
        } else {
            $baseSegments = explode('/', $currentPath);
            array_pop($baseSegments);
        }

        foreach (explode('/', $target) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($baseSegments === []) {
                    return null; // sairia da raiz de documentacao
                }
                array_pop($baseSegments);
                continue;
            }

            $baseSegments[] = $segment;
        }

        $resolved = implode('/', $baseSegments);

        return $this->resolve($resolved) !== null ? $resolved : null;
    }

    /**
     * Extrai o primeiro titulo `# ...` do arquivo (ate 10 primeiras linhas).
     */
    private function extractTitle(string $absolutePath): ?string
    {
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            for ($i = 0; $i < 10; $i++) {
                $line = fgets($handle);

                if ($line === false) {
                    return null;
                }

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

    /**
     * @return array<int, string>
     */
    private function markdownFilesIn(string $directory): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }

            if (str_starts_with($file->getFilename(), '.')) {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }
}
