<?php

namespace Tests\Feature\Backups;

use App\Enums\Backups\BackupStatus;
use App\Models\Backups\Backup;
use App\Services\Backups\BackupRetentionPolicy;
use App\Services\Backups\BackupSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class BackupRetentionPolicyTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `configuracoes` e uma tabela legada: so existe no schema montado
        // por este trait, nao nas migracoes do Laravel.
        $this->rebuildLegacySchema();
        config()->set('cache.default', 'array');
    }

    public function test_mantem_o_ultimo_backup_bom_mesmo_com_retencao_zerada(): void
    {
        $settings = app(BackupSettingsService::class);
        $settings->put('backup_retencao_diarios', '0');
        $settings->put('backup_retencao_semanais', '0');
        $settings->put('backup_retencao_mensais', '0');
        $settings->put('backup_retencao_minimo_copias', '1');

        $this->makeBackup(Carbon::parse('2026-08-20 03:15:00'));
        $recente = $this->makeBackup(Carbon::parse('2026-08-22 03:15:00'));

        $result = app(BackupRetentionPolicy::class)->apply();

        // O piso duro existe justamente para o caso de alguem zerar a tela:
        // o sistema nunca pode ficar sem nenhuma copia boa.
        $this->assertSame(1, $result['mantidos']);
        $this->assertSame(BackupStatus::Concluido, $recente->refresh()->status);
    }

    public function test_nunca_apaga_backup_protegido(): void
    {
        $this->configureRetention(diarios: 1);

        $protegido = $this->makeBackup(Carbon::parse('2026-01-01 03:15:00'), ['protegido' => true]);
        $this->makeBackup(Carbon::parse('2026-08-22 03:15:00'));

        app(BackupRetentionPolicy::class)->apply();

        $this->assertSame(BackupStatus::Concluido, $protegido->refresh()->status);
        $this->assertTrue(is_file((string) $protegido->arquivo_caminho));
    }

    public function test_nunca_apaga_backup_nao_gerenciado_pelo_painel(): void
    {
        $this->configureRetention(diarios: 1);

        // Os dumps do cron de root sao root:root: o painel le e restaura, mas
        // tentar apagar falharia silenciosamente toda noite.
        $doCron = $this->makeBackup(Carbon::parse('2026-01-01 02:00:00'), ['gerenciado' => false]);
        $this->makeBackup(Carbon::parse('2026-08-22 03:15:00'));

        app(BackupRetentionPolicy::class)->apply();

        $this->assertSame(BackupStatus::Concluido, $doCron->refresh()->status);
        $this->assertTrue(is_file((string) $doCron->arquivo_caminho));
    }

    public function test_expurga_mantendo_o_historico_no_catalogo(): void
    {
        $this->configureRetention(diarios: 1, minimo: 1);

        $antigo = $this->makeBackup(Carbon::parse('2026-06-01 03:15:00'));
        $this->makeBackup(Carbon::parse('2026-08-22 03:15:00'));

        $caminho = (string) $antigo->arquivo_caminho;
        $result = app(BackupRetentionPolicy::class)->apply();

        $this->assertSame(1, $result['removidos']);
        $this->assertFalse(is_file($caminho), 'O arquivo deveria ter sido removido do disco.');

        // A linha permanece: ocupa quase nada e e o historico que o painel
        // mostra. Saber que um backup existiu vale mais que a linha sumir.
        $antigo->refresh();
        $this->assertSame(BackupStatus::Expirado, $antigo->status);
    }

    public function test_simulacao_nao_apaga_nada(): void
    {
        $this->configureRetention(diarios: 1, minimo: 1);

        $antigo = $this->makeBackup(Carbon::parse('2026-06-01 03:15:00'));
        $this->makeBackup(Carbon::parse('2026-08-22 03:15:00'));

        $result = app(BackupRetentionPolicy::class)->apply(dryRun: true);

        $this->assertSame(1, $result['removidos']);
        $this->assertTrue(is_file((string) $antigo->arquivo_caminho));
        $this->assertSame(BackupStatus::Concluido, $antigo->refresh()->status);
    }

    private function configureRetention(int $diarios = 7, int $semanais = 0, int $mensais = 0, int $minimo = 1): void
    {
        $settings = app(BackupSettingsService::class);
        $settings->put('backup_retencao_diarios', (string) $diarios);
        $settings->put('backup_retencao_semanais', (string) $semanais);
        $settings->put('backup_retencao_mensais', (string) $mensais);
        $settings->put('backup_retencao_minimo_copias', (string) $minimo);
    }

    /** @param array<string, mixed> $attributes */
    private function makeBackup(Carbon $when, array $attributes = []): Backup
    {
        $path = tempnam(sys_get_temp_dir(), 'erp-backup-teste-');
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
            'created_at' => $when,
            'updated_at' => $when,
        ], $attributes));
    }
}
