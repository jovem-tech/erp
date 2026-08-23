<?php

namespace Tests\Feature\Backups;

use App\Enums\Backups\BackupStatus;
use App\Models\Backups\Backup;
use App\Services\Backups\BackupPassphraseResolver;
use App\Services\Backups\Contracts\ProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

class BackupApiTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedBackupRbac();
        config()->set('cache.default', 'array');
        $this->app->instance(ProcessRunner::class, new FakeProcessRunner());
    }

    public function test_listagem_exige_permissao_de_visualizar(): void
    {
        $actor = $this->actorWith([]);
        Sanctum::actingAs($actor, ['*']);

        $this->getJson('/api/v1/backups')->assertForbidden();
    }

    public function test_gerar_backup_exige_permissao_de_criar(): void
    {
        $actor = $this->actorWith(['backups' => ['visualizar']]);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson('/api/v1/backups')->assertForbidden();
    }

    public function test_baixar_exige_permissao_propria_separada_de_visualizar(): void
    {
        // "baixar" e separado de propósito: o pacote carrega todos os segredos
        // e todos os arquivos de clientes. Ver a lista e levar o arquivo
        // embora sao poderes diferentes.
        $actor = $this->actorWith(['backups' => ['visualizar']]);
        $backup = $this->makeBackup();
        Sanctum::actingAs($actor, ['*']);

        $this->postJson('/api/v1/backups/'.$backup->uuid.'/link-download')->assertForbidden();
    }

    public function test_listagem_nunca_expoe_o_caminho_absoluto_do_arquivo(): void
    {
        $actor = $this->actorWith(['backups' => ['visualizar']]);
        $backup = $this->makeBackup();
        Sanctum::actingAs($actor, ['*']);

        $response = $this->getJson('/api/v1/backups')->assertOk();

        $response->assertJsonPath('data.0.arquivo_nome', (string) $backup->arquivo_nome);
        $this->assertStringNotContainsString(
            (string) $backup->arquivo_caminho,
            $response->getContent() ?: '',
            'Caminhos absolutos do servidor não podem chegar ao navegador.'
        );
        $response->assertJsonMissingPath('data.0.arquivo_caminho');
    }

    public function test_gerar_backup_apenas_enfileira_e_nao_executa_na_requisicao(): void
    {
        $actor = $this->actorWith(['backups' => ['criar', 'visualizar']]);
        app(BackupPassphraseResolver::class)->define('uma-frase-secreta-boa-2026');
        config()->set('backup.store.path', $this->makeStore());
        Sanctum::actingAs($actor, ['*']);

        // O pool PHP-FPM corta em 60s: um backup de ~130 MB jamais pode rodar
        // dentro da requisicao. A API grava a linha e o scheduler executa.
        $this->postJson('/api/v1/backups')
            ->assertStatus(202)
            ->assertJsonPath('data.status', BackupStatus::Pendente->value);

        $this->assertSame(1, Backup::query()->where('status', BackupStatus::Pendente->value)->count());
    }

    public function test_gerar_backup_e_recusado_sem_frase_secreta(): void
    {
        $actor = $this->actorWith(['backups' => ['criar']]);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson('/api/v1/backups')->assertStatus(422);
        $this->assertSame(0, Backup::query()->count());
    }

    public function test_nao_permite_excluir_backup_que_o_painel_nao_gerencia(): void
    {
        $actor = $this->actorWith(['backups' => ['excluir']]);
        $backup = $this->makeBackup(['gerenciado' => false]);
        Sanctum::actingAs($actor, ['*']);

        $this->deleteJson('/api/v1/backups/'.$backup->uuid)->assertStatus(422);

        $this->assertTrue(is_file((string) $backup->arquivo_caminho));
        $this->assertSame(BackupStatus::Concluido, $backup->refresh()->status);
    }

    public function test_nao_permite_excluir_backup_protegido(): void
    {
        $actor = $this->actorWith(['backups' => ['excluir']]);
        $backup = $this->makeBackup(['protegido' => true]);
        Sanctum::actingAs($actor, ['*']);

        $this->deleteJson('/api/v1/backups/'.$backup->uuid)->assertStatus(422);
        $this->assertTrue(is_file((string) $backup->arquivo_caminho));
    }

    public function test_link_de_download_e_assinado_e_temporario(): void
    {
        $actor = $this->actorWith(['backups' => ['baixar']]);
        $backup = $this->makeBackup();
        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/v1/backups/'.$backup->uuid.'/link-download')->assertOk();

        $url = (string) $response->json('data.url');
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
    }

    public function test_download_sem_assinatura_valida_e_recusado(): void
    {
        $backup = $this->makeBackup();

        // Rota sem sessao: a assinatura temporaria E a autorizacao.
        $this->get('/backups/'.$backup->uuid.'/arquivo')->assertForbidden();
    }

    public function test_resumo_alerta_quando_nao_existe_nenhum_backup_completo(): void
    {
        $actor = $this->actorWith(['backups' => ['visualizar']]);
        $this->makeBackup(['conteudo' => 'somente_banco']);
        Sanctum::actingAs($actor, ['*']);

        // Os dumps do cron cobrem so o banco: o painel precisa dizer que
        // nenhuma imagem ou documento esta protegido.
        $this->getJson('/api/v1/backups/resumo')
            ->assertOk()
            ->assertJsonPath('data.alerta_sem_backup_completo', true);
    }

    /**
     * O seed compartilhado (BuildsLegacyErpSchema) nao conhece o modulo novo:
     * cada modulo semeia o proprio catalogo no seu teste, como o gerenciador
     * de arquivos ja faz em FileManagerApiTest::seedFileManagerRbac().
     */
    private function seedBackupRbac(): void
    {
        DB::table('modulos')->insert([
            'id' => 21,
            'nome' => 'Backups',
            'slug' => 'backups',
            'icone' => 'bi-hdd-stack',
            'ordem_menu' => 79,
            'ativo' => 1,
        ]);

        foreach (['baixar', 'restaurar', 'administrar'] as $index => $slug) {
            DB::table('permissoes')->insert([
                'id' => 30 + $index,
                'nome' => ucfirst($slug),
                'slug' => $slug,
            ]);
        }

        Cache::flush();
    }

    /** @param array<string, array<int, string>> $permissions */
    private function actorWith(array $permissions)
    {
        if ($permissions !== []) {
            $this->grantGroupPermissions(1, $permissions);
        }

        return $this->createUserRecord(['grupo_id' => 1]);
    }

    private function makeStore(): string
    {
        $path = sys_get_temp_dir().'/erp-backup-api-'.bin2hex(random_bytes(4));
        mkdir($path, 0700, true);

        return $path;
    }

    /** @param array<string, mixed> $attributes */
    private function makeBackup(array $attributes = []): Backup
    {
        $path = tempnam(sys_get_temp_dir(), 'erp-backup-api-');
        file_put_contents($path, 'conteudo');

        return Backup::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'tipo' => 'completo',
            'origem' => 'agendado',
            'conteudo' => 'completo',
            'gerenciado' => true,
            'protegido' => false,
            'status' => BackupStatus::Concluido->value,
            'arquivo_nome' => basename($path),
            'arquivo_caminho' => $path,
            'tamanho_bytes' => 8,
        ], $attributes));
    }
}
