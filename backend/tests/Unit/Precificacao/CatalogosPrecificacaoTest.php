<?php

namespace Tests\Unit\Precificacao;

use App\Services\Financeiro\PrecoQuote;
use App\Support\FaixaMargem;
use App\Support\ModoPrecificacao;
use App\Support\VisibilidadeCusto;
use PHPUnit\Framework\TestCase;

/**
 * Catalogos de precificacao — specs/037-precificacao-integrada-ao-fluxo.
 *
 * Sem banco: sao regras puras, e e onde as bordas perigosas moram.
 */
class CatalogosPrecificacaoTest extends TestCase
{
    /**
     * A borda que decide se o semaforo ajuda ou atrapalha.
     *
     * Peca sem custo cadastrado tem margem aritmetica de 100%. Se isso virasse
     * VERDE, o recurso premiaria exatamente o cadastro incompleto: quem
     * esquecesse o custo veria a tela toda verde e confiaria nela.
     */
    public function test_custo_desconhecido_e_indefinido_nunca_verde(): void
    {
        $this->assertSame(FaixaMargem::INDEFINIDO, FaixaMargem::classificar(null));

        $cotacao = PrecoQuote::criar(
            valorRecomendado: 100.0,
            precoMinimo: 80.0,
            custoUnitario: null,
            valorCobrado: 100.0,
        );

        $this->assertSame(FaixaMargem::INDEFINIDO, $cotacao->faixa);
        $this->assertNull($cotacao->margemPercentual);
    }

    public function test_faixas_seguem_os_limites_configurados(): void
    {
        $this->assertSame(FaixaMargem::VERDE, FaixaMargem::classificar(30.0));
        $this->assertSame(FaixaMargem::AMARELO, FaixaMargem::classificar(29.99));
        $this->assertSame(FaixaMargem::AMARELO, FaixaMargem::classificar(15.0));
        $this->assertSame(FaixaMargem::VERMELHO, FaixaMargem::classificar(14.99));
        $this->assertSame(FaixaMargem::VERMELHO, FaixaMargem::classificar(-5.0));
    }

    /**
     * O piso vence o percentual: vender abaixo do custo variavel e vermelho
     * mesmo que a conta de margem devolva um numero confortavel.
     */
    public function test_abaixo_do_piso_e_sempre_vermelho(): void
    {
        $this->assertSame(FaixaMargem::VERMELHO, FaixaMargem::classificar(90.0, abaixoDoPiso: true));
    }

    /**
     * A redacao acontece no DTO, nao na view: a chave nao pode existir no
     * array, senao chega ao DOM e aparece no devtools de quem nao pode ver.
     */
    public function test_visibilidade_indicativa_remove_o_custo_do_payload(): void
    {
        $cotacao = PrecoQuote::criar(
            valorRecomendado: 187.05,
            precoMinimo: 150.0,
            custoUnitario: 129.0,
            valorCobrado: 200.0,
            composicao: ['custo_mao_obra' => 64.77],
        );

        $completo = $cotacao->toArray(VisibilidadeCusto::COMPLETO);
        $this->assertArrayHasKey('custo_unitario', $completo);
        $this->assertArrayHasKey('composicao', $completo);
        $this->assertSame(35.5, $completo['margem_percentual']);

        $indicativo = $cotacao->toArray(VisibilidadeCusto::INDICATIVO);
        $this->assertArrayNotHasKey('custo_unitario', $indicativo);
        $this->assertArrayNotHasKey('margem_percentual', $indicativo);
        $this->assertArrayNotHasKey('composicao', $indicativo);
        // O piso permanece: quem vende precisa saber que passou dele.
        $this->assertArrayHasKey('preco_minimo', $indicativo);
        $this->assertArrayHasKey('faixa', $indicativo);

        $this->assertSame([], $cotacao->toArray(VisibilidadeCusto::NENHUM));
    }

    public function test_visibilidade_desconhecida_cai_no_mais_restritivo(): void
    {
        $this->assertSame(VisibilidadeCusto::NENHUM, VisibilidadeCusto::normalizar('banana'));
        $this->assertSame(VisibilidadeCusto::NENHUM, VisibilidadeCusto::normalizar(null));
    }

    /**
     * O modo e resolvido no servidor por comparacao. Se viesse do cliente,
     * a coluna registraria a intencao declarada, nao o fato.
     */
    public function test_modo_precificacao_e_resolvido_por_comparacao(): void
    {
        $this->assertSame(
            ModoPrecificacao::SUGERIDO,
            ModoPrecificacao::resolver(187.05, 187.05, 210.0, true)
        );

        $this->assertSame(
            ModoPrecificacao::TABELA,
            ModoPrecificacao::resolver(210.0, 187.05, 210.0, true)
        );

        $this->assertSame(
            ModoPrecificacao::MANUAL,
            ModoPrecificacao::resolver(150.0, 187.05, 210.0, true)
        );

        $this->assertSame(
            ModoPrecificacao::AVULSO,
            ModoPrecificacao::resolver(150.0, null, null, false)
        );
    }

    /**
     * Sugerido vence tabela quando os dois coincidem: seguir a recomendacao e
     * a informacao mais forte.
     */
    public function test_sugerido_vence_tabela_no_empate(): void
    {
        $this->assertSame(
            ModoPrecificacao::SUGERIDO,
            ModoPrecificacao::resolver(200.0, 200.0, 200.0, true)
        );
    }

    public function test_tolerancia_de_centavo_na_comparacao(): void
    {
        $this->assertSame(
            ModoPrecificacao::SUGERIDO,
            ModoPrecificacao::resolver(187.06, 187.05, null, true)
        );

        $this->assertSame(
            ModoPrecificacao::MANUAL,
            ModoPrecificacao::resolver(187.10, 187.05, null, true)
        );
    }
}
