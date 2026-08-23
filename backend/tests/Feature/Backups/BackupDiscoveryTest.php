<?php

namespace Tests\Feature\Backups;

use App\Enums\Backups\BackupContent;
use App\Enums\Backups\BackupOrigin;
use App\Enums\Backups\BackupStatus;
use App\Models\Backups\Backup;
use App\Services\Backups\BackupDiscoveryService;
use App\Services\Backups\Contracts\ProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

class BackupDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $painel;

    private string $cron;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $base = sys_get_temp_dir().'/erp-backup-varredura-'.bin2hex(random_bytes(4));
        $this->painel = $base.'/painel';
        $this->cron = $base.'/cron';

        mkdir($this->painel, 0700, true);
        mkdir($this->cron, 0700, true);

        config()->set('backup.discovery.enabled', true);
        config()->set('backup.discovery.patterns', ['*.sql.gz', '*.tar']);
        config()->set('backup.discovery.roots', [
            ['path' => $this->painel, 'origin' => 'painel', 'managed' => true],
            ['path' => $this->cron, 'origin' => 'cron_legado', 'managed' => false],
        ]);

        $this->app->instance(ProcessRunner::class, new FakeProcessRunner());
    }

    public function test_cataloga_backups_que_o_painel_nao_criou(): void
    {
        $this->drop($this->cron.'/sistema_hml-20260822-0200.sql.gz');
        $this->drop($this->cron.'/pre-deploy-20260810-101500.sql.gz');

        $result = app(BackupDiscoveryService::class)->scan();

        $this->assertSame(2, $result['catalogados']);

        $doCron = Backup::query()->where('arquivo_nome', 'sistema_hml-20260822-0200.sql.gz')->firstOrFail();
        $this->assertSame(BackupOrigin::CronLegado, $doCron->origem);
        $this->assertSame(BackupContent::SomenteBanco, $doCron->conteudo);

        // Nome de pre-deploy tem procedencia propria, mesmo vivendo na pasta
        // do cron: e o script de implantacao que o gera.
        $preDeploy = Backup::query()->where('arquivo_nome', 'pre-deploy-20260810-101500.sql.gz')->firstOrFail();
        $this->assertSame(BackupOrigin::PreDeploy, $preDeploy->origem);
    }

    public function test_marca_como_nao_gerenciado_o_que_o_painel_nao_pode_apagar(): void
    {
        $this->drop($this->cron.'/sistema_hml-20260822-0200.sql.gz');

        app(BackupDiscoveryService::class)->scan();

        $backup = Backup::query()->firstOrFail();

        // Nao gerenciado => a retencao nunca tenta apagar e a interface
        // desabilita o botao Excluir, em vez de falhar toda noite.
        $this->assertFalse($backup->gerenciado);
        $this->assertFalse($backup->canBeDeletedByPanel());
    }

    public function test_varredura_e_idempotente(): void
    {
        $this->drop($this->cron.'/sistema_hml-20260822-0200.sql.gz');

        $primeira = app(BackupDiscoveryService::class)->scan();
        $segunda = app(BackupDiscoveryService::class)->scan();

        $this->assertSame(1, $primeira['catalogados']);
        $this->assertSame(0, $segunda['catalogados']);
        $this->assertSame(0, $segunda['atualizados']);
        $this->assertSame(1, Backup::query()->count());
    }

    public function test_arquivo_removido_do_disco_vira_ausente_sem_perder_o_historico(): void
    {
        $caminho = $this->cron.'/sistema_hml-20260822-0200.sql.gz';
        $this->drop($caminho);

        app(BackupDiscoveryService::class)->scan();
        unlink($caminho);
        $result = app(BackupDiscoveryService::class)->scan();

        $this->assertSame(1, $result['ausentes']);

        // A linha continua existindo: saber que um backup existiu e sumiu vale
        // mais que a linha desaparecer junto com o arquivo.
        $backup = Backup::query()->firstOrFail();
        $this->assertSame(BackupStatus::Ausente, $backup->status);
    }

    public function test_nao_calcula_sha256_na_varredura(): void
    {
        $this->drop($this->cron.'/sistema_hml-20260822-0200.sql.gz');

        app(BackupDiscoveryService::class)->scan();

        // Rehashear centenas de MB a cada 15 minutos seria desperdicio puro:
        // o hash e calculado sob demanda, na primeira verificacao.
        $this->assertNull(Backup::query()->firstOrFail()->sha256);
    }

    private function drop(string $path): void
    {
        file_put_contents($path, 'conteudo-de-teste');
    }
}
