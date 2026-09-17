<?php

namespace App\Services\Orders\Documents;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Cache em disco dos PDFs renderizados sob demanda. Descartável por
 * definição: chave derivada do snapshot + template + formato + perfil, TTL
 * deslizante por mtime e purga LRU pelo comando documents:purge-render-cache.
 * Nunca é fonte de verdade — quem manda é o snapshot (ou o arquivo
 * persistido do documento assinado).
 */
final class PdfRenderCache
{
    public static function key(string $hashSnapshot, int $templateVersaoId, string $hashSchema, string $formato, ?int $profileVersion = null): string
    {
        return hash('sha256', implode('|', [
            $hashSnapshot,
            (string) $templateVersaoId,
            $hashSchema,
            $formato,
            (string) ($profileVersion ?? (int) config('document-rendering.profile_version', 1)),
        ]));
    }

    public function enabled(): bool
    {
        return (bool) config('document-rendering.render_cache.enabled', true);
    }

    public function get(string $key): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $disk = $this->disk();
        $path = self::path($key);
        if (! $disk->exists($path)) {
            return null;
        }

        $bytes = $disk->get($path);
        if (! is_string($bytes) || $bytes === '' || ! str_starts_with($bytes, '%PDF-')) {
            $disk->delete($path);

            return null;
        }

        // TTL deslizante: toque no mtime a cada hit, para a purga por idade
        // só descartar o que ninguém abre mais.
        @touch($disk->path($path));

        return $bytes;
    }

    public function put(string $key, string $bytes): void
    {
        if (! $this->enabled() || $bytes === '' || ! str_starts_with($bytes, '%PDF-')) {
            return;
        }

        if (strlen($bytes) > (int) config('document-rendering.render_cache.max_entry_bytes', 25 * 1024 * 1024)) {
            return;
        }

        try {
            $disk = $this->disk();
            $path = self::path($key);
            $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
            $disk->put($temporary, $bytes);
            // rename atômico: leitor concorrente nunca vê um PDF pela metade.
            if (! @rename($disk->path($temporary), $disk->path($path))) {
                $disk->delete($temporary);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  \Closure(): string  $render
     */
    public function remember(string $key, \Closure $render): string
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }

        if (! $this->enabled()) {
            return $render();
        }

        return (string) Cache::lock('pdf-render:'.$key, 30)->block(10, function () use ($key, $render): string {
            $cached = $this->get($key);
            if ($cached !== null) {
                return $cached;
            }

            $bytes = $render();
            $this->put($key, $bytes);

            return $bytes;
        });
    }

    public function forget(string $key): void
    {
        try {
            $this->disk()->delete(self::path($key));
        } catch (Throwable) {
            // cache: falha ao apagar não é erro de negócio
        }
    }

    public static function path(string $key): string
    {
        return substr($key, 0, 2).'/'.$key.'.pdf';
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('document-rendering.render_cache.disk', 'pdf_render_cache'));
    }
}
