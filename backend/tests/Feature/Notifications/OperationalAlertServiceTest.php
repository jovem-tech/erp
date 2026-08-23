<?php

namespace Tests\Feature\Notifications;

use App\Mail\OperationalAlertMail;
use App\Services\Channels\Whatsapp\WhatsappMessagingService;
use App\Services\Integrations\EmailIntegrationSettingsService;
use App\Services\Notifications\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class OperationalAlertServiceTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        Cache::flush();
    }

    private function service(
        ?WhatsappMessagingService $whatsapp = null,
        bool $mailerDisponivel = true
    ): OperationalAlertService {
        $whatsapp ??= $this->whatsappOk();

        $email = Mockery::mock(EmailIntegrationSettingsService::class);
        $email->shouldReceive('operationalMailerAvailable')->andReturn($mailerDisponivel);

        return new OperationalAlertService($whatsapp, $email);
    }

    private function whatsappOk(): WhatsappMessagingService
    {
        $mock = Mockery::mock(WhatsappMessagingService::class);
        $mock->shouldReceive('sendSystemMessage')->andReturn(['ok' => true]);

        return $mock;
    }

    public function test_alerta_urgente_vai_por_whatsapp_e_email(): void
    {
        config(['alertas.whatsapp' => '22999998888', 'alertas.email' => 'dono@example.com']);
        Mail::fake();

        $whatsapp = Mockery::mock(WhatsappMessagingService::class);
        $whatsapp->shouldReceive('sendSystemMessage')
            ->once()
            ->withArgs(fn (string $numero, string $texto): bool => $numero === '22999998888'
                && str_contains($texto, 'Certificado vencendo'))
            ->andReturn(['ok' => true]);

        $entregue = $this->service($whatsapp)->urgente(
            'Certificado vencendo',
            'O certificado da aplicacao vence em 7 dias.',
            ['dias_restantes' => 7]
        );

        $this->assertTrue($entregue);
        Mail::assertSent(OperationalAlertMail::class);
    }

    public function test_relatorio_nao_usa_whatsapp(): void
    {
        config(['alertas.whatsapp' => '22999998888', 'alertas.email' => 'dono@example.com']);
        Mail::fake();

        $whatsapp = Mockery::mock(WhatsappMessagingService::class);
        $whatsapp->shouldNotReceive('sendSystemMessage');

        $this->assertTrue(
            $this->service($whatsapp)->relatorio('Conciliacao diaria', 'Nada divergente hoje.')
        );

        Mail::assertSent(OperationalAlertMail::class);
    }

    public function test_falha_de_entrega_nao_lanca_excecao(): void
    {
        config(['alertas.whatsapp' => '22999998888', 'alertas.email' => 'dono@example.com']);
        Mail::fake();

        // Alerta e' caminho de observabilidade: se ele quebrar, nao pode
        // derrubar a operacao que estava sendo observada.
        $whatsapp = Mockery::mock(WhatsappMessagingService::class);
        $whatsapp->shouldReceive('sendSystemMessage')->andThrow(new RuntimeException('gateway fora do ar'));

        $entregue = $this->service($whatsapp)->urgente('Baixa divergente', 'Valor nao confere.');

        // E-mail salvou a entrega, mesmo com o WhatsApp explodindo.
        $this->assertTrue($entregue);
        Mail::assertSent(OperationalAlertMail::class);
    }

    public function test_sem_smtp_real_o_email_nao_conta_como_entregue(): void
    {
        config(['alertas.whatsapp' => '', 'alertas.email' => 'dono@example.com']);
        Mail::fake();

        // Com MAIL_MAILER=log o e-mail iria para o mesmo log que ja estamos
        // gravando - reportar "entregue" seria mentira.
        $entregue = $this->service(mailerDisponivel: false)->urgente('Sem canal', 'Ninguem vai ver isto.');

        $this->assertFalse($entregue);
        Mail::assertNothingSent();
    }

    public function test_dedupe_impede_repeticao_do_mesmo_alerta(): void
    {
        config(['alertas.whatsapp' => '', 'alertas.email' => 'dono@example.com', 'alertas.dedupe_minutos' => 720]);
        Mail::fake();

        $service = $this->service();

        $this->assertTrue($service->urgente('Cert', 'vence em 7 dias', [], 'cert:7'));
        $this->assertFalse($service->urgente('Cert', 'vence em 7 dias', [], 'cert:7'));
        // Chave diferente passa: D-7 e D-1 sao alertas distintos.
        $this->assertTrue($service->urgente('Cert', 'vence em 1 dia', [], 'cert:1'));

        Mail::assertSent(OperationalAlertMail::class, 2);
    }

    public function test_alertas_desligados_suprimem_o_envio(): void
    {
        config(['alertas.enabled' => false, 'alertas.email' => 'dono@example.com']);
        Mail::fake();

        $this->assertFalse($this->service()->urgente('Qualquer', 'coisa'));

        Mail::assertNothingSent();
    }
}
