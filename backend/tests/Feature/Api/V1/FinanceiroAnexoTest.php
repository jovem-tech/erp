<?php

namespace Tests\Feature\Api\V1;

use App\DTO\Files\FileContext;
use App\Enums\Files\FileCategory;
use App\Enums\Files\FileLifecycleStatus;
use App\Enums\Files\FileOrigin;
use App\Enums\Files\ManagedFileAction;
use App\Models\Financeiro;
use App\Models\FinanceiroAnexo;
use App\Models\Files\ManagedFile;
use App\Models\Files\ManagedFileEvent;
use App\Models\Files\ManagedFileLegacyAlias;
use App\Services\Financeiro\FinanceiroService;
use App\Services\Files\FinanceiroAnexoReconciliationService;
use App\Services\Files\LegacyCompatibleFileAdapter;
use App\Services\Files\ManagedFileDomainLifecycleService;
use App\Services\Files\ManagedFilePurgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class FinanceiroAnexoTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, [
            'financeiro' => ['visualizar', 'criar', 'editar', 'excluir'],
        ]);
    }

    private function criarLancamento(array $overrides = []): Financeiro
    {
        return Financeiro::query()->create(array_merge([
            'tipo' => Financeiro::TIPO_PAGAR,
            'categoria' => 'Energia',
            'descricao' => 'Energia elétrica',
            'valor' => 680.00,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->addDays(5)->toDateString(),
        ], $overrides));
    }

    public function test_anexa_boleto_pdf_ao_lancamento(): void
    {
        Storage::fake('local');

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $lancamento = $this->criarLancamento();

        $response = $this->post('/api/v1/financeiro/' . $lancamento->id . '/anexos', [
            'arquivo' => UploadedFile::fake()->createWithContent('boleto-setembro.pdf', '%PDF-1.4 boleto setembro'),
            'descricao' => 'Boleto setembro/2026',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.anexo.nome_original', 'boleto-setembro.pdf')
            ->assertJsonPath('data.anexo.descricao', 'Boleto setembro/2026');

        $this->assertDatabaseHas('financeiro_anexos', [
            'financeiro_id' => $lancamento->id,
            'nome_original' => 'boleto-setembro.pdf',
            'usuario_id' => $admin->id,
        ]);

        $anexo = FinanceiroAnexo::query()->where('financeiro_id', $lancamento->id)->firstOrFail();
        Storage::disk('local')->assertExists($anexo->arquivo);
    }

    public function test_anexo_e_catalogado_no_gerenciador_de_arquivos(): void
    {
        Storage::fake('local');
        config()->set('file-manager.mode', 'shadow');
        config()->set('file-manager.enabled_categories', ['financeiro_anexo']);

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $lancamento = $this->criarLancamento();

        // createWithContent(), não create(): create() só simula o tamanho
        // declarado (arquivo real fica com 0 bytes), e a policy do File
        // Manager confere o tamanho REAL gravado em disco — sem conteúdo de
        // verdade, a catalogação é recusada silenciosamente (modo shadow
        // engole a exceção) e o teste falharia sem dizer por quê.
        $this->post('/api/v1/financeiro/' . $lancamento->id . '/anexos', [
            'arquivo' => UploadedFile::fake()->createWithContent('boleto-setembro.pdf', '%PDF-1.4 conteudo de teste'),
        ])->assertCreated()
            ->assertJsonPath('data.anexo.management_status', 'active');

        $anexo = FinanceiroAnexo::query()->where('financeiro_id', $lancamento->id)->firstOrFail();

        $managedFiles = \App\Models\Files\ManagedFile::query()
            ->where('category', \App\Enums\Files\FileCategory::FinanceiroAnexo->value)
            ->get();

        $this->assertCount(1, $managedFiles);
        $this->assertSame((string) $managedFiles->first()->uuid, (string) $anexo->managed_file_uuid);
        $this->assertSame(
            1,
            \App\Models\Files\ManagedFileLink::query()
                ->where('subject_type', 'financeiro')
                ->where('subject_id', $lancamento->id)
                ->count()
        );
        $this->assertSame(
            1,
            \App\Models\Files\ManagedFileLegacyAlias::query()
                ->where('file_id', $managedFiles->first()->id)
                ->where('source_table', 'financeiro_anexos')
                ->where('source_record_id', (string) $anexo->id)
                ->count()
        );
    }

    public function test_anexa_foto_da_conta_como_imagem(): void
    {
        Storage::fake('local');

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $lancamento = $this->criarLancamento();

        $this->post('/api/v1/financeiro/' . $lancamento->id . '/anexos', [
            'arquivo' => UploadedFile::fake()->image('conta-luz.jpg', 120, 120),
        ])->assertCreated();

        $this->assertDatabaseCount('financeiro_anexos', 1);
    }

    public function test_recusa_arquivo_de_tipo_nao_permitido(): void
    {
        Storage::fake('local');

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $lancamento = $this->criarLancamento();

        $this->post('/api/v1/financeiro/' . $lancamento->id . '/anexos', [
            'arquivo' => UploadedFile::fake()->create('planilha.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ])->assertStatus(422);

        $this->assertDatabaseCount('financeiro_anexos', 0);
    }

    public function test_recusa_arquivo_acima_do_limite(): void
    {
        Storage::fake('local');

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $lancamento = $this->criarLancamento();

        $this->post('/api/v1/financeiro/' . $lancamento->id . '/anexos', [
            'arquivo' => UploadedFile::fake()->create('boleto-grande.pdf', 20481, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_usuario_sem_permissao_editar_nao_pode_anexar(): void
    {
        Storage::fake('local');

        $attendant = $this->createUserRecord(['grupo_id' => 3, 'perfil' => 'atendente']);
        Sanctum::actingAs($attendant, ['*']);

        $lancamento = $this->criarLancamento();

        $this->post('/api/v1/financeiro/' . $lancamento->id . '/anexos', [
            'arquivo' => UploadedFile::fake()->createWithContent('boleto.pdf', '%PDF-1.4 boleto'),
        ])->assertStatus(403);

        $this->assertDatabaseCount('financeiro_anexos', 0);
    }

    public function test_index_e_show_do_lancamento_trazem_contagem_e_lista_de_anexos(): void
    {
        Storage::fake('local');

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $lancamento = $this->criarLancamento();

        $this->post('/api/v1/financeiro/' . $lancamento->id . '/anexos', [
            'arquivo' => UploadedFile::fake()->createWithContent('boleto.pdf', '%PDF-1.4 boleto'),
        ])->assertCreated();

        $this->getJson('/api/v1/financeiro')
            ->assertOk()
            ->assertJsonPath('data.lancamentos.0.anexos_count', 1);

        $this->getJson('/api/v1/financeiro/' . $lancamento->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.lancamento.anexos')
            ->assertJsonPath('data.lancamento.anexos.0.nome_original', 'boleto.pdf')
            ->assertJsonPath('data.lancamento.anexos.0.uploaded_by.nome', $admin->nome);
    }

    public function test_download_serve_o_arquivo_com_content_disposition_inline(): void
    {
        Storage::fake('local');

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $lancamento = $this->criarLancamento();

        $this->post('/api/v1/financeiro/' . $lancamento->id . '/anexos', [
            'arquivo' => UploadedFile::fake()->createWithContent('boleto.pdf', '%PDF-1.4 boleto'),
        ])->assertCreated();

        $anexo = FinanceiroAnexo::query()->where('financeiro_id', $lancamento->id)->firstOrFail();

        $response = $this->get('/api/v1/financeiro/' . $lancamento->id . '/anexos/' . $anexo->id . '/download');

        $response->assertOk();
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_exclui_anexo_removendo_registro_e_arquivo_do_disco(): void
    {
        Storage::fake('local');

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $lancamento = $this->criarLancamento();

        $this->post('/api/v1/financeiro/' . $lancamento->id . '/anexos', [
            'arquivo' => UploadedFile::fake()->createWithContent('boleto.pdf', '%PDF-1.4 boleto'),
        ])->assertCreated();

        $anexo = FinanceiroAnexo::query()->where('financeiro_id', $lancamento->id)->firstOrFail();
        $caminho = $anexo->arquivo;

        $this->deleteJson('/api/v1/financeiro/' . $lancamento->id . '/anexos/' . $anexo->id)
            ->assertOk();

        $this->assertDatabaseMissing('financeiro_anexos', ['id' => $anexo->id]);
        Storage::disk('local')->assertMissing($caminho);
    }

    public function test_excluir_lancamento_apaga_anexos_em_cascata(): void
    {
        Storage::fake('local');

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $lancamento = $this->criarLancamento();

        $this->post('/api/v1/financeiro/' . $lancamento->id . '/anexos', [
            'arquivo' => UploadedFile::fake()->createWithContent('boleto.pdf', '%PDF-1.4 boleto'),
        ])->assertCreated();

        $anexo = FinanceiroAnexo::query()->where('financeiro_id', $lancamento->id)->firstOrFail();
        $caminho = $anexo->arquivo;

        $superAdmin = $this->createUserRecord(['perfil' => 'admin', 'grupo_id' => 1]);

        $this->deleteJson('/api/v1/financeiro/' . $lancamento->id, [
            'admin_email' => $superAdmin->email,
            'admin_password' => 'Senha@123',
        ])->assertOk();

        $this->assertDatabaseMissing('financeiro_anexos', ['id' => $anexo->id]);
        Storage::disk('local')->assertMissing($caminho);
    }

    public function test_upload_pendente_e_recuperado_pelo_reconciliador_sem_duplicar(): void
    {
        Storage::fake('local');
        config()->set('file-manager.mode', 'off');

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);
        $lancamento = $this->criarLancamento();

        $this->post('/api/v1/financeiro/' . $lancamento->id . '/anexos', [
            'arquivo' => UploadedFile::fake()->createWithContent('pendente.pdf', '%PDF-1.4 pendente'),
        ])->assertCreated()
            ->assertJsonPath('data.anexo.management_status', 'pending');

        $anexo = FinanceiroAnexo::query()->firstOrFail();
        $this->assertNull($anexo->managed_file_uuid);

        $this->enableAuthoritativeFileManager();
        $first = app(FinanceiroAnexoReconciliationService::class)->reconcile(true);
        $second = app(FinanceiroAnexoReconciliationService::class)->reconcile(true);

        $this->assertSame(1, $first['repaired']);
        $this->assertSame(1, $second['consistent']);
        $this->assertNotNull($anexo->fresh()->managed_file_uuid);
        $this->assertDatabaseCount('managed_files', 1);
        $this->assertDatabaseCount('managed_file_links', 1);
        $this->assertDatabaseCount('managed_file_legacy_aliases', 1);
    }

    public function test_exclusao_autoritativa_move_para_lixeira_e_permite_restauracao_central(): void
    {
        Storage::fake('local');
        $this->enableAuthoritativeFileManager();

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);
        $lancamento = $this->criarLancamento();

        $this->post('/api/v1/financeiro/' . $lancamento->id . '/anexos', [
            'arquivo' => UploadedFile::fake()->createWithContent('boleto.pdf', '%PDF-1.4 restauravel'),
        ])->assertCreated();

        $anexo = FinanceiroAnexo::query()->firstOrFail();
        $managed = $anexo->managedFile()->firstOrFail();
        $path = $anexo->arquivo;

        $this->deleteJson('/api/v1/financeiro/' . $lancamento->id . '/anexos/' . $anexo->id)
            ->assertOk()
            ->assertJsonPath('data.message', 'Anexo movido para a lixeira.');

        $this->assertSoftDeleted('financeiro_anexos', ['id' => $anexo->id]);
        Storage::disk('local')->assertExists($path);
        $this->assertSame(FileLifecycleStatus::Trashed, $managed->fresh()->lifecycle_status);
        $trashEvent = ManagedFileEvent::query()
            ->where('file_id', $managed->id)
            ->where('action', ManagedFileAction::Trashed->value)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('financeiro', $trashEvent->context_json['origin'] ?? null);

        app(ManagedFileDomainLifecycleService::class)->restore(
            $managed->fresh(),
            (int) $admin->id,
            'Restauração validada em teste.',
            (int) $admin->id
        );

        $this->assertDatabaseHas('financeiro_anexos', ['id' => $anexo->id, 'deleted_at' => null]);
        $this->assertSame(FileLifecycleStatus::Active, $managed->fresh()->lifecycle_status);
    }

    public function test_purge_remove_binario_e_metadado_financeiro_mas_preserva_auditoria_gerenciada(): void
    {
        Storage::fake('local');
        $this->enableAuthoritativeFileManager();
        config()->set('file-manager.kill_switches.allow_permanent_deletion', true);

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);
        $lancamento = $this->criarLancamento();
        $this->post('/api/v1/financeiro/' . $lancamento->id . '/anexos', [
            'arquivo' => UploadedFile::fake()->createWithContent('purgar.pdf', '%PDF-1.4 purge'),
        ])->assertCreated();

        $anexo = FinanceiroAnexo::query()->firstOrFail();
        $managed = $anexo->managedFile()->firstOrFail();
        $path = $anexo->arquivo;
        $this->deleteJson('/api/v1/financeiro/' . $lancamento->id . '/anexos/' . $anexo->id)->assertOk();

        app(ManagedFilePurgeService::class)->purge(
            $managed->fresh(),
            (int) $admin->id,
            'Purga definitiva validada em teste.',
            (int) $admin->id,
            'test'
        );

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('financeiro_anexos', ['id' => $anexo->id]);
        $this->assertSame(FileLifecycleStatus::Purged, $managed->fresh()->lifecycle_status);
        $this->assertDatabaseHas('managed_file_events', [
            'file_id' => $managed->id,
            'action' => ManagedFileAction::Purged->value,
        ]);
    }

    public function test_comando_cataloga_anexo_real_e_envia_alias_orfao_para_lixeira(): void
    {
        Storage::fake('local');
        config()->set('file-manager.mode', 'off');

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);
        $lancamento = $this->criarLancamento();
        $this->post('/api/v1/financeiro/' . $lancamento->id . '/anexos', [
            'arquivo' => UploadedFile::fake()->createWithContent('real.pdf', '%PDF-1.4 real'),
        ])->assertCreated();

        $this->enableAuthoritativeFileManager();
        $orphanPath = 'private/financeiro/'.$lancamento->id.'/debug-test.pdf';
        Storage::disk('local')->put($orphanPath, '%PDF-1.4 orfao');
        $orphan = app(LegacyCompatibleFileAdapter::class)->synchronizeExisting(
            new FileContext(
                category: FileCategory::FinanceiroAnexo,
                origin: FileOrigin::Upload,
                operationKey: 'financeiro-anexo-orphan-test',
                subjectType: 'financeiro',
                subjectId: (int) $lancamento->id,
                relation: 'anexo:999999',
                createdBy: (int) $admin->id
            ),
            'local',
            $orphanPath,
            'financeiro_anexos',
            'arquivo',
            '999999'
        );
        $this->assertInstanceOf(ManagedFile::class, $orphan);

        $this->artisan('file-manager:reconcile-financeiro-anexos')->assertExitCode(0);
        $this->assertNull(FinanceiroAnexo::query()->firstOrFail()->managed_file_uuid);

        $this->artisan('file-manager:reconcile-financeiro-anexos', ['--apply' => true])->assertExitCode(0);
        $this->artisan('file-manager:reconcile-financeiro-anexos', ['--apply' => true])->assertExitCode(0);

        $anexo = FinanceiroAnexo::query()->firstOrFail();
        $this->assertNotNull($anexo->managed_file_uuid);
        $this->assertSame(FileLifecycleStatus::Trashed, $orphan->fresh()->lifecycle_status);
        $this->assertNotNull(
            ManagedFileLegacyAlias::query()
                ->where('file_id', $orphan->id)
                ->firstOrFail()
                ->retired_at
        );
        $this->assertSame(2, ManagedFile::query()->where('category', FileCategory::FinanceiroAnexo->value)->count());
    }

    public function test_download_recusa_caminho_fora_do_namespace_do_lancamento(): void
    {
        Storage::fake('local');
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);
        $lancamento = $this->criarLancamento();
        Storage::disk('local')->put('private/segredo.pdf', '%PDF-1.4 segredo');
        $anexo = FinanceiroAnexo::withoutEvents(fn () => FinanceiroAnexo::query()->create([
            'financeiro_id' => $lancamento->id,
            'nome_original' => 'segredo.pdf',
            'arquivo' => 'private/segredo.pdf',
            'mime' => 'application/pdf',
            'tamanho_bytes' => 20,
            'usuario_id' => $admin->id,
        ]));

        $this->get('/api/v1/financeiro/' . $lancamento->id . '/anexos/' . $anexo->id . '/download')
            ->assertNotFound();
    }

    public function test_download_recusa_mime_falso_mesmo_quando_banco_declara_pdf(): void
    {
        Storage::fake('local');
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);
        $lancamento = $this->criarLancamento();
        $path = 'private/financeiro/'.$lancamento->id.'/disfarce.pdf';
        Storage::disk('local')->put($path, "MZ\x00\x00conteudo executavel");
        $anexo = FinanceiroAnexo::withoutEvents(fn () => FinanceiroAnexo::query()->create([
            'financeiro_id' => $lancamento->id,
            'nome_original' => 'disfarce.pdf',
            'arquivo' => $path,
            'mime' => 'application/pdf',
            'tamanho_bytes' => Storage::disk('local')->size($path),
            'hash_sha256' => hash('sha256', Storage::disk('local')->get($path)),
            'usuario_id' => $admin->id,
        ]));

        $this->get('/api/v1/financeiro/'.$lancamento->id.'/anexos/'.$anexo->id.'/download')
            ->assertNotFound();
    }

    public function test_download_e_exclusao_recusam_anexo_de_outro_lancamento(): void
    {
        Storage::fake('local');
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);
        $origem = $this->criarLancamento();
        $outro = $this->criarLancamento(['descricao' => 'Outro lançamento']);
        $this->post('/api/v1/financeiro/'.$origem->id.'/anexos', [
            'arquivo' => UploadedFile::fake()->createWithContent('restrito.pdf', '%PDF-1.4 restrito'),
        ])->assertCreated();
        $anexo = FinanceiroAnexo::query()->firstOrFail();

        $this->get('/api/v1/financeiro/'.$outro->id.'/anexos/'.$anexo->id.'/download')
            ->assertNotFound();
        $this->deleteJson('/api/v1/financeiro/'.$outro->id.'/anexos/'.$anexo->id)
            ->assertNotFound();

        $this->assertDatabaseHas('financeiro_anexos', [
            'id' => $anexo->id,
            'financeiro_id' => $origem->id,
            'deleted_at' => null,
        ]);
        Storage::disk('local')->assertExists($anexo->arquivo);
    }

    public function test_reconciliador_marca_hash_divergente_como_erro_de_integridade(): void
    {
        Storage::fake('local');
        $this->enableAuthoritativeFileManager();
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);
        $lancamento = $this->criarLancamento();
        $this->post('/api/v1/financeiro/'.$lancamento->id.'/anexos', [
            'arquivo' => UploadedFile::fake()->createWithContent('imutavel.pdf', '%PDF-1.4 original'),
        ])->assertCreated();
        $anexo = FinanceiroAnexo::query()->firstOrFail();

        Storage::disk('local')->put($anexo->arquivo, '%PDF-1.4 alterado fora do sistema');
        $result = app(FinanceiroAnexoReconciliationService::class)->reconcile(true);

        $this->assertSame(1, $result['integrity_errors']);
        $this->assertSame('integrity_error', $anexo->fresh()->management_status);
    }

    public function test_exclusao_autoritativa_do_lancamento_preserva_binario_na_lixeira_e_bloqueia_restauracao(): void
    {
        Storage::fake('local');
        $this->enableAuthoritativeFileManager();

        $admin = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'admin']);
        Sanctum::actingAs($admin, ['*']);
        $lancamento = $this->criarLancamento();
        $this->post('/api/v1/financeiro/' . $lancamento->id . '/anexos', [
            'arquivo' => UploadedFile::fake()->createWithContent('retido.pdf', '%PDF-1.4 retido'),
        ])->assertCreated();

        $anexo = FinanceiroAnexo::query()->firstOrFail();
        $managed = $anexo->managedFile()->firstOrFail();
        $path = $anexo->arquivo;

        $this->deleteJson('/api/v1/financeiro/' . $lancamento->id, [
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ])->assertOk();

        $this->assertDatabaseMissing('financeiro_anexos', ['id' => $anexo->id]);
        Storage::disk('local')->assertExists($path);
        $this->assertSame(FileLifecycleStatus::Trashed, $managed->fresh()->lifecycle_status);

        $this->expectException(\DomainException::class);
        app(ManagedFileDomainLifecycleService::class)->restore(
            $managed->fresh(),
            (int) $admin->id,
            'Tentativa após exclusão do lançamento.',
            (int) $admin->id
        );
    }

    public function test_exclusao_do_lancamento_e_atomica_quando_um_anexo_nao_pode_ser_catalogado(): void
    {
        Storage::fake('local');
        $this->enableAuthoritativeFileManager();
        $admin = $this->createUserRecord(['grupo_id' => 1, 'perfil' => 'admin']);
        Sanctum::actingAs($admin, ['*']);
        $lancamento = $this->criarLancamento();
        $this->post('/api/v1/financeiro/'.$lancamento->id.'/anexos', [
            'arquivo' => UploadedFile::fake()->createWithContent('valido.pdf', '%PDF-1.4 valido'),
        ])->assertCreated();
        $valido = FinanceiroAnexo::query()->firstOrFail();
        $managed = $valido->managedFile()->firstOrFail();
        Storage::disk('local')->put('private/fora-do-financeiro.pdf', '%PDF-1.4 fora');
        FinanceiroAnexo::withoutEvents(fn () => FinanceiroAnexo::query()->create([
            'financeiro_id' => $lancamento->id,
            'nome_original' => 'fora.pdf',
            'arquivo' => 'private/fora-do-financeiro.pdf',
            'mime' => 'application/pdf',
            'tamanho_bytes' => 18,
            'hash_sha256' => hash('sha256', '%PDF-1.4 fora'),
            'usuario_id' => $admin->id,
        ]));

        try {
            app(FinanceiroService::class)->delete($lancamento);
            $this->fail('A exclusão deveria ser bloqueada por anexo fora do namespace.');
        } catch (\InvalidArgumentException) {
            // Resultado esperado: a transação inteira deve ser revertida.
        }

        $this->assertDatabaseHas('financeiro', ['id' => $lancamento->id]);
        $this->assertDatabaseHas('financeiro_anexos', ['id' => $valido->id, 'deleted_at' => null]);
        $this->assertSame(FileLifecycleStatus::Active, $managed->fresh()->lifecycle_status);
        Storage::disk('local')->assertExists($valido->arquivo);
    }

    private function enableAuthoritativeFileManager(): void
    {
        config()->set('file-manager.mode', 'shadow');
        config()->set('file-manager.enabled_categories', [FileCategory::FinanceiroAnexo->value]);
        config()->set('file-manager.authoritative_categories', [FileCategory::FinanceiroAnexo->value]);
        config()->set('file-manager.kill_switches.allow_mutating_reconcile', true);
    }
}
