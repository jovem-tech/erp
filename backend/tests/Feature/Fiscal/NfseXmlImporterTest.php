<?php

namespace Tests\Feature\Fiscal;

use App\Services\Fiscal\NfseXmlImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Leitura do XML da NFS-e baixado do Emissor Nacional.
 *
 * A fixture é uma nota real (ver tests/Fixtures/nfse/ORIGEM.md). Testar contra
 * o arquivo de verdade é o que separa "meu parser funciona no meu exemplo" de
 * "funciona no que o portal entrega" — inclusive na acentuação duplamente
 * codificada, que só aparece no arquivo real.
 */
class NfseXmlImporterTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
    }

    public function test_extrai_numero_serie_e_chave_da_nota_real(): void
    {
        $lido = $this->importar();

        $this->assertSame('2', $lido['numero']);
        $this->assertSame('70000', $lido['serie']);
        $this->assertSame('33052082234129526000198000000000000226086919348703', $lido['chave']);
        $this->assertSame('1', $lido['numero_dps']);
        $this->assertSame(270.00, $lido['valor']);
    }

    public function test_corrige_a_acentuacao_dupla_do_portal(): void
    {
        // O arquivo vem com "SÃ£o Pedro" e "ServiÃ§os". Guardar assim colocaria
        // lixo na tela e no PDF que vai ao cliente.
        $lido = $this->importar();

        $this->assertSame('São Pedro da Aldeia', $lido['municipio']);
        $this->assertSame('reparo em dispositivo móvel avariado', $lido['descricao_servico']);
        $this->assertStringContainsString('Serviços técnicos', (string) $lido['descricao_tributacao']);
        $this->assertStringNotContainsString('Ã', (string) $lido['descricao_tributacao']);
    }

    public function test_le_os_codigos_fiscais_do_servico(): void
    {
        $lido = $this->importar();

        $this->assertSame('310102', $lido['codigo_tributacao']);
        $this->assertSame('120018100', $lido['codigo_nbs']);
    }

    public function test_le_prestador_e_tomador(): void
    {
        $lido = $this->importar();

        $this->assertSame('34129526000198', $lido['prestador_documento']);
        $this->assertSame('72063654001309', $lido['tomador_documento']);
        $this->assertSame('ABRIGO DO MARINHEIRO', $lido['tomador_nome']);
    }

    public function test_recusa_nota_de_outro_cnpj(): void
    {
        // A checagem que impede anexar a nota de outro emitente a uma OS nossa.
        $this->cadastrarCnpj('11222333000181');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/outro CNPJ/');

        $this->importar();
    }

    public function test_aceita_quando_o_cnpj_da_empresa_confere(): void
    {
        $this->cadastrarCnpj('34129526000198');

        $this->assertSame('2', $this->importar()['numero']);
    }

    public function test_sem_cnpj_cadastrado_nao_bloqueia(): void
    {
        // Recusar aqui travaria quem ainda não terminou o cadastro da empresa.
        $this->assertSame('2', $this->importar()['numero']);
    }

    public function test_recusa_arquivo_que_nao_e_nfse(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/padrão nacional/');

        app(NfseXmlImporter::class)->ler('<?xml version="1.0"?><outraCoisa><a>1</a></outraCoisa>');
    }

    public function test_recusa_arquivo_que_nao_e_xml(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/XML válido/');

        app(NfseXmlImporter::class)->ler('isto nao e xml nenhum');
    }

    /**
     * @return array<string, mixed>
     */
    private function importar(): array
    {
        return app(NfseXmlImporter::class)->ler(
            (string) file_get_contents(base_path('tests/Fixtures/nfse/nfse-real-mei.xml'))
        );
    }

    private function cadastrarCnpj(string $cnpj): void
    {
        DB::table('configuracoes')->insert([
            'chave' => 'empresa_cnpj',
            'valor' => $cnpj,
            'tipo' => 'texto',
        ]);
    }
}
