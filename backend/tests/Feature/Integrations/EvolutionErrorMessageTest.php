<?php

namespace Tests\Feature\Integrations;

use App\Models\Configuration;
use App\Services\Integrations\IntegrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * O que a Evolution API responde quando o envio falha.
 *
 * A API devolve `error: "Bad Request"` no topo e o motivo de verdade lá dentro,
 * em `response.message`. Lendo só o topo, o operador recebia **"Bad Request"**
 * na tela — que não diz o que fazer. O caso do dia a dia é o telefone do
 * cadastro simplesmente não ter WhatsApp, e é isso que a mensagem precisa dizer.
 */
class EvolutionErrorMessageTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();

        foreach ([
            'whatsapp_direct_provider' => 'evolution',
            'whatsapp_evolution_url' => 'https://evolution.exemplo',
            'whatsapp_evolution_apikey' => 'chave',
            'whatsapp_evolution_instance' => 'oficina',
        ] as $chave => $valor) {
            Configuration::query()->create(['chave' => $chave, 'valor' => $valor]);
        }
    }

    public function test_numero_sem_whatsapp_vira_frase_que_diz_o_que_houve(): void
    {
        Http::fake([
            '*/message/sendMedia/*' => Http::response([
                'status' => 400,
                'error' => 'Bad Request',
                'response' => ['message' => [
                    ['jid' => '5522992741004@s.whatsapp.net', 'exists' => false, 'number' => '5522992741004'],
                ]],
            ], 400),
        ]);

        $resultado = app(IntegrationSettingsService::class)->sendDirectMedia(
            '(22) 99274-1004',
            $this->arquivo(),
            'document',
            'Segue a nota',
            'nfse-3.xml'
        );

        $this->assertFalse($resultado['ok']);
        $this->assertSame('O número (22) 99274-1004 não tem WhatsApp.', $resultado['message']);
    }

    public function test_erro_de_validacao_da_evolution_chega_inteiro(): void
    {
        Http::fake([
            '*/message/sendMedia/*' => Http::response([
                'status' => 400,
                'error' => 'Bad Request',
                'response' => ['message' => ['mediatype must be a valid enum value']],
            ], 400),
        ]);

        $resultado = app(IntegrationSettingsService::class)->sendDirectMedia(
            '(22) 99274-1004',
            $this->arquivo(),
            'document'
        );

        $this->assertFalse($resultado['ok']);
        $this->assertSame('mediatype must be a valid enum value', $resultado['message']);
    }

    /**
     * Sem detalhe nenhum, o status ainda é melhor que uma frase vazia.
     */
    public function test_sem_detalhe_sobra_o_status(): void
    {
        Http::fake(['*/message/sendMedia/*' => Http::response([], 502)]);

        $resultado = app(IntegrationSettingsService::class)->sendDirectMedia(
            '(22) 99274-1004',
            $this->arquivo(),
            'document'
        );

        $this->assertFalse($resultado['ok']);
        $this->assertSame('Falha na resposta do gateway (HTTP 502).', $resultado['message']);
    }

    private function arquivo(): string
    {
        return base_path('tests/Fixtures/nfse/nfse-real-mei.xml');
    }
}
