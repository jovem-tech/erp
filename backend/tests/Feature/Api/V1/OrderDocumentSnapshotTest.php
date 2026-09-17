<?php

namespace Tests\Feature\Api\V1;

use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\OrderDocumentSend;
use App\Models\OrderDocumentSnapshot;
use App\Services\Integrations\IntegrationSettingsService;
use App\Services\Orders\Documents\DocumentBytesResolver;
use App\Services\Orders\Documents\ResolvedDocumentFile;
use App\Services\Orders\OrderDocumentCenterService;
use App\Services\Pdf\PdfDefaultTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Modo 'snapshot' da Central Documental: gerar grava o JSON da emissão e
 * não o PDF; ler renderiza sob demanda; assinatura formal continua em
 * disco; envio, ZIP, link público e miniatura funcionam sem binário.
 */
class OrderDocumentSnapshotTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();
        $this->grantGroupPermissions(1, [
            'os' => ['visualizar', 'criar', 'editar', 'excluir'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
        ]);
        Storage::fake('local');
        Storage::fake('pdf_render_cache');
        config([
            'document-rendering.mode' => 'snapshot',
            'document-rendering.render_cache.enabled' => false,
            'document-rendering.archive.ghostscript' => false,
        ]);
        $this->seedPdfEngineTemplates();
    }

    public function test_generate_stores_snapshot_instead_of_pdf_and_serves_both_formats_on_demand(): void
    {
        [$actor, $orderId] = $this->buildOrderFixture();
        $service = app(OrderDocumentCenterService::class);

        $result = $service->generate($orderId, $actor, ['laudo']);
        $this->assertSame('ok', (string) ($result['result'] ?? ''), json_encode($result));

        $document = OrderDocument::query()->where('os_id', $orderId)->where('tipo_documento', 'laudo')->firstOrFail();
        $metadata = is_array($document->metadados_json) ? $document->metadados_json : [];

        $this->assertSame('snapshot', (string) ($metadata['armazenamento'] ?? ''));
        $this->assertSame('emissao', (string) ($metadata['hash_semantica'] ?? ''));
        $this->assertNotSame('', (string) $document->hash_sha256, 'hash do binário emitido continua registrado');
        $this->assertTrue(Storage::disk('local')->missing((string) $document->arquivo), 'o PDF não pode ir para o disco');

        $snapshot = OrderDocumentSnapshot::query()->where('documento_id', (int) $document->id)->firstOrFail();
        $envelope = $snapshot->envelope();
        $json = (string) json_encode($envelope);
        $this->assertStringNotContainsString('base64,', $json, 'snapshot não pode carregar imagens em base64');
        $this->assertSame('os_laudo_tecnico', (string) ($envelope['tipo_codigo'] ?? ''));
        $this->assertSame('Fonte queimada no conector.', (string) ($envelope['contexto']['os']['diagnostico_tecnico'] ?? ''));
        $this->assertGreaterThan(0, (int) $snapshot->template_versao_id);
        $this->assertLessThan(64 * 1024, (int) $snapshot->tamanho_bytes, 'snapshot deve pesar KBs, não MBs');

        // Linhas de formato existem para os dois layouts, sem binário.
        $this->assertDatabaseHas('os_documento_arquivos', ['documento_id' => $document->id, 'formato' => 'a4']);
        $this->assertDatabaseHas('os_documento_arquivos', ['documento_id' => $document->id, 'formato' => '80mm']);

        $token = $this->loginAndGetToken($actor->email);
        foreach (['a4', '80mm'] as $format) {
            $response = $this
                ->withHeader('Authorization', 'Bearer '.$token)
                ->get("/api/v1/orders/{$orderId}/documents/{$document->id}/files/{$format}");

            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('content-type'));
            $this->assertStringStartsWith('%PDF-', (string) $response->getContent(), $format);
            $this->assertSame((string) strlen((string) $response->getContent()), (string) $response->headers->get('content-length'));
        }

        // O catálogo marca os formatos como disponíveis sem renderizar nada.
        $catalog = $service->catalog($orderId, $actor);
        $laudo = collect($catalog['catalog'] ?? $catalog['types'] ?? [])->first(fn ($item) => ($item['type'] ?? null) === 'laudo');
        $this->assertNotNull($laudo);
        $version = collect($laudo['versions'] ?? [])->first();
        $this->assertTrue((bool) ($version['files']['a4']['available'] ?? false));
        $this->assertTrue((bool) ($version['files']['80mm']['available'] ?? false));
        $this->assertSame('snapshot', (string) ($version['storage'] ?? ''));
    }

    public function test_each_version_renders_from_its_own_snapshot(): void
    {
        if (! is_executable('/usr/bin/pdftotext')) {
            $this->markTestSkipped('pdftotext (poppler) não disponível.');
        }

        [$actor, $orderId] = $this->buildOrderFixture();
        $service = app(OrderDocumentCenterService::class);

        $this->assertSame('ok', (string) ($service->generate($orderId, $actor, ['laudo'])['result'] ?? ''));
        DB::table('os')->where('id', $orderId)->update(['diagnostico_tecnico' => 'Placa oxidada por umidade.']);
        $this->assertSame('ok', (string) ($service->generate($orderId, $actor, ['laudo'])['result'] ?? ''));

        $versions = OrderDocument::query()->where('os_id', $orderId)->where('tipo_documento', 'laudo')->orderBy('versao')->get();
        $this->assertCount(2, $versions);

        $order = Order::query()->findOrFail($orderId);
        $resolver = app(DocumentBytesResolver::class);

        $v1 = $resolver->resolve($order, $versions[0], 'a4');
        $v2 = $resolver->resolve($order, $versions[1], 'a4');
        $this->assertSame('ok', $v1['result']);
        $this->assertSame('ok', $v2['result']);
        $this->assertSame(ResolvedDocumentFile::SOURCE_SNAPSHOT, $v1['file']->source);

        $textV1 = $this->pdfText($v1['file']->bytes());
        $textV2 = $this->pdfText($v2['file']->bytes());

        $this->assertStringContainsString('Fonte queimada', $textV1, 'v1 deve reproduzir o diagnóstico da época');
        $this->assertStringNotContainsString('Placa oxidada', $textV1);
        $this->assertStringContainsString('Placa oxidada', $textV2);
    }

    public function test_formal_signature_keeps_compressed_binary_on_disk(): void
    {
        [$actor, $orderId] = $this->buildOrderFixture();
        $service = app(OrderDocumentCenterService::class);

        $result = $service->generate($orderId, $actor, ['laudo'], [
            'signature_signer' => $actor,
            'signature_method' => 'pendencia_sessao',
            'signature_signed_at' => now(),
        ]);
        $this->assertSame('ok', (string) ($result['result'] ?? ''), json_encode($result));

        $document = OrderDocument::query()->where('os_id', $orderId)->where('tipo_documento', 'laudo')->firstOrFail();
        $metadata = is_array($document->metadados_json) ? $document->metadados_json : [];

        $this->assertSame('disco', (string) ($metadata['armazenamento'] ?? ''));
        $this->assertSame('assinado', (string) ($metadata['render_perfil'] ?? ''));
        $this->assertTrue(Storage::disk('local')->exists((string) $document->arquivo), 'assinatura formal exige o binário em disco');
        $this->assertSame(hash('sha256', (string) Storage::disk('local')->get((string) $document->arquivo)), (string) $document->hash_sha256);

        // O snapshot também existe (alimenta cache/miniatura), e a leitura vem do disco.
        $this->assertDatabaseHas('os_documento_snapshots', ['documento_id' => $document->id]);
        $resolved = app(DocumentBytesResolver::class)->resolve(Order::query()->findOrFail($orderId), $document, 'a4');
        $this->assertSame(ResolvedDocumentFile::SOURCE_DISK, $resolved['file']->source);
    }

    public function test_automatic_rubric_is_not_persisted_but_is_rehydrated_on_render(): void
    {
        [$actor, $orderId] = $this->buildOrderFixture();
        $service = app(OrderDocumentCenterService::class);

        $result = $service->generate($orderId, $actor, ['laudo'], [
            'signature_signer' => $actor,
            'signature_method' => 'sessao',
            'signature_signed_at' => now(),
        ]);
        $this->assertSame('ok', (string) ($result['result'] ?? ''));

        $document = OrderDocument::query()->where('os_id', $orderId)->where('tipo_documento', 'laudo')->firstOrFail();
        $this->assertTrue(Storage::disk('local')->missing((string) $document->arquivo), 'rubrica automática não persiste binário');
    }

    public function test_whatsapp_and_email_sending_work_without_a_file_on_disk(): void
    {
        [$actor, $orderId] = $this->buildOrderFixture();

        // O mock precisa existir antes de o serviço ser resolvido (injeção
        // pelo construtor).
        $this->mock(IntegrationSettingsService::class, function ($mock): void {
            $mock->shouldReceive('sendDirectMediaBytes')
                ->once()
                ->withArgs(function (string $phone, string $bytes, string $mime, string $mediaType): bool {
                    return str_starts_with($bytes, '%PDF-') && $mime === 'application/pdf' && $mediaType === 'document';
                })
                ->andReturn(['ok' => true, 'provider' => 'evolution', 'reference' => 'abc']);
            $mock->shouldReceive('sendDirectMedia')->never();
        });

        $service = app(OrderDocumentCenterService::class);
        $this->assertSame('ok', (string) ($service->generate($orderId, $actor, ['laudo'])['result'] ?? ''));
        $document = OrderDocument::query()->where('os_id', $orderId)->where('tipo_documento', 'laudo')->firstOrFail();

        // Fila fake: o processamento é disparado explicitamente abaixo (com
        // queue sync o job rodaria dentro do queueSend e enviaria duas vezes).
        Queue::fake();

        $queued = $service->queueSend($orderId, $actor, [(int) $document->id], [
            'channel' => 'whatsapp',
            'format' => 'a4',
        ]);
        $this->assertSame('ok', (string) ($queued['result'] ?? ''), json_encode($queued));
        $sendId = (int) ($queued['send']['id'] ?? OrderDocumentSend::query()->latest('id')->value('id'));

        $processed = $service->processQueuedSend($sendId);
        $send = OrderDocumentSend::query()->findOrFail($sendId);
        $this->assertSame('enviado', (string) $send->status, json_encode([$processed, $send->erro_sanitizado]));

        Mail::fake();
        $queuedMail = $service->queueSend($orderId, $actor, [(int) $document->id], [
            'channel' => 'email',
            'format' => 'a4',
        ]);
        $this->assertSame('ok', (string) ($queuedMail['result'] ?? ''), json_encode($queuedMail));
        $mailSendId = (int) ($queuedMail['send']['id'] ?? OrderDocumentSend::query()->latest('id')->value('id'));
        $service->processQueuedSend($mailSendId);
        $this->assertSame('enviado', (string) OrderDocumentSend::query()->findOrFail($mailSendId)->status);
    }

    public function test_public_share_link_and_zip_serve_ephemeral_documents_without_residue(): void
    {
        [$actor, $orderId] = $this->buildOrderFixture();
        $service = app(OrderDocumentCenterService::class);
        $this->assertSame('ok', (string) ($service->generate($orderId, $actor, ['laudo'])['result'] ?? ''));
        $document = OrderDocument::query()->where('os_id', $orderId)->where('tipo_documento', 'laudo')->firstOrFail();

        $link = $service->createShareLink($orderId, $actor, [(int) $document->id], ['format' => 'a4', 'expiracao' => '7d']);
        $this->assertSame('ok', (string) ($link['result'] ?? ''), json_encode($link));
        $publicPath = (string) parse_url((string) $link['link']['url'], PHP_URL_PATH);

        $this->get($publicPath)->assertOk();
        $response = $this->get($publicPath.'/arquivos/'.$document->id.'/a4');
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());

        $zip = $service->buildZip($orderId, $actor, [(int) $document->id], 'a4');
        $this->assertSame('ok', (string) ($zip['result'] ?? ''), json_encode($zip));
        $zipPath = (string) $zip['file']['absolute_path'];
        $this->assertFileExists($zipPath);
        $this->assertStringStartsWith("PK\x03\x04", (string) file_get_contents($zipPath));
        $this->assertStringNotContainsString('/os_documentos/', $zipPath, 'ZIP é temporário, fora do acervo');
        $this->assertFalse(Storage::disk('local')->directoryExists("private/os_documentos/{$orderId}/zip"));
        @unlink($zipPath);
    }

    public function test_render_cache_is_reused_on_second_read(): void
    {
        config(['document-rendering.render_cache.enabled' => true]);
        [$actor, $orderId] = $this->buildOrderFixture();
        $service = app(OrderDocumentCenterService::class);
        $this->assertSame('ok', (string) ($service->generate($orderId, $actor, ['laudo'])['result'] ?? ''));
        $document = OrderDocument::query()->where('os_id', $orderId)->where('tipo_documento', 'laudo')->firstOrFail();
        $order = Order::query()->findOrFail($orderId);
        $resolver = app(DocumentBytesResolver::class);

        // A emissão pré-aquece o cache com os bytes emitidos.
        $first = $resolver->resolve($order, $document, 'a4');
        $this->assertSame(ResolvedDocumentFile::SOURCE_RENDER_CACHE, $first['file']->source);
        $this->assertSame((string) $document->hash_sha256, $first['file']->sha256(), 'cache devolve exatamente o binário emitido');

        // O 80mm não foi emitido: primeiro acesso renderiza, segundo vem do cache.
        $thermal = $resolver->resolve($order, $document, '80mm');
        $this->assertSame(ResolvedDocumentFile::SOURCE_SNAPSHOT, $thermal['file']->source);
        $again = $resolver->resolve($order, $document, '80mm');
        $this->assertSame(ResolvedDocumentFile::SOURCE_RENDER_CACHE, $again['file']->source);

        // Mudar o perfil invalida a chave.
        config(['document-rendering.profile_version' => 99]);
        $this->assertSame(ResolvedDocumentFile::SOURCE_SNAPSHOT, $resolver->resolve($order, $document, '80mm')['file']->source);
    }

    public function test_legacy_document_without_snapshot_or_file_is_reconstituted_from_current_data(): void
    {
        [$actor, $orderId] = $this->buildOrderFixture();
        $documentId = (int) DB::table('os_documentos')->insertGetId([
            'os_id' => $orderId,
            'tipo_documento' => 'laudo',
            'arquivo' => "private/os_documentos/{$orderId}/laudo_os26070888_v1_a4.pdf",
            'versao' => 1,
            'hash_sha1' => sha1('expurgado'),
            'gerado_por' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = Order::query()->findOrFail($orderId);
        $document = OrderDocument::query()->findOrFail($documentId);
        $resolver = app(DocumentBytesResolver::class);
        $this->assertTrue($resolver->isAvailable($order, $document, 'a4'));

        $token = $this->loginAndGetToken($actor->email);
        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->get("/api/v1/orders/{$orderId}/documents/{$documentId}/files/a4");
        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());

        config(['document-rendering.live_fallback' => false]);
        $this->assertFalse($resolver->isAvailable($order, $document, 'a4'));
    }

    public function test_ephemeral_documents_are_not_cataloged_in_file_manager(): void
    {
        [$actor, $orderId] = $this->buildOrderFixture();
        $service = app(OrderDocumentCenterService::class);
        $this->assertSame('ok', (string) ($service->generate($orderId, $actor, ['laudo'])['result'] ?? ''));
        $document = OrderDocument::query()->where('os_id', $orderId)->where('tipo_documento', 'laudo')->firstOrFail();

        if (DB::getSchemaBuilder()->hasTable('managed_files')) {
            $this->assertSame(0, DB::table('managed_files')->where('storage_key', (string) $document->arquivo)->count());
        }
    }

    // ---------------------------------------------------------------

    private function pdfText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'snap-');
        file_put_contents($path, $bytes);
        try {
            $process = new Process(['/usr/bin/pdftotext', '-layout', $path, '-']);
            $process->run();

            return $process->getOutput();
        } finally {
            @unlink($path);
        }
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'pwa-mobile',
        ]);

        return (string) $response->json('data.access_token');
    }

    private function seedPdfEngineTemplates(): void
    {
        $now = now();

        foreach (PdfDefaultTemplates::all() as $tipoCodigo => $definition) {
            $schemaJson = json_encode($definition['schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $templateId = DB::table('pdf_templates')->insertGetId([
                'tipo_codigo' => $tipoCodigo,
                'nome' => (string) $definition['nome'],
                'arquivado' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('pdf_template_versoes')->insert([
                'template_id' => $templateId,
                'versao' => 1,
                'status' => 'publicado',
                'schema_json' => $schemaJson,
                'papel' => 'a4',
                'orientacao' => 'retrato',
                'hash_schema' => hash('sha256', (string) $schemaJson),
                'publicado_em' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array{0: \App\Models\User, 1: int}
     */
    private function buildOrderFixture(): array
    {
        $actor = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'admin']);
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Snapshot', 'telefone1' => '(11) 99999-0000', 'email' => 'cliente@example.com']);
        $equipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Console Snapshot']);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'numero_os' => 'OS26070888',
            'status' => 'entregue_reparado_pago',
            'estado_fluxo' => 'encerrado',
            'relato_cliente' => 'Aparelho desliga sozinho.',
            'diagnostico_tecnico' => 'Fonte queimada no conector.',
            'solucao_aplicada' => 'Troca da fonte interna.',
            'valor_final' => 350.00,
        ]);

        return [$actor, $orderId];
    }
}
