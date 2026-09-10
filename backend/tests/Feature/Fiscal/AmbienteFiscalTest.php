<?php

namespace Tests\Feature\Fiscal;

use App\Models\Configuration;
use App\Services\Fiscal\AmbienteFiscal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\Concerns\GeneratesFiscalTestCertificate;
use Tests\TestCase;

/**
 * Ambiente de emissao: quem decide, com que guarda, e com que rastro.
 *
 * A chave que esta classe governa e' a de maior consequencia do modulo fiscal:
 * ligada, toda nota emitida passa a ter obrigacao tributaria real.
 */
class AmbienteFiscalTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use GeneratesFiscalTestCertificate;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        config()->set('fiscal.nfse.ambiente', AmbienteFiscal::HOMOLOGACAO);
    }

    protected function tearDown(): void
    {
        $this->limparCertificadoFiscalDeTeste();

        parent::tearDown();
    }

    public function test_nasce_em_homologacao(): void
    {
        // O default seguro nao pode depender de ninguem lembrar de configurar.
        $this->assertSame(AmbienteFiscal::HOMOLOGACAO, $this->servico()->atual());
        $this->assertFalse($this->servico()->ehProducao());
    }

    public function test_o_valor_da_tela_vence_o_env(): void
    {
        // Mesma precedencia da senha do certificado: o `.env` e' padrao de
        // instalacao, a tela e' operacao.
        config()->set('fiscal.nfse.ambiente', AmbienteFiscal::HOMOLOGACAO);
        Configuration::query()->updateOrInsert(
            ['chave' => AmbienteFiscal::CHAVE],
            ['valor' => (string) AmbienteFiscal::PRODUCAO, 'tipo' => 'texto']
        );

        $this->assertSame(AmbienteFiscal::PRODUCAO, $this->servico()->atual());
    }

    public function test_valor_estranho_no_banco_cai_para_homologacao(): void
    {
        // O default seguro nao pode depender de o dado estar sao.
        Configuration::query()->updateOrInsert(
            ['chave' => AmbienteFiscal::CHAVE],
            ['valor' => '7', 'tipo' => 'texto']
        );

        $this->assertSame(AmbienteFiscal::HOMOLOGACAO, $this->servico()->atual());
    }

    public function test_recusa_ligar_producao_sem_certificado(): void
    {
        $this->desinstalarCertificadoFiscalDeTeste();
        $this->cadastrarEmpresaCompleta();

        try {
            $this->servico()->definir(AmbienteFiscal::PRODUCAO);
            $this->fail('Deveria ter lancado ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('produção', implode(' ', $e->errors()['ambiente']));
        }

        $this->assertSame(AmbienteFiscal::HOMOLOGACAO, $this->servico()->atual());
    }

    public function test_recusa_ligar_producao_com_cadastro_fiscal_incompleto(): void
    {
        // Ligar producao para descobrir na primeira nota que falta o codigo
        // IBGE troca um erro barato por um caro.
        $this->instalarCertificadoFiscalDeTeste();
        // sem cadastrar a empresa

        $this->expectException(ValidationException::class);

        $this->servico()->definir(AmbienteFiscal::PRODUCAO);
    }

    public function test_liga_producao_quando_tudo_esta_pronto_e_deixa_rastro(): void
    {
        $this->instalarCertificadoFiscalDeTeste();
        $this->cadastrarEmpresaCompleta();

        $this->servico()->definir(AmbienteFiscal::PRODUCAO, usuarioId: 42, ip: '10.0.0.9', userAgent: 'Firefox');

        $this->assertSame(AmbienteFiscal::PRODUCAO, $this->servico()->atual());

        // Quem ligou producao e quando e' pergunta que aparece depois de a nota
        // errada existir — o rastro tem de estar la' antes.
        $log = DB::table('logs')->where('acao', 'fiscal_ambiente_alterado')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame(42, (int) $log->usuario_id);
        $this->assertSame('10.0.0.9', $log->ip);
        $this->assertStringContainsString('PRODUÇÃO', $log->descricao);
    }

    public function test_voltar_para_homologacao_nao_exige_nada(): void
    {
        // O caminho de REDUZIR risco tem de ser o mais facil dos dois.
        $this->desinstalarCertificadoFiscalDeTeste();

        $this->servico()->definir(AmbienteFiscal::HOMOLOGACAO);

        $this->assertSame(AmbienteFiscal::HOMOLOGACAO, $this->servico()->atual());
    }

    public function test_ambiente_invalido_e_recusado(): void
    {
        $this->expectException(ValidationException::class);

        $this->servico()->definir(9);
    }

    private function cadastrarEmpresaCompleta(): void
    {
        foreach ([
            'empresa_cnpj' => '11222333000181',
            'empresa_codigo_ibge' => '3550308',
            'empresa_codigo_tributacao_nacional' => '010701',
        ] as $chave => $valor) {
            DB::table('configuracoes')->updateOrInsert(['chave' => $chave], ['valor' => $valor, 'tipo' => 'texto']);
        }
    }

    private function servico(): AmbienteFiscal
    {
        // Instancia nova a cada chamada: o servico memoiza o valor por
        // instancia, e o teste precisa enxergar o que acabou de gravar.
        return app()->make(AmbienteFiscal::class);
    }
}
