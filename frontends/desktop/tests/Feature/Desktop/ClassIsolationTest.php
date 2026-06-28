<?php

namespace Tests\Feature\Desktop;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class ClassIsolationTest extends TestCase
{
    public function test_desktop_runtime_classes_do_not_collide_with_backend_runtime_classes(): void
    {
        $desktopAppPath = base_path('app');
        $backendAppPath = base_path('../../backend/app');

        $desktopFiles = $this->phpFileMap($desktopAppPath);
        $backendFiles = $this->phpFileMap($backendAppPath);

        $collisions = array_values(array_intersect($desktopFiles, $backendFiles));

        $this->assertSame(
            [],
            $collisions,
            'Classes runtime duplicadas entre desktop e backend podem colidir no Apache compartilhado: ' . implode(', ', $collisions)
        );
    }

    /**
     * @return array<int, string>
     */
    private function phpFileMap(string $rootPath): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath)
        );

        $files = [];

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($fileInfo->getPathname(), strlen($rootPath) + 1);
            $files[] = str_replace('\\', '/', $relativePath);
        }

        sort($files);

        return $files;
    }
}
