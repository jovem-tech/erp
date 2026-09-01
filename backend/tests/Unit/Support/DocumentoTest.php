<?php

namespace Tests\Unit\Support;

use App\Support\Documento;
use PHPUnit\Framework\TestCase;

/**
 * CPF e CNPJ.
 *
 * O caso que justifica a classe existir é o de normalização: o mesmo documento
 * com e sem máscara tem de virar a MESMA string, ou o `Rule::unique` de
 * `clientes.cpf_cnpj` deixa passar duplicata sem reclamar.
 *
 * O caso que justifica os testes de letra é o CNPJ alfanumérico, em produção
 * desde 06/07/2026: uma normalização feita com `preg_replace('/\D+/')` apaga as
 * letras e transforma um CNPJ novo em lixo, silenciosamente.
 */
class DocumentoTest extends TestCase
{
    public function test_normaliza_removendo_mascara_e_mantendo_letras(): void
    {
        $this->assertSame('52998224725', Documento::normalizar('529.982.247-25'));
        $this->assertSame('11222333000181', Documento::normalizar('11.222.333/0001-81'));
        $this->assertSame('12ABC34501DE35', Documento::normalizar('12.abc.345/01de-35'));
    }

    public function test_o_mesmo_documento_com_e_sem_mascara_normaliza_igual(): void
    {
        // É este assert que impede a duplicata silenciosa no `unique`.
        $this->assertSame(
            Documento::normalizar('529.982.247-25'),
            Documento::normalizar('52998224725')
        );
    }

    public function test_valor_vazio_vira_null(): void
    {
        $this->assertNull(Documento::normalizar(''));
        $this->assertNull(Documento::normalizar(null));
        $this->assertNull(Documento::normalizar('   '));
        $this->assertNull(Documento::normalizar('.-/'));
    }

    public function test_aceita_cpf_valido_com_e_sem_mascara(): void
    {
        $this->assertTrue(Documento::valido('529.982.247-25'));
        $this->assertTrue(Documento::valido('52998224725'));
        $this->assertTrue(Documento::ehCpf('52998224725'));
        $this->assertFalse(Documento::ehCnpj('52998224725'));
    }

    public function test_recusa_cpf_com_digito_verificador_errado(): void
    {
        $this->assertFalse(Documento::valido('529.982.247-26'));
    }

    public function test_recusa_sequencia_repetida_que_passa_no_modulo_11(): void
    {
        // O furo clássico: estes passam no cálculo do DV e não são documento.
        $this->assertFalse(Documento::valido('111.111.111-11'));
        $this->assertFalse(Documento::valido('00000000000'));
        $this->assertFalse(Documento::valido('00000000000000'));
        $this->assertFalse(Documento::valido('AAAAAAAAAAAA00'));
    }

    public function test_aceita_cnpj_numerico_valido(): void
    {
        $this->assertTrue(Documento::valido('11.222.333/0001-81'));
        $this->assertTrue(Documento::valido('11222333000181'));
        $this->assertTrue(Documento::ehCnpj('11222333000181'));
        $this->assertFalse(Documento::ehCpf('11222333000181'));
    }

    public function test_recusa_cnpj_com_digito_verificador_errado(): void
    {
        $this->assertFalse(Documento::valido('11.222.333/0001-82'));
    }

    public function test_aceita_cnpj_alfanumerico(): void
    {
        // Em produção desde 06/07/2026. Mesmo módulo 11, com o valor de cada
        // caractere valendo ord($c) - 48 (logo 'A' = 17).
        $this->assertTrue(Documento::valido('12ABC34501DE35'));
        $this->assertTrue(Documento::ehCnpj('12abc34501de35'));
    }

    public function test_recusa_cnpj_alfanumerico_com_digito_errado(): void
    {
        $this->assertFalse(Documento::valido('12ABC34501DE00'));
    }

    public function test_recusa_letra_na_posicao_do_digito_verificador(): void
    {
        // As duas últimas posições continuam numéricas mesmo no formato novo.
        $this->assertFalse(Documento::valido('12ABC34501DEX5'));
    }

    public function test_recusa_letra_em_cpf(): void
    {
        // Alfanumérico é exclusividade do CNPJ.
        $this->assertFalse(Documento::valido('5299822472A'));
    }

    public function test_recusa_tamanho_invalido(): void
    {
        $this->assertFalse(Documento::valido('123'));
        $this->assertFalse(Documento::valido('529982247251'));
    }

    public function test_formata_para_exibicao(): void
    {
        $this->assertSame('529.982.247-25', Documento::formatar('52998224725'));
        $this->assertSame('11.222.333/0001-81', Documento::formatar('11222333000181'));
        $this->assertSame('12.ABC.345/01DE-35', Documento::formatar('12ABC34501DE35'));
        $this->assertSame('', Documento::formatar(null));
    }

    public function test_formatar_devolve_valor_invalido_como_esta(): void
    {
        // Formatar não é lugar de esconder dado ruim.
        $this->assertSame('123', Documento::formatar('123'));
    }
}
