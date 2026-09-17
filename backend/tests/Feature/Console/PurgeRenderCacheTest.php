<?php

namespace Tests\Feature\Console;

use App\Services\Orders\Documents\PdfRenderCache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeRenderCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('pdf_render_cache');
        config(['document-rendering.render_cache.enabled' => true]);
    }

    public function test_ttl_and_lru_limit_are_applied(): void
    {
        $cache = app(PdfRenderCache::class);
        $disk = $cache->disk();

        $keys = [];
        foreach (['velho', 'medio', 'novo'] as $i => $name) {
            $key = hash('sha256', $name);
            $keys[$name] = $key;
            $cache->put($key, '%PDF-1.4 '.str_repeat($name, 100));
        }
        touch($disk->path(PdfRenderCache::path($keys['velho'])), time() - 30 * 86400);
        touch($disk->path(PdfRenderCache::path($keys['medio'])), time() - 2 * 86400);

        $this->artisan('documents:purge-render-cache', ['--dry-run' => true, '--ttl-days' => 7])->assertSuccessful();
        $this->assertTrue($disk->exists(PdfRenderCache::path($keys['velho'])), 'dry-run não apaga');

        $this->artisan('documents:purge-render-cache', ['--ttl-days' => 7])->assertSuccessful();
        $this->assertTrue($disk->missing(PdfRenderCache::path($keys['velho'])), 'TTL vencido sai');
        $this->assertTrue($disk->exists(PdfRenderCache::path($keys['medio'])));
        $this->assertTrue($disk->exists(PdfRenderCache::path($keys['novo'])));

        // Teto de tamanho: descarta o menos recente até caber.
        $this->artisan('documents:purge-render-cache', ['--ttl-days' => 0, '--max-bytes' => 600])->assertSuccessful();
        $this->assertTrue($disk->missing(PdfRenderCache::path($keys['medio'])), 'LRU descarta o mais antigo');
        $this->assertTrue($disk->exists(PdfRenderCache::path($keys['novo'])));

        // get() renova o mtime (TTL deslizante) e rejeita conteúdo corrompido.
        $this->assertNotNull($cache->get($keys['novo']));
        $disk->put(PdfRenderCache::path($keys['novo']), 'lixo');
        $this->assertNull($cache->get($keys['novo']));
        $this->assertTrue($disk->missing(PdfRenderCache::path($keys['novo'])));
    }
}
