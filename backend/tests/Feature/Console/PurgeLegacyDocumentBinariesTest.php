<?php

namespace Tests\Feature\Console;

use App\Models\OrderDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * documents:purge-legacy-binaries só apaga o que pode ser re-renderizado e
 * nunca toca fiscal, assinatura formal, legal_hold ou tipo sem registry.
 */
class PurgeLegacyDocumentBinariesTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private int $orderId;

    private int $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();
        Storage::fake('local');

        $actor = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'admin']);
        $this->actorId = (int) $actor->id;
        $clientId = $this->createClientRecord();
        $equipmentId = $this->createEquipmentRecord($clientId);
        $this->orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'numero_os' => 'OS26090001',
            'status' => 'entregue_reparado_pago',
            'diagnostico_tecnico' => 'Fonte queimada.',
        ]);
    }

    public function test_dry_run_reports_without_deleting_and_execute_respects_every_protection(): void
    {
        $ephemeral = $this->seedDocument('laudo', "private/os_documentos/{$this->orderId}/laudo_os26090001_v1_a4.pdf");
        $formal = $this->seedDocument('laudo', "private/os_documentos/{$this->orderId}/laudo_os26090001_v2_a4.pdf", [
            'metodo_assinatura' => 'pendencia_sessao',
            'assinatura_hash' => str_repeat('a', 64),
            'assinado_por' => $this->actorId,
        ]);
        $customer = $this->seedDocument('entrega', "private/os_documentos/{$this->orderId}/entrega_os26090001_v1_a4.pdf", [
            'metodo_assinatura' => 'cliente_link',
            'assinatura_hash' => str_repeat('b', 64),
        ]);
        $fiscal = $this->seedDocument('nota_fiscal', "private/os_documentos/{$this->orderId}/fiscal/nfse_123.pdf");
        $unknown = $this->seedDocument('tipo_inexistente', "private/os_documentos/{$this->orderId}/tipo_inexistente_v1_a4.pdf");
        $rubric = $this->seedDocument('abertura', "private/os_documentos/{$this->orderId}/abertura_os26090001_v1_a4.pdf", [
            'metodo_assinatura' => 'sessao',
            'assinatura_hash' => str_repeat('c', 64),
            'assinado_por' => $this->actorId,
        ]);

        // Dry-run (padrão): nada some.
        $this->artisan('documents:purge-legacy-binaries')
            ->expectsOutputToContain('Dry-run')
            ->assertSuccessful();
        foreach ([$ephemeral, $formal, $customer, $fiscal, $unknown, $rubric] as $document) {
            $this->assertTrue(Storage::disk('local')->exists($document->arquivo), $document->tipo_documento.' não pode sumir em dry-run');
        }

        $this->assertSame(0, Artisan::call('documents:purge-legacy-binaries', ['--execute' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('mantidos por assinatura_formal: 2', $output, $output);
        $this->assertStringContainsString('mantidos por fiscal_guarda_legal: 1', $output, $output);
        $this->assertStringContainsString('mantidos por tipo_fora_do_registry: 1', $output, $output);

        $this->assertTrue(Storage::disk('local')->missing($ephemeral->arquivo), 'laudo sem assinatura formal é expurgado');
        $this->assertTrue(Storage::disk('local')->missing($rubric->arquivo), 'rubrica automática (sessao) é expurgada');
        $this->assertTrue(Storage::disk('local')->exists($formal->arquivo), 'assinatura formal fica');
        $this->assertTrue(Storage::disk('local')->exists($customer->arquivo), 'assinatura do cliente fica');
        $this->assertTrue(Storage::disk('local')->exists($fiscal->arquivo), 'fiscal nunca é tocado');
        $this->assertTrue(Storage::disk('local')->exists($unknown->arquivo), 'tipo sem re-render fica');

        $metadata = OrderDocument::query()->findOrFail($ephemeral->id)->metadados_json;
        $this->assertSame('dados_atuais', (string) ($metadata['origem_reconstituicao'] ?? ''));
        $this->assertNotSame('', (string) ($metadata['binario_expurgado_em'] ?? ''));
        // O registro do que foi emitido permanece.
        $this->assertDatabaseHas('os_documento_arquivos', ['documento_id' => $ephemeral->id, 'formato' => 'a4', 'tamanho_bytes' => 27]);
    }

    public function test_before_and_order_filters_and_zip_cleanup(): void
    {
        $old = $this->seedDocument('laudo', "private/os_documentos/{$this->orderId}/laudo_os26090001_v1_a4.pdf", ['created_at' => now()->subDays(40)]);
        $recent = $this->seedDocument('laudo', "private/os_documentos/{$this->orderId}/laudo_os26090001_v2_a4.pdf", ['created_at' => now()->subDay()]);
        Storage::disk('local')->put("private/os_documentos/{$this->orderId}/zip/documentos-cliente-x.zip", 'PK');
        touch(Storage::disk('local')->path("private/os_documentos/{$this->orderId}/zip/documentos-cliente-x.zip"), time() - 3 * 86400);
        Storage::disk('local')->put('private/orcamentos/7/orcamento_orc7_v1.pdf', '%PDF-1.4 orc');
        touch(Storage::disk('local')->path('private/orcamentos/7/orcamento_orc7_v1.pdf'), time() - 40 * 86400);
        Storage::disk('local')->put('private/orcamentos/8/orcamento_orc8_v1.pdf', '%PDF-1.4 orc recente');
        Storage::disk('local')->put("private/os_documentos/{$this->orderId}/foto_entrada.jpg", 'JPEG');

        $this->assertSame(0, Artisan::call('documents:purge-legacy-binaries', [
            '--execute' => true,
            '--before' => now()->subDays(7)->format('Y-m-d'),
            '--order' => $this->orderId,
            '--zips' => true,
            '--budgets' => true,
        ]));
        $output = Artisan::output();

        $this->assertTrue(Storage::disk('local')->missing($old->arquivo));
        $this->assertTrue(Storage::disk('local')->exists($recent->arquivo), '--before protege os recentes');
        $this->assertTrue(Storage::disk('local')->missing("private/os_documentos/{$this->orderId}/zip/documentos-cliente-x.zip"));
        $this->assertTrue(Storage::disk('local')->missing('private/orcamentos/7/orcamento_orc7_v1.pdf'), $output);
        $this->assertTrue(Storage::disk('local')->exists('private/orcamentos/8/orcamento_orc8_v1.pdf'), '--before vale para orçamentos (mtime)');
        $this->assertTrue(Storage::disk('local')->exists("private/os_documentos/{$this->orderId}/foto_entrada.jpg"), 'nunca varre diretório: fotos ficam');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedDocument(string $type, string $path, array $overrides = []): OrderDocument
    {
        Storage::disk('local')->put($path, '%PDF-1.4 documento antigo');

        $document = OrderDocument::query()->create(array_merge([
            'os_id' => $this->orderId,
            'tipo_documento' => $type,
            'arquivo' => $path,
            'versao' => (int) (DB::table('os_documentos')->where('os_id', $this->orderId)->where('tipo_documento', $type)->max('versao') ?? 0) + 1,
            'hash_sha1' => sha1('documento antigo'),
            'gerado_por' => $this->actorId,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ], $overrides));

        DB::table('os_documento_arquivos')->insert([
            'documento_id' => $document->id,
            'formato' => 'a4',
            'arquivo' => $path,
            'mime' => 'application/pdf',
            'tamanho_bytes' => 27,
            'hash_sha256' => hash('sha256', '%PDF-1.4 documento antigo'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $document;
    }
}
