<?php

namespace Tests\Feature\Integrations\Inter;

use App\Mail\OperationalAlertMail;
use App\Services\Integrations\EmailIntegrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\Concerns\GeneratesInterTestCertificate;
use Tests\TestCase;

class VerificarCertificadoInterTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use GeneratesInterTestCertificate;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        Cache::flush();
        Mail::fake();

        // Alerta so' por e-mail: o caminho do WhatsApp depende do schema de
        // chat e nao e' o que este teste mede.
        config(['alertas.enabled' => true, 'alertas.email' => 'dono@example.com', 'alertas.whatsapp' => '']);

        // SMTP operacional configurado: sem isto o OperationalAlertService
        // recusa a entrega de proposito (cairia no mailer `log`, que e' onde
        // ja gravamos — reportar "entregue" seria mentira). Aqui queremos
        // medir o comando, nao aquele guard.
        app(EmailIntegrationSettingsService::class)->save([
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_crypto' => 'tls',
            'smtp_timeout' => 10,
            'smtp_user' => 'alerta@example.test',
            'smtp_pass' => 'segredo',
            'smtp_from_email' => 'alerta@example.test',
            'smtp_from_name' => 'ERP',
        ]);
    }

    protected function tearDown(): void
    {
        $this->limparCertificadosDeTeste();

        parent::tearDown();
    }

    public function test_certificado_longe_do_vencimento_nao_alerta(): void
    {
        $this->gerarCertificadoDeTeste(diasDeValidade: 200);
        $this->gravarCredenciaisDoInter();

        $this->artisan('inter:verificar-certificado --alertar')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_dentro_do_limiar_alerta_uma_vez_por_limiar(): void
    {
        $this->gerarCertificadoDeTeste(diasDeValidade: 6);
        $this->gravarCredenciaisDoInter();

        $this->artisan('inter:verificar-certificado --alertar')->assertSuccessful();
        // Segunda execucao no mesmo dia nao repete: o scheduler roda todo dia
        // e o dono nao precisa do mesmo aviso varias vezes.
        $this->artisan('inter:verificar-certificado --alertar')->assertSuccessful();

        Mail::assertSent(OperationalAlertMail::class, 1);
    }

    public function test_certificado_vencido_falha_e_alerta(): void
    {
        $this->gerarCertificadoDeTeste(diasDeValidade: -3);
        $this->gravarCredenciaisDoInter();

        $this->artisan('inter:verificar-certificado --alertar')->assertFailed();

        Mail::assertSent(OperationalAlertMail::class, 1);
    }

    public function test_certificado_ilegivel_falha_em_vez_de_assumir_que_esta_valido(): void
    {
        $this->gerarCertificadoDeTeste();
        File::put(config('inter.certificado.cert_path'), 'lixo');
        $this->gravarCredenciaisDoInter();

        $this->artisan('inter:verificar-certificado --alertar')->assertFailed();

        Mail::assertSent(OperationalAlertMail::class, 1);
    }

    public function test_sem_integracao_configurada_nao_faz_barulho(): void
    {
        config([
            'inter.certificado.cert_path' => '/nao/existe.crt',
            'inter.certificado.key_path' => '/nao/existe.key',
        ]);

        $this->artisan('inter:verificar-certificado --alertar')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_sem_a_flag_alertar_apenas_relata(): void
    {
        $this->gerarCertificadoDeTeste(diasDeValidade: -1);
        $this->gravarCredenciaisDoInter();

        $this->artisan('inter:verificar-certificado')->assertFailed();

        Mail::assertNothingSent();
    }
}
