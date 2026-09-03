<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClienteHttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Emissão assistida (fase 042).
 *
 * O que estes testes protegem é o par de avisos que transforma o relatório em
 * ação: cliente sem documento não pode emitir, e peça não entra na NFS-e. Sem
 * eles a tela vira um formulário bonito que deixa emitir errado.
 */
class DocumentoFiscalTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_pendentes_marca_cliente_sem_documento(): void
    {
        $this->fakeApi([
            'fiscal/pendentes*' => [
                'ordens' => [
                    ['os_id' => 10, 'numero_os' => 'OS2609001', 'valor_final' => 420.0, 'entregue_em' => '2026-09-01T10:00:00-03:00', 'cliente_nome' => 'João', 'cliente_documento' => '52998224725'],
                    ['os_id' => 11, 'numero_os' => 'OS2609002', 'valor_final' => 180.0, 'entregue_em' => '2026-09-01T11:00:00-03:00', 'cliente_id' => 55, 'cliente_nome' => 'Maria', 'cliente_documento' => ''],
                ],
                'total' => 2,
            ],
        ]);

        // Com `clientes:editar` o aviso vira link para o cadastro.
        $this->withSession($this->desktopSession(['os' => ['visualizar'], 'clientes' => ['editar']]))
            ->get('/fiscal/pendentes')
            ->assertOk()
            ->assertSee('OS2609001')
            ->assertSee('OS2609002')
            // O aviso precisa LEVAR ao cadastro, nao so' apontar a falta.
            ->assertSee('preencher CPF/CNPJ')
            ->assertSee(route('clients.edit', 55), false);
    }

    public function test_sem_permissao_de_editar_cliente_o_aviso_nao_vira_link(): void
    {
        $this->fakeApi([
            'fiscal/pendentes*' => [
                'ordens' => [
                    ['os_id' => 11, 'numero_os' => 'OS2609002', 'valor_final' => 180.0, 'entregue_em' => null, 'cliente_id' => 55, 'cliente_nome' => 'Maria', 'cliente_documento' => ''],
                ],
                'total' => 1,
            ],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar']]))
            ->get('/fiscal/pendentes')
            ->assertOk()
            ->assertSee('sem documento')
            ->assertDontSee(route('clients.edit', 55), false);
    }

    public function test_pagina_da_nota_mostra_discriminacao_e_separa_peca(): void
    {
        $this->fakeApi(['orders/10/documento-fiscal' => ['documento' => $this->documento()]]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('Troca de tela')
            ->assertSee('Importar o XML da nota')
            // Peca aparece com o valor, mas dizendo que nao entra na NFS-e.
            ->assertSee('Não entra na NFS-e: peça é mercadoria.');
    }

    public function test_oferece_importar_xml_antes_de_digitar(): void
    {
        // Digitar do PDF e' onde o erro acontece; o XML tem tudo.
        $this->fakeApi(['orders/10/documento-fiscal' => ['documento' => $this->documento()]]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('Importar o XML da nota')
            ->assertSee('Ou registrar à mão')
            ->assertSee(route('fiscal.documentos.importar-xml', 7), false);
    }

    public function test_o_xml_realmente_viaja_na_requisicao_ao_backend(): void
    {
        // Este teste existe por causa de um defeito real: o `ApiClient` espera
        // `['campo' => [$arquivo]]` e recebia `['campo' => $arquivo]`. O
        // `foreach` iterava as PROPRIEDADES do UploadedFile, nao achava nenhum
        // arquivo e seguia SEM O ANEXO — sem erro. Os testes anteriores so'
        // olhavam o redirecionamento, entao passavam com o arquivo perdido.
        $this->fakeApi(['orders/10/documento-fiscal' => ['documento' => $this->documento()]]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->post('/fiscal/documentos/7/importar-xml', [
                'os_id' => 10,
                'arquivo_xml' => new \Illuminate\Http\UploadedFile(
                    base_path('tests/Fixtures/nfse-real-mei.xml'), 'nfse.xml', 'application/xml', null, true
                ),
            ]);

        Http::assertSent(function (ClienteHttpRequest $requisicao): bool {
            if (! str_contains($requisicao->url(), '/fiscal/documentos/7/importar-xml')) {
                return false;
            }

            // O conteudo do XML tem de estar no corpo multipart.
            return str_contains($requisicao->body(), 'infNFSe')
                && str_contains($requisicao->body(), 'name="arquivo"');
        });
    }

    public function test_erro_de_importacao_diz_o_que_fazer_e_onde(): void
    {
        // A queixa foi "a pendencia deve ser mais clara do que se trata":
        // "o campo arquivo XML e obrigatorio" nao diz nem onde nem o que.
        $this->fakeApi(['orders/10/documento-fiscal' => ['documento' => $this->documento()]]);

        $resposta = $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->post('/fiscal/documentos/7/importar-xml', ['os_id' => 10]);

        $resposta->assertSessionHasErrors('arquivo_xml');

        $mensagem = session('errors')->first('arquivo_xml');

        $this->assertStringContainsString('Importar o XML da nota', $mensagem);
        $this->assertStringContainsString('Emissor Nacional', $mensagem);
    }

    public function test_upload_de_pdf_no_lugar_do_xml_e_recusado_com_explicacao(): void
    {
        $this->fakeApi(['orders/10/documento-fiscal' => ['documento' => $this->documento()]]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->post('/fiscal/documentos/7/importar-xml', [
                'os_id' => 10,
                'arquivo_xml' => \Illuminate\Http\UploadedFile::fake()->create('danfse.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasErrors('arquivo_xml');

        $this->assertStringContainsString(
            'Guardar arquivos do portal',
            session('errors')->first('arquivo_xml')
        );
    }

    public function test_oferece_completar_o_cadastro_do_cliente_sem_sair_da_tela(): void
    {
        // O dado fiscal falta exatamente na hora de emitir; mandar o operador
        // sair, achar o cliente e voltar e' caminho que nao se percorre.
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'tomador_documento' => '', 'cliente_id' => 77,
            ])],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar'], 'clientes' => ['editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('Completar cadastro')
            ->assertSee('quickEditClientModal')
            // O modal precisa do codigo IBGE: a NFS-e identifica municipio por
            // ele, e sem o campo o cadastro rapido nao completa o que falta.
            ->assertSee('codigo_ibge_municipio')
            ->assertSee(route('clients.quick.update', 77), false);
    }

    public function test_sem_permissao_de_editar_cliente_nao_ha_modal(): void
    {
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'tomador_documento' => '', 'cliente_id' => 77,
            ])],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertDontSee('quickEditClientModal');
    }

    /**
     * A tela tem duas colunas com papeis distintos: a esquerda e' onde se OPERA
     * a nota (dados para copiar, registro, guarda de arquivos, cancelamento) e
     * a direita e' o visor do documento.
     *
     * Vale um teste porque a arrumacao e' pedido explicito do dono do sistema e
     * nao se ve num `assertSee`: qualquer bloco novo colado no fim do arquivo
     * cai na coluna errada sem quebrar nada visivel.
     */
    public function test_coluna_da_esquerda_opera_a_nota_e_a_direita_mostra_o_documento(): void
    {
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'status' => 'emitido', 'numero' => '3', 'tem_xml' => true, 'tem_pdf' => false,
            ])],
        ]);

        $html = $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->getContent();

        [$esquerda, $direita] = $this->colunas((string) $html);

        foreach (['Dados para o portal', 'Nota registrada', 'Guardar arquivos do portal', 'Cancelar a nota'] as $bloco) {
            $this->assertStringContainsString($bloco, $esquerda, $bloco.' saiu da coluna da esquerda');
            $this->assertStringNotContainsString($bloco, $direita, $bloco.' vazou para a coluna do documento');
        }

        $this->assertStringContainsString('DANFSe (gerado do XML)', $direita);
        $this->assertStringNotContainsString('DANFSe (gerado do XML)', $esquerda);
    }

    /**
     * Divide o HTML da tela nas duas colunas do bloco de conteudo.
     *
     * @return array{0: string, 1: string}
     */
    private function colunas(string $html): array
    {
        $inicio = strpos($html, '<div class="row g-3">');
        $this->assertNotFalse($inicio, 'a linha de colunas da tela sumiu');

        $corpo = substr($html, $inicio);
        $partes = preg_split('#<div class="col-12 col-lg-\d+">#', $corpo);

        $this->assertNotFalse($partes);
        $this->assertCount(3, $partes, 'a tela deixou de ter exatamente duas colunas');

        return [$partes[1], $partes[2]];
    }

    /**
     * "Mais ações" junta o que se faz com uma nota já emitida e que antes exigia
     * caçar link pela tela — ou não existia, como copiar a chave de 50 dígitos.
     */
    public function test_mais_acoes_reune_as_acoes_da_nota_emitida(): void
    {
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'status' => 'emitido', 'numero' => '3', 'tem_xml' => true,
                'chave' => str_repeat('1', 50),
            ])],
        ]);

        $html = $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('Mais ações')
            ->assertSee('Baixar DANFSe')
            ->assertSee('Baixar XML')
            ->assertSee('Imprimir DANFSe')
            ->assertSee('Copiar chave de acesso')
            ->assertSee('Consultar no portal nacional')
            ->getContent();

        // A consulta publica aponta para o MESMO endereco do QR Code do DANFSe
        // (NT-008, item 2.4.3) — dois enderecos diferentes para a mesma nota
        // seria um deles errado.
        $this->assertStringContainsString(
            'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&amp;chave='.str_repeat('1', 50),
            (string) $html
        );
    }

    /**
     * Nota nova é caminho de quem cancelou. Com nota válida o botão não aparece:
     * duas notas vivas para o mesmo serviço é o erro caro deste fluxo.
     */
    public function test_emitir_nova_nota_aparece_na_nota_cancelada(): void
    {
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'status' => 'cancelado', 'numero' => '3', 'motivo_cancelamento' => 'valor errado',
            ])],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('Emitir nova nota')
            ->assertSee(route('fiscal.documentos.novo', 10), false);
    }

    public function test_emitir_nova_nota_nao_aparece_com_nota_valida(): void
    {
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'status' => 'emitido', 'numero' => '3',
            ])],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertDontSee('Emitir nova nota');
    }

    /**
     * O envio abre com o contato do cadastro preenchido, mas o campo é livre —
     * cliente que trocou de e-mail não pode travar o envio até alguém lembrar
     * de atualizar o cadastro.
     */
    public function test_envio_ao_cliente_vem_preenchido_com_o_contato_cadastrado(): void
    {
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'status' => 'emitido', 'numero' => '3', 'tem_xml' => true,
            ])],
        ]);

        $html = $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('Enviar a nota ao cliente')
            ->assertSee('Dá para trocar por outro só neste envio')
            ->assertSee(route('fiscal.documentos.envio', 7), false)
            ->getContent();

        // Com telefone no cadastro, o WhatsApp vem selecionado e o destino ja'
        // preenchido — o caminho de um clique.
        $this->assertStringContainsString('value="(22) 99999-8888"', (string) $html);
        $this->assertMatchesRegularExpression('/id="canal-whatsapp"[^>]*checked/', (string) $html);
    }

    /**
     * A tela diz o que vai anexado, e isso muda com o tomador: pessoa jurídica
     * recebe o XML junto; pessoa física, não.
     */
    public function test_a_tela_diz_que_o_xml_so_acompanha_nota_de_cnpj(): void
    {
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'status' => 'emitido', 'numero' => '3', 'tem_xml' => true,
                'tomador_documento' => '52998224725',
            ])],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('só acompanha nota de cliente com CNPJ');
    }

    public function test_a_tela_anuncia_os_dois_anexos_para_tomador_com_cnpj(): void
    {
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'status' => 'emitido', 'numero' => '3', 'tem_xml' => true,
                'tomador_documento' => '72063654001309',
            ])],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('que o contador do')
            ->assertDontSee('só acompanha nota de cliente com CNPJ');
    }

    /**
     * Sem contato nenhum no cadastro a tela não pode simplesmente sumir com o
     * envio: ela diz o que falta e deixa digitar.
     */
    public function test_envio_explica_quando_o_cliente_nao_tem_contato(): void
    {
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'status' => 'emitido', 'numero' => '3', 'tem_xml' => true,
                'contatos' => ['email' => '', 'whatsapp' => ''],
            ])],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('Enviar a nota ao cliente')
            ->assertSee('não tem e-mail nem telefone no cadastro');
    }

    public function test_desenha_o_danfse_quando_so_ha_xml(): void
    {
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'status' => 'emitido', 'numero' => '3', 'tem_xml' => true, 'tem_pdf' => false,
            ])],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('DANFSe (gerado do XML)')
            ->assertSee('no layout da NT-008')
            ->assertSee(route('fiscal.documentos.danfse', 7), false);
    }

    public function test_pdf_oficial_tem_precedencia_sobre_o_desenhado(): void
    {
        // Com os dois, mostra o do portal — nao por valer mais (o gerado aqui
        // segue a NT-008, que e' justamente a norma que passa essa geracao para
        // os ERPs), mas por ser o arquivo que o operador ja' tem em maos.
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'status' => 'emitido', 'numero' => '3', 'tem_xml' => true, 'tem_pdf' => true,
            ])],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('DANFSe (baixado do portal)')
            ->assertDontSee('DANFSe (gerado do XML)');
    }

    public function test_exibe_o_danfse_oficial_quando_ha_pdf_anexado(): void
    {
        // Quando o operador anexou o PDF do portal, e' ele que aparece: os
        // dois sao DANFSe, e o anexado e' o que ele ja' baixou.
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'status' => 'emitido', 'numero' => '2', 'tem_pdf' => true,
            ])],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('DANFSe (baixado do portal)')
            ->assertSee(route('fiscal.documentos.arquivo.download', [7, 'pdf']), false);
    }

    public function test_diz_onde_achar_cada_campo_no_danfse(): void
    {
        // A queixa foi "não tem instruções claras": o DANFSe traz DOIS números
        // (o da NFS-e e o da DPS) e o rótulo antigo dizia só "Número".
        $this->fakeApi(['orders/10/documento-fiscal' => ['documento' => $this->documento()]]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('Como emitir, passo a passo')
            ->assertSee('Número da NFS-e')
            ->assertSee('Não é o "número da DPS".', false)
            ->assertSee('Série da DPS');
    }

    public function test_avisa_quando_o_tomador_nao_tem_documento(): void
    {
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento(['tomador_documento' => ''])],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('Este cliente não tem CPF/CNPJ cadastrado.');
    }

    public function test_aviso_de_tomador_sem_documento_leva_ao_cadastro(): void
    {
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'tomador_documento' => '', 'cliente_id' => 77,
            ])],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar'], 'clientes' => ['editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('Editar cliente')
            ->assertSee(route('clients.edit', 77), false);
    }

    public function test_documento_emitido_troca_o_formulario_pela_guarda_de_arquivos(): void
    {
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'status' => 'emitido', 'numero' => '000123', 'chave' => 'ABC123',
            ])],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('Nota registrada')
            ->assertSee('Guardar arquivos do portal')
            ->assertDontSee('Importar o XML da nota');
    }

    public function test_o_menu_mostra_a_secao_fiscal(): void
    {
        // A entrega anterior criou as rotas e esqueceu o menu: as telas
        // existiam e ninguem achava. Este teste e' o que impede a repeticao.
        $this->fakeApi(['fiscal/pendentes*' => ['ordens' => [], 'total' => 0]]);

        $this->withSession($this->desktopSession(['os' => ['visualizar']]))
            ->get('/fiscal/pendentes')
            ->assertOk()
            ->assertSee('Fiscal')
            ->assertSee('Notas pendentes');
    }

    public function test_prontidao_nao_rouba_o_fallback_de_permissao_de_clientes(): void
    {
        // A secao Fiscal precisa vir DEPOIS de Cadastros:
        // `firstAllowedRouteName()` percorre o menu em ordem, e "Prontidão
        // fiscal" (modulo `clientes`) roubaria de `clients.index` o papel de
        // destino de fallback.
        $this->fakeApi([]);

        $this->withSession($this->desktopSession(['clientes' => ['visualizar']]))
            ->get('/usuarios')
            ->assertRedirect(route('clients.index'));
    }

    public function test_sem_permissao_de_editar_nao_abre_a_pagina_da_nota(): void
    {
        $this->fakeApi(['orders/10/documento-fiscal' => ['documento' => $this->documento()]]);

        $this->withSession($this->desktopSession(['os' => ['visualizar']]))
            ->get('/fiscal/os/10/nota')
            ->assertRedirect()
            ->assertSessionHas('error', 'Você não tem permissão para acessar este recurso.');
    }

    public function test_baixa_avisa_quando_o_cliente_nao_tem_documento(): void
    {
        // O aviso precisa estar na BAIXA, nao so' na tela de emissao: la' a OS
        // ja' esta' fechada e o cliente ja' foi embora com o equipamento. Aqui
        // ainda da' pra pedir o CPF.
        $this->fakeApi([
            'orders/10' => ['order' => $this->ordemParaBaixa(['cliente' => [
                'id' => 77, 'nome_razao' => 'Otavio Rosa', 'cpf_cnpj' => '',
            ]])],
            'orders/10/closure' => $this->metadadosDeBaixa(),
        ]);

        $this->withSession($this->desktopSession([
            'os' => ['visualizar', 'editar'],
            'clientes' => ['editar'],
        ]))
            ->get('/os/10/baixa')
            ->assertOk()
            ->assertSee('Este cliente não tem CPF/CNPJ cadastrado.')
            // Apontar a falta sem oferecer o conserto vira reclamacao, nao acao.
            ->assertSee('Preencher CPF/CNPJ')
            ->assertSee(route('clients.edit', 77), false);
    }

    public function test_baixa_de_cliente_com_documento_nao_mostra_o_aviso(): void
    {
        $this->fakeApi([
            'orders/10' => ['order' => $this->ordemParaBaixa(['cliente' => [
                'id' => 77, 'nome_razao' => 'Otavio Rosa', 'cpf_cnpj' => '529.982.247-25',
            ]])],
            'orders/10/closure' => $this->metadadosDeBaixa(),
        ]);

        $this->withSession($this->desktopSession([
            'os' => ['visualizar', 'editar'],
            'clientes' => ['editar'],
        ]))
            ->get('/os/10/baixa')
            ->assertOk()
            ->assertDontSee('Este cliente não tem CPF/CNPJ cadastrado.');
    }

    public function test_aviso_da_baixa_sem_permissao_de_editar_cliente_nao_vira_link(): void
    {
        $this->fakeApi([
            'orders/10' => ['order' => $this->ordemParaBaixa(['cliente' => [
                'id' => 77, 'nome_razao' => 'Otavio Rosa', 'cpf_cnpj' => '',
            ]])],
            'orders/10/closure' => $this->metadadosDeBaixa(),
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/os/10/baixa')
            ->assertOk()
            ->assertSee('Este cliente não tem CPF/CNPJ cadastrado.')
            ->assertDontSee(route('clients.edit', 77), false);
    }

    public function test_baixa_entregue_e_paga_leva_para_a_emissao_da_nota(): void
    {
        // A nota sai DEPOIS de encerrar, porque referencia uma OS fechada.
        // Emitir dentro do assistente deixaria nota para OS que pode não fechar.
        $this->fakeApi([]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->post('/os/10/baixa', [
                'classificacao_baixa' => 'baixa',
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-01',
                'emitir_nota_fiscal' => '1',
            ])
            ->assertRedirect(route('fiscal.nota', 10));
    }

    public function test_baixa_com_xml_registra_a_nota_no_mesmo_ato(): void
    {
        $this->fakeApi([]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->post('/os/10/baixa', [
                'classificacao_baixa' => 'baixa',
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-01',
                'nota_fiscal_xml' => new \Illuminate\Http\UploadedFile(
                    base_path('tests/Fixtures/nfse-real-mei.xml'),
                    'nfse.xml',
                    'application/xml',
                    null,
                    true
                ),
            ])
            ->assertRedirect(route('fiscal.nota', 10))
            ->assertSessionHas('success');
    }

    public function test_devolucao_com_xml_nao_registra_nota(): void
    {
        // A guarda nao pode depender do JS: trocar para devolucao depois de
        // escolher o arquivo nao pode gerar nota.
        $this->fakeApi([]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->post('/os/10/baixa', [
                'classificacao_baixa' => 'baixa',
                'encerrar_como' => 'devolvido_sem_reparo',
                'data_entrega' => '2026-09-01',
                'nota_fiscal_xml' => new \Illuminate\Http\UploadedFile(
                    base_path('tests/Fixtures/nfse-real-mei.xml'), 'nfse.xml', 'application/xml', null, true
                ),
            ])
            ->assertRedirect(route('orders.show', 10));
    }

    public function test_baixa_sem_marcar_a_nota_volta_para_a_os(): void
    {
        $this->fakeApi([]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->post('/os/10/baixa', [
                'classificacao_baixa' => 'baixa',
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-01',
            ])
            ->assertRedirect(route('orders.show', 10));
    }

    public function test_devolucao_sem_reparo_nao_leva_para_a_emissao(): void
    {
        // Devolvido/descartado não geram nota: mesmo com o campo marcado, o
        // servidor não desvia — a guarda não pode depender só do JS da tela.
        $this->fakeApi([]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->post('/os/10/baixa', [
                'classificacao_baixa' => 'baixa',
                'encerrar_como' => 'devolvido_sem_reparo',
                'data_entrega' => '2026-09-01',
                'emitir_nota_fiscal' => '1',
            ])
            ->assertRedirect(route('orders.show', 10));
    }

    /**
     * OS aberta como a tela de baixa a recebe (GET /orders/{id}).
     *
     * @param  array<string, mixed>  $sobrescreve
     * @return array<string, mixed>
     */
    private function ordemParaBaixa(array $sobrescreve = []): array
    {
        return array_replace([
            'id' => 10,
            'numero_os' => 'OS2609001',
            'status' => 'aguardando_reparo',
            'status_nome' => 'Aguardando Reparo',
            'estado_fluxo' => 'em_atendimento',
            'valor_final' => 420.0,
            'cliente_id' => 77,
            'cliente_nome' => 'Otavio Rosa',
            'equipamento_id' => 3,
        ], $sobrescreve);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadadosDeBaixa(): array
    {
        return [
            'cliente_telefone' => '',
            'opcoes_encerramento' => [
                ['codigo' => 'entregue_reparado_pago', 'nome' => 'Entregue - Reparado e Pago'],
                ['codigo' => 'devolvido_sem_reparo', 'nome' => 'Devolvido sem reparo'],
            ],
            'financeiro' => [
                'valor_titulo' => 420.0,
                'valor_movimentado' => 0,
                'valor_aberto' => 420.0,
                'total_movimentos' => 0,
                'status_resolvido' => null,
                'percentual_quitado' => 0,
            ],
            'custo_summary' => ['pecas' => 0, 'servicos' => 0, 'total' => 0],
            'retorno_padrao' => now()->addDays(180)->toDateString(),
            'cartao' => ['operadoras' => [], 'bandeiras' => [], 'taxas' => []],
            'contas_financeiras' => ['contas' => [], 'contas_padrao' => []],
            'status_pagamento_pendente' => [
                'codigo' => 'entregue_pagamento_pendente',
                'nome' => 'Entregue - Pendência Financeira',
            ],
            'status_sem_reparo' => ['devolvido_sem_reparo', 'descartado'],
            'status_entregue' => 'entregue_reparado_pago',
        ];
    }

    /**
     * @param  array<string, mixed>  $sobrescreve
     * @return array<string, mixed>
     */
    /**
     * O botão de anexar XML/PDF era um rótulo com o nome do formato — a
     * confirmação de envio ficava implícita nele, sem nenhum sinal de "clique
     * aqui para enviar". Agora tem verbo, ícone e começa desabilitado até um
     * arquivo ser escolhido, o que só um script no navegador liga de volta.
     */
    public function test_botao_de_anexar_arquivo_comeca_desabilitado_e_tem_verbo(): void
    {
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento([
                'status' => 'emitido', 'numero' => '2', 'tem_xml' => false, 'tem_pdf' => false,
            ])],
        ]);

        $html = (string) $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('Enviar XML')
            ->assertSee('Enviar PDF')
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-input-arquivo-fiscal>\s*<button type="submit"[^>]*\bdisabled\b[^>]*data-botao-arquivo-fiscal/s',
            $html,
            'o botao de enviar XML/PDF deveria comecar desabilitado, ate' . " o operador escolher um arquivo"
        );
    }

    private function documento(array $sobrescreve = []): array
    {
        return array_replace([
            'id' => 7,
            'tipo' => 'nfse',
            'status' => 'rascunho',
            'os_id' => 10,
            'cliente_id' => 77,
            'tomador_nome' => 'João da Silva',
            'tomador_documento' => '52998224725',
            'discriminacao' => "Ordem de servico OS2609001\nTroca de tela — R$ 300,00",
            'valor_servicos' => 300.0,
            'valor_pecas' => 120.0,
            'valor_total' => 420.0,
            'numero' => '',
            'serie' => '',
            'chave' => '',
            'motivo_cancelamento' => '',
            'motivo_rejeicao' => '',
            'tem_xml' => false,
            'tem_pdf' => false,
            'contatos' => ['email' => 'cliente@exemplo.com', 'whatsapp' => '(22) 99999-8888'],
        ], $sobrescreve);
    }

    public function test_lista_notas_emitidas_com_totais_e_os(): void
    {
        $this->fakeApi([
            'fiscal/documentos*' => [
                'data' => ['documentos' => [
                    $this->documento([
                        'id' => 7, 'status' => 'emitido', 'numero' => '3', 'serie' => '70000',
                        'chave' => '33052082234129526000198000000000000226086919348703',
                        'numero_os' => 'OS2609001', 'emitido_em' => '2026-08-27T10:00:00-03:00',
                        'tem_xml' => true,
                    ]),
                    $this->documento([
                        'id' => 8, 'status' => 'cancelado', 'numero' => '2', 'serie' => '70000',
                        'numero_os' => 'OS2609002', 'motivo_cancelamento' => 'Cliente desistiu',
                    ]),
                ]],
                'meta' => [
                    'pagination' => ['current_page' => 1, 'per_page' => 20, 'total' => 2, 'last_page' => 1, 'from' => 1, 'to' => 2],
                    'totais' => ['quantidade' => 2, 'emitidas' => 1, 'valor' => 420.0],
                ],
            ],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/notas')
            ->assertOk()
            // O numero da OS, e nao o `os_id`: e' o que existe no papel.
            ->assertSee('OS2609001')
            ->assertSee('OS2609002')
            ->assertSee('Emitida')
            ->assertSee('Cancelada')
            // A soma conta so' o emitido — cancelada nao virou receita.
            ->assertSee('R$ 420,00')
            ->assertSee('1 emitidas');
    }

    public function test_nota_sem_pdf_oferece_o_danfse_do_xml(): void
    {
        // Com XML e sem PDF do portal, o caminho para ver a nota e' o DANFSe
        // desenhado. Sem XML nao ha' o que oferecer, e a lista precisa dizer.
        $this->fakeApi([
            'fiscal/documentos*' => [
                'data' => ['documentos' => [
                    $this->documento(['id' => 7, 'status' => 'emitido', 'numero' => '3', 'tem_xml' => true, 'tem_pdf' => false]),
                    $this->documento(['id' => 9, 'status' => 'emitido', 'numero' => '4', 'tem_xml' => false, 'tem_pdf' => false]),
                ]],
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 20, 'total' => 2, 'last_page' => 1, 'from' => 1, 'to' => 2], 'totais' => []],
            ],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar']]))
            ->get('/fiscal/notas')
            ->assertOk()
            ->assertSee(route('fiscal.documentos.danfse', 7), false)
            ->assertSee('sem XML');
    }

    public function test_lista_de_notas_repassa_os_filtros_a_api(): void
    {
        $this->fakeApi([
            'fiscal/documentos*' => [
                'data' => ['documentos' => []],
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1], 'totais' => []],
            ],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar']]))
            ->get('/fiscal/notas?busca=OS2609001&status=emitido&de=2026-08-01&ate=2026-08-31')
            ->assertOk();

        Http::assertSent(static function (ClienteHttpRequest $requisicao): bool {
            if (! str_contains($requisicao->url(), '/fiscal/documentos')) {
                return false;
            }

            // Se o filtro nao chegar a' API, a tela mostra tudo e o operador
            // acha que filtrou.
            return str_contains($requisicao->url(), 'busca=OS2609001')
                && str_contains($requisicao->url(), 'status=emitido')
                && str_contains($requisicao->url(), 'de=2026-08-01')
                && str_contains($requisicao->url(), 'ate=2026-08-31');
        });
    }

    public function test_lista_vazia_explica_onde_a_nota_nasce(): void
    {
        $this->fakeApi([
            'fiscal/documentos*' => [
                'data' => ['documentos' => []],
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1], 'totais' => []],
            ],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar']]))
            ->get('/fiscal/notas')
            ->assertOk()
            ->assertSee('Nenhuma nota encontrada')
            ->assertSee(route('fiscal.pendentes'), false);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rotas
     */
    private function fakeApi(array $rotas): void
    {
        $fakes = [
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ];

        foreach ($rotas as $rota => $dados) {
            // Rota paginada declara o envelope inteiro (`data` + `meta`); as
            // demais passam so' o conteudo de `data`, que e' o caso comum.
            $envelope = array_key_exists('data', $dados) && array_key_exists('meta', $dados);

            $fakes['http://127.0.0.1:8000/api/v1/' . $rota] = Http::response([
                'status' => 'success',
                'data' => $envelope ? $dados['data'] : $dados,
                'error' => null,
                'meta' => $envelope ? $dados['meta'] : [],
            ], 200);
        }

        // Coringa por último: `Http::fake` casa na ordem, então as rotas
        // específicas acima vencem. Sem isto, qualquer chamada não prevista
        // (a baixa da OS, por exemplo) vaza para o backend real e o teste
        // falha com erro de conexão em vez de dizer o que quebrou.
        $fakes['*'] = Http::response([
            'status' => 'success', 'data' => [], 'error' => null, 'meta' => [],
        ], 200);

        Http::fake($fakes);
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeNotificationsPayload(): array
    {
        return [
            'status' => 'success',
            'data' => ['items' => [], 'unread_count' => 0],
            'error' => null,
            'meta' => [
                'pagination' => ['current_page' => 1, 'per_page' => 6, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0],
            ],
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $permissions
     * @return array<string, mixed>
     */
    private function desktopSession(array $permissions): array
    {
        return [
            'desktop_auth' => [
                'token' => 'desktop-session-token',
                'synced_at' => time(),
                'user' => $this->fakeUser([
                    'permissions' => $permissions,
                    'modules' => array_keys($permissions),
                ]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fakeUser(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 99,
            'nome' => 'Usuário de Teste',
            'email' => 'usuario@teste.local',
            'perfil' => 'admin',
            'group' => ['id' => 1, 'nome' => 'Administrador', 'descricao' => 'Grupo completo', 'sistema' => true],
            'modules' => [],
            'permissions' => [],
            'foto' => '',
            'ativo' => true,
        ], $overrides);
    }
}
