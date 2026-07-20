<?php

namespace Tests\Unit\Services\Files;

use App\Services\Files\FilePathGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FilePathGuardTest extends TestCase
{
    #[DataProvider('unsafePaths')]
    public function test_rejects_unsafe_relative_paths(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FilePathGuard::normalizeRelativePath($path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafePaths(): array
    {
        return [
            'empty' => [''],
            'absolute unix' => ['/etc/passwd'],
            'absolute windows' => ['C:\\Windows\\system.ini'],
            'traversal' => ['managed/../secret'],
            'dot segment' => ['managed/./file'],
            'protocol' => ['file://secret'],
            'null byte' => ["managed/file\0.png"],
        ];
    }

    public function test_normalizes_windows_separators_without_changing_scope(): void
    {
        $this->assertSame(
            'managed-files/company_logo/file.png',
            FilePathGuard::normalizeRelativePath('managed-files\\company_logo\\file.png')
        );
    }
}
