<?php

namespace App\Services\Files;

final class FilePathGuard
{
    public static function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $segments = explode('/', $path);

        if (
            $path === ''
            || str_contains($path, "\0")
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path) === 1
            || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1
            || in_array('..', $segments, true)
            || in_array('.', $segments, true)
            || in_array('', $segments, true)
        ) {
            throw new \InvalidArgumentException('Caminho relativo inseguro.');
        }

        return implode('/', $segments);
    }

    public static function safeFileName(string $name, string $fallbackExtension): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?? '';
        $name = trim($name, " .\t\n\r\0\x0B");

        if ($name === '') {
            $name = 'arquivo.'.preg_replace('/[^a-z0-9]+/', '', strtolower($fallbackExtension));
        }

        if (mb_strlen($name) > 200) {
            $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            $suffix = $extension !== '' ? '.'.$extension : '';
            $name = rtrim(mb_substr((string) pathinfo($name, PATHINFO_FILENAME), 0, 200 - mb_strlen($suffix)), ' .').$suffix;
        }

        return $name;
    }
}
