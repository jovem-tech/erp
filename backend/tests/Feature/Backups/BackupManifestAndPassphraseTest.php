<?php

namespace Tests\Feature\Backups;

use App\Services\Backups\ArchiveCipher;
use App\Services\Backups\BackupManifestBuilder;
use App\Services\Backups\BackupPassphraseResolver;
use App\Services\Backups\ConfigSnapshotService;
use App\Services\Backups\Contracts\ProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

class BackupManifestAndPassphraseTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        config()->set('cache.default', 'array');
        $this->app->instance(ProcessRunner::class, new FakeProcessRunner());
    }

    public function test_manifesto_nao_carrega_nenhum_caminho_absoluto(): void
    {
        config()->set('filesystems.disks.legacy_public.root', '/var/www/sistema-hml-legacy/public');

        $manifest = $this->manifest()
            ->addRoot('legado_uploads', [
                'rotulo' => 'Uploads do sistema legado',
                'membro' => 'arquivos/legado_uploads.tar.gz.enc',
                'arquivos' => 404,
                'bytes' => 59_000_000,
            ])
            ->toJson();

        // Propriedade de seguranca central: o manifesto guarda IDs LOGICOS.
        // Se guardasse caminhos, restaurar um pacote de producao na bancada
        // escreveria no caminho de producao.
        $this->assertStringNotContainsString('/var/www', $manifest);
        $this->assertStringNotContainsString('sistema-hml-legacy', $manifest);
        $this->assertStringContainsString('legado_uploads', $manifest);
    }

    public function test_manifesto_registra_o_sha256_de_cada_membro(): void
    {
        $manifest = $this->manifest()
            ->addMember('db/sistema_hml.sql.gz.enc', 100, str_repeat('a', 64))
            ->toArray();

        // E o sha256 por membro que fecha a maleabilidade do AES-CBC: nada e
        // restaurado sem conferir estes hashes antes.
        $this->assertSame(str_repeat('a', 64), $manifest['membros']['db/sistema_hml.sql.gz.enc']['sha256']);
    }

    public function test_manifesto_declara_que_certificados_ficam_de_fora(): void
    {
        $manifest = $this->manifest()->toArray();

        $this->assertFalse($manifest['tls']['incluido']);
        $this->assertTrue($manifest['tls']['renovavel']);
    }

    public function test_frase_secreta_curta_e_recusada(): void
    {
        $this->expectException(RuntimeException::class);

        app(BackupPassphraseResolver::class)->define('curta');
    }

    public function test_frase_correta_confere_e_frase_errada_nao(): void
    {
        $resolver = app(BackupPassphraseResolver::class);
        $resolver->define('uma-frase-secreta-boa-2026');

        $this->assertTrue($resolver->isConfigured());
        $this->assertTrue($resolver->verify('uma-frase-secreta-boa-2026'));
        $this->assertFalse($resolver->verify('outra-frase-qualquer'));
    }

    public function test_impressao_da_frase_e_estavel_e_nao_reversivel(): void
    {
        $resolver = app(BackupPassphraseResolver::class);
        $resolver->define('uma-frase-secreta-boa-2026');

        $fingerprint = $resolver->storedFingerprint();

        $this->assertSame(16, strlen($fingerprint));
        $this->assertSame($fingerprint, $resolver->fingerprint('uma-frase-secreta-boa-2026'));
        $this->assertNotSame($fingerprint, $resolver->fingerprint('outra-frase-qualquer'));
        $this->assertStringNotContainsString('uma-frase', $fingerprint);
    }

    public function test_modo_manual_desabilita_o_backup_agendado(): void
    {
        $resolver = app(BackupPassphraseResolver::class);
        app(\App\Services\Backups\BackupSettingsService::class)->put('backup_passphrase_modo', 'manual');
        $resolver->define('uma-frase-secreta-boa-2026');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/modo manual/');

        // Em modo manual a frase nao fica na maquina, entao nao ha como uma
        // execucao nao assistida decifrar coisa alguma - e isso precisa ser
        // dito com todas as letras, nao falhar de forma obscura mais adiante.
        $resolver->resolveForUnattended();
    }

    private function manifest(): BackupManifestBuilder
    {
        return new BackupManifestBuilder(app(ArchiveCipher::class), app(ConfigSnapshotService::class));
    }
}
