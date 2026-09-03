<?php

namespace Tests\Feature\Api\V1;

use App\Models\DocumentoFiscal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Modo assistido de emissão (fase 042).
 *
 * O que estes testes protegem é a regra de "uma nota por serviço": rascunho
 * duplicado vira nota duplicada, e nota duplicada é problema com o fisco, não
 * bug de tela.
 */
class DocumentoFiscalTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        // A lista de pendentes junta com `os_status` para saber o que e' status
        // final: sem o catalogo semeado o join nao casa e a lista sai vazia.
        $this->seedOrderCatalog();

        // Este arquivo testa o FLUXO do documento fiscal, nao a conferencia de
        // assinatura em si (isso e' NfseXmlImporterTest) — a fixture usada
        // aqui teve a assinatura removida antes de ser versionada (ver
        // tests/Fixtures/nfse/ORIGEM.md), entao exigi-la aqui so' obrigaria
        // assinar a fixture em toda importacao sem testar nada a mais.
        config()->set('fiscal.nfse.exigir_assinatura_xml', false);

        // O fiscal e' modulo proprio de RBAC. As permissoes aqui espelham o
        // que a migration `create_fiscal_rbac_module` semeia em producao:
        // `fiscal:criar` e `fiscal:excluir` nascem de quem tem `os:editar`.
        $this->grantGroupPermissions(1, [
            'os' => ['visualizar', 'criar', 'editar'],
            'fiscal' => ['visualizar', 'criar', 'excluir'],
        ]);
        $this->grantGroupPermissions(3, [
            'os' => ['visualizar'],
            'fiscal' => ['visualizar'],
        ]);
    }

    public function test_monta_rascunho_com_tomador_e_discriminacao(): void
    {
        $os = $this->criarOrdem();

        $resposta = $this->comToken()
            ->postJson('/api/v1/orders/' . $os . '/documento-fiscal')
            ->assertOk();

        $resposta
            ->assertJsonPath('data.documento.status', 'rascunho')
            ->assertJsonPath('data.documento.tipo', 'nfse')
            ->assertJsonPath('data.documento.tomador_nome', 'Cliente Fiscal')
            // Documento normalizado: e' assim que ele vai para o portal.
            ->assertJsonPath('data.documento.tomador_documento', '52998224725')
            ->assertJsonPath('data.documento.valor_servicos', 300.0)
            ->assertJsonPath('data.documento.valor_pecas', 120.0);

        $discriminacao = (string) $resposta->json('data.documento.discriminacao');

        $this->assertStringContainsString('OS2609001', $discriminacao);
        $this->assertStringContainsString('Troca de tela', $discriminacao);
        // Peca NAO entra na discriminacao da NFS-e: e' mercadoria, sai por
        // NF-e/NFC-e na SEFAZ estadual.
        $this->assertStringNotContainsString('Tela LCD', $discriminacao);
    }

    public function test_rascunho_e_idempotente(): void
    {
        // Dois rascunhos da mesma OS levariam a duas notas do mesmo servico.
        $os = $this->criarOrdem();

        $primeiro = (int) $this->comToken()->postJson('/api/v1/orders/' . $os . '/documento-fiscal')->json('data.documento.id');
        $segundo = (int) $this->comToken()->postJson('/api/v1/orders/' . $os . '/documento-fiscal')->json('data.documento.id');

        $this->assertSame($primeiro, $segundo);
        $this->assertSame(1, DocumentoFiscal::query()->where('os_id', $os)->count());
    }

    public function test_abrir_a_tela_de_uma_os_ja_emitida_nao_cria_rascunho_novo(): void
    {
        // Defeito real: cada visita depois de emitir criava um rascunho novo, a
        // tela dizia "Rascunho" para uma OS que ja' tinha nota, e reimportar o
        // mesmo XML batia na trava de numero duplicado.
        $os = $this->criarOrdem('OS2609030');
        $documento = $this->rascunho($os);

        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/emissao', ['numero' => '000900'])
            ->assertOk();

        $this->comToken()
            ->postJson('/api/v1/orders/' . $os . '/documento-fiscal')
            ->assertOk()
            ->assertJsonPath('data.documento.id', $documento)
            ->assertJsonPath('data.documento.status', 'emitido');

        $this->assertSame(1, DocumentoFiscal::query()->where('os_id', $os)->count());
    }

    public function test_documento_cancelado_tambem_nao_vira_rascunho_novo(): void
    {
        // Cancelado continua existindo no fisco: a tela tem de mostra-lo, e a
        // substituta e' um registro criado deliberadamente, nao por visita.
        $os = $this->criarOrdem('OS2609031');
        $documento = $this->rascunho($os);

        $this->comToken()->postJson('/api/v1/fiscal/documentos/' . $documento . '/emissao', ['numero' => '000901'])->assertOk();
        $this->comToken()->postJson('/api/v1/fiscal/documentos/' . $documento . '/cancelamento', ['motivo_cancelamento' => 'erro'])->assertOk();

        $this->comToken()
            ->postJson('/api/v1/orders/' . $os . '/documento-fiscal')
            ->assertOk()
            ->assertJsonPath('data.documento.status', 'cancelado');
    }

    public function test_numero_duplicado_diz_em_qual_os_ja_esta(): void
    {
        $primeiro = $this->rascunho();
        $this->comToken()->postJson('/api/v1/fiscal/documentos/' . $primeiro . '/emissao', ['numero' => '000123', 'serie' => '1'])->assertOk();

        $segundo = $this->rascunho($this->criarOrdem('OS2609032'));

        $resposta = $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $segundo . '/emissao', ['numero' => '000123', 'serie' => '1'])
            ->assertStatus(422);

        $this->assertStringContainsString('já foi importada', $resposta->json('error.details.numero.0'));
    }

    public function test_registra_o_retorno_do_portal(): void
    {
        $documento = $this->rascunho();

        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/emissao', [
                'numero' => '000123',
                'serie' => '1',
                'chave' => 'abc123def456',
            ])
            ->assertOk()
            ->assertJsonPath('data.documento.status', 'emitido')
            ->assertJsonPath('data.documento.numero', '000123')
            ->assertJsonPath('data.documento.chave', 'ABC123DEF456');

        $this->assertDatabaseHas('documentos_fiscais', [
            'id' => $documento,
            'status' => 'emitido',
            'numero' => '000123',
        ]);
    }

    public function test_recusa_numero_duplicado_na_mesma_serie(): void
    {
        $primeiro = $this->rascunho();
        $this->comToken()->postJson('/api/v1/fiscal/documentos/' . $primeiro . '/emissao', [
            'numero' => '000123', 'serie' => '1',
        ])->assertOk();

        $segundo = $this->rascunho($this->criarOrdem('OS2609002'));

        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $segundo . '/emissao', ['numero' => '000123', 'serie' => '1'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['numero']]]);
    }

    public function test_nao_registra_emissao_duas_vezes(): void
    {
        $documento = $this->rascunho();

        $this->comToken()->postJson('/api/v1/fiscal/documentos/' . $documento . '/emissao', ['numero' => '000123'])->assertOk();

        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/emissao', ['numero' => '000999'])
            ->assertStatus(422);
    }

    public function test_rejeicao_permite_nova_tentativa(): void
    {
        // Rejeitado continua sendo rascunho para efeito de retomada: o portal
        // recusou, ninguem emitiu nada.
        $documento = $this->rascunho();

        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/rejeicao', ['motivo_rejeicao' => 'CPF do tomador invalido'])
            ->assertOk()
            ->assertJsonPath('data.documento.status', 'rejeitado');

        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/emissao', ['numero' => '000200'])
            ->assertOk()
            ->assertJsonPath('data.documento.status', 'emitido')
            ->assertJsonPath('data.documento.motivo_rejeicao', '');
    }

    public function test_cancela_documento_emitido_preservando_o_numero(): void
    {
        $documento = $this->rascunho();
        $this->comToken()->postJson('/api/v1/fiscal/documentos/' . $documento . '/emissao', ['numero' => '000123'])->assertOk();

        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/cancelamento', ['motivo_cancelamento' => 'Servico nao executado'])
            ->assertOk()
            ->assertJsonPath('data.documento.status', 'cancelado')
            // O numero NAO e' liberado: o documento continua existindo no fisco.
            ->assertJsonPath('data.documento.numero', '000123');
    }

    public function test_nao_cancela_rascunho(): void
    {
        $documento = $this->rascunho();

        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/cancelamento', ['motivo_cancelamento' => 'qualquer'])
            ->assertStatus(422);
    }

    public function test_sem_permissao_de_editar_nao_monta_rascunho(): void
    {
        $os = $this->criarOrdem();

        $usuario = $this->createUserRecord([
            'nome' => 'Atendente', 'email' => 'atendente.fiscal@example.com', 'perfil' => 'atendente', 'grupo_id' => 3,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token($usuario->email))
            ->postJson('/api/v1/orders/' . $os . '/documento-fiscal')
            ->assertForbidden();
    }

    public function test_lista_os_pendentes_de_nota(): void
    {
        $comNota = $this->criarOrdem('OS2609010');
        $semNota = $this->criarOrdem('OS2609011');

        $documento = $this->rascunho($comNota);
        $this->comToken()->postJson('/api/v1/fiscal/documentos/' . $documento . '/emissao', ['numero' => '000500'])->assertOk();

        $resposta = $this->comToken()->getJson('/api/v1/fiscal/pendentes')->assertOk();

        $numeros = array_column($resposta->json('data.ordens'), 'numero_os');

        $this->assertContains('OS2609011', $numeros);
        $this->assertNotContains('OS2609010', $numeros);
        $this->assertSame($semNota, (int) $resposta->json('data.ordens.0.os_id'));
    }

    public function test_cancelar_devolve_a_os_para_a_fila_de_pendentes(): void
    {
        // Cancelou, ninguem tem nota valida: a OS volta a aparecer.
        $os = $this->criarOrdem('OS2609012');
        $documento = $this->rascunho($os);
        $this->comToken()->postJson('/api/v1/fiscal/documentos/' . $documento . '/emissao', ['numero' => '000600'])->assertOk();
        $this->comToken()->postJson('/api/v1/fiscal/documentos/' . $documento . '/cancelamento', ['motivo_cancelamento' => 'Erro no valor'])->assertOk();

        $numeros = array_column($this->comToken()->getJson('/api/v1/fiscal/pendentes')->json('data.ordens'), 'numero_os');

        $this->assertContains('OS2609012', $numeros);
    }

    public function test_anexa_o_xml_do_portal(): void
    {
        Storage::fake('local');

        // Tomador e chave batendo com a fixture real: "Registrar a mao" com o
        // numero/chave certos, depois "Guardar arquivos do portal" com o XML
        // certo — as duas conferencias (chave e tomador) tem de deixar passar
        // um arquivo que realmente e' o desta nota.
        $documento = $this->rascunho($this->criarOrdem('OS2609040', '72063654001309'));
        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/emissao', [
                'numero' => '2',
                'serie' => '70000',
                'chave' => '33052082234129526000198000000000000226086919348703',
            ])
            ->assertOk();

        $this->comToken()
            ->post('/api/v1/fiscal/documentos/' . $documento . '/arquivo', [
                'formato' => 'xml',
                'arquivo' => new UploadedFile(
                    base_path('tests/Fixtures/nfse/nfse-real-mei.xml'), 'nfse.xml', 'application/xml', null, true
                ),
            ])
            ->assertOk()
            ->assertJsonPath('data.documento.tem_xml', true);

        $registro = DocumentoFiscal::query()->findOrFail($documento);

        $this->assertNotNull($registro->xml_arquivo);
        $this->assertSame(64, strlen((string) $registro->xml_hash_sha256));
        Storage::disk('local')->assertExists((string) $registro->xml_arquivo);

        // Na arvore da OS: e' o que faz o arquivo aparecer no Gerenciador
        // dentro de "Documentos de OS", ja' vinculado, sem codigo de vinculo.
        $this->assertStringStartsWith(
            'private/os_documentos/'.$registro->os_id.'/fiscal/',
            (string) $registro->xml_arquivo
        );
    }

    /**
     * O achado que gerou esta correção: uma OS de um cliente ficou com o XML
     * de outro anexado — "Guardar arquivos do portal" (usado depois da emissão
     * manual) não conferia nada do conteúdo, ao contrário de "Importar o XML
     * da nota" (antes de emitir), que já checava o tomador.
     */
    public function test_recusa_anexar_xml_de_outro_cliente(): void
    {
        Storage::fake('local');

        // OS de um CPF; o XML anexado e' o da fixture, cujo tomador e' outro
        // CNPJ inteiramente — o caso real que apareceu na tela.
        $documento = $this->rascunho($this->criarOrdem('OS2609041', '52998224725'));
        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/emissao', ['numero' => '999'])
            ->assertOk();

        $this->comToken()
            ->post('/api/v1/fiscal/documentos/' . $documento . '/arquivo', [
                'formato' => 'xml',
                'arquivo' => new UploadedFile(
                    base_path('tests/Fixtures/nfse/nfse-real-mei.xml'), 'nfse.xml', 'application/xml', null, true
                ),
            ])
            ->assertStatus(422);

        $this->assertNull(DocumentoFiscal::query()->findOrFail($documento)->xml_arquivo);
    }

    /**
     * Mesmo cliente, nota errada: a checagem de tomador sozinha deixaria isto
     * passar, porque o CPF/CNPJ bate. A chave é a conferência que pega o caso
     * "XML certo, nota errada".
     */
    public function test_recusa_anexar_xml_de_chave_diferente_da_registrada(): void
    {
        Storage::fake('local');

        $documento = $this->rascunho($this->criarOrdem('OS2609042', '72063654001309'));
        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/emissao', [
                'numero' => '2',
                'chave' => str_repeat('9', 50),
            ])
            ->assertOk();

        $resposta = $this->comToken()
            ->post('/api/v1/fiscal/documentos/' . $documento . '/arquivo', [
                'formato' => 'xml',
                'arquivo' => new UploadedFile(
                    base_path('tests/Fixtures/nfse/nfse-real-mei.xml'), 'nfse.xml', 'application/xml', null, true
                ),
            ]);

        $resposta->assertStatus(422);
        $this->assertStringContainsString(
            'de outra nota',
            (string) ($resposta->json('error.details.arquivo.0') ?? $resposta->json('error.message'))
        );
    }

    public function test_nao_anexa_arquivo_em_rascunho(): void
    {
        // O arquivo vem do portal, e o portal so' devolve depois de emitir.
        Storage::fake('local');

        $documento = $this->rascunho();

        $this->comToken()
            ->post('/api/v1/fiscal/documentos/' . $documento . '/arquivo', [
                'formato' => 'xml',
                'arquivo' => UploadedFile::fake()->createWithContent('nfse.xml', '<?xml version="1.0"?><NFSe/>'),
            ])
            ->assertStatus(422);
    }

    public function test_importa_o_xml_e_registra_a_emissao_de_uma_vez(): void
    {
        Storage::fake('local');
        // A OS precisa ser do tomador do XML: a guarda de tomador existe
        // justamente para impedir o contrario.
        $documento = $this->rascunho($this->criarOrdem('OS2609020', '72063654001309'));

        $resposta = $this->comToken()
            ->post('/api/v1/fiscal/documentos/' . $documento . '/importar-xml', [
                'arquivo' => new UploadedFile(
                    base_path('tests/Fixtures/nfse/nfse-real-mei.xml'),
                    'nfse.xml',
                    'application/xml',
                    null,
                    true
                ),
            ])
            ->assertOk();

        $resposta
            ->assertJsonPath('data.documento.status', 'emitido')
            ->assertJsonPath('data.documento.numero', '2')
            ->assertJsonPath('data.documento.serie', '70000')
            ->assertJsonPath('data.documento.chave', '33052082234129526000198000000000000226086919348703')
            // O XML fica guardado no mesmo ato: e' a guarda que a lei pede.
            ->assertJsonPath('data.documento.tem_xml', true)
            // O que foi lido volta para a tela mostrar antes de confiar.
            ->assertJsonPath('data.lido.codigo_tributacao', '310102');
    }

    public function test_recusa_xml_de_outro_tomador(): void
    {
        // Anexar a nota da OS errada e' o erro caro: numero e chave sao
        // legitimos, so' que de outro atendimento.
        Storage::fake('local');
        $documento = $this->rascunho();

        $this->comToken()
            ->post('/api/v1/fiscal/documentos/' . $documento . '/importar-xml', [
                'arquivo' => new UploadedFile(
                    base_path('tests/Fixtures/nfse/nfse-real-mei.xml'),
                    'nfse.xml',
                    'application/xml',
                    null,
                    true
                ),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['arquivo']]]);
    }

    public function test_gera_o_danfse_a_partir_do_xml_guardado(): void
    {
        Storage::fake('local');
        $documento = $this->rascunho($this->criarOrdem('OS2609040', '72063654001309'));

        $this->comToken()
            ->post('/api/v1/fiscal/documentos/' . $documento . '/importar-xml', [
                'arquivo' => new UploadedFile(
                    base_path('tests/Fixtures/nfse/nfse-real-mei.xml'), 'nfse.xml', 'application/xml', null, true
                ),
            ])
            ->assertOk();

        $resposta = $this->comToken()->get('/api/v1/fiscal/documentos/' . $documento . '/danfse');

        $resposta->assertOk();
        $this->assertSame('application/pdf', $resposta->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $resposta->getContent());
    }

    public function test_danfse_sem_xml_explica_o_que_falta(): void
    {
        // Sem XML nao ha' o que desenhar. Devolver PDF vazio seria pior:
        // alguem mandaria ao cliente uma nota em branco.
        $documento = $this->rascunho();

        $this->comToken()
            ->getJson('/api/v1/fiscal/documentos/' . $documento . '/danfse')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DANFSE_SEM_XML');
    }

    public function test_lista_traz_o_numero_da_os_e_soma_so_o_que_esta_emitido(): void
    {
        // Uma emitida, uma cancelada e um rascunho. A lista mostra as tres —
        // cancelada e' historico e o fisco cobra —, mas somar as tres
        // inventaria receita que nunca existiu.
        $emitida = $this->rascunho();
        $this->comToken()->postJson('/api/v1/fiscal/documentos/'.$emitida.'/emissao', [
            'numero' => '000123', 'serie' => '1', 'emitido_em' => '2026-08-27',
        ])->assertOk();

        $cancelada = $this->rascunho($this->criarOrdem('OS2609002'));
        $this->comToken()->postJson('/api/v1/fiscal/documentos/'.$cancelada.'/emissao', [
            'numero' => '000124', 'serie' => '1', 'emitido_em' => '2026-08-28',
        ])->assertOk();
        $this->comToken()->postJson('/api/v1/fiscal/documentos/'.$cancelada.'/cancelamento', [
            'motivo_cancelamento' => 'Cliente desistiu',
        ])->assertOk();

        $this->rascunho($this->criarOrdem('OS2609003'));

        $resposta = $this->comToken()
            ->getJson('/api/v1/fiscal/documentos?status=emitido,cancelado')
            ->assertOk();

        $this->assertCount(2, $resposta->json('data.documentos'));
        // O `os_id` e' chave interna e nao bate com nada que o operador ve.
        $this->assertSame('OS2609002', $resposta->json('data.documentos.0.numero_os'));
        $this->assertSame(2, $resposta->json('meta.totais.quantidade'));
        $this->assertSame(1, $resposta->json('meta.totais.emitidas'));
        $this->assertSame(420.0, $resposta->json('meta.totais.valor'));
    }

    public function test_busca_acha_pela_chave_e_pelo_documento_com_pontuacao(): void
    {
        // O tomador e' guardado normalizado, e a chave costuma ser colada do
        // PDF com pontuacao junto. Buscar como o operador digita tem de achar.
        $documento = $this->rascunho();
        $this->comToken()->postJson('/api/v1/fiscal/documentos/'.$documento.'/emissao', [
            'numero' => '000123', 'serie' => '1', 'chave' => '3305208223412952600019800000',
        ])->assertOk();

        $porChave = $this->comToken()
            ->getJson('/api/v1/fiscal/documentos?busca='.urlencode('3305 2082 2341'))
            ->assertOk();
        $this->assertCount(1, $porChave->json('data.documentos'));

        $porCpf = $this->comToken()
            ->getJson('/api/v1/fiscal/documentos?busca='.urlencode('529.982.247-25'))
            ->assertOk();
        $this->assertCount(1, $porCpf->json('data.documentos'));

        $porOs = $this->comToken()
            ->getJson('/api/v1/fiscal/documentos?busca=OS2609001')
            ->assertOk();
        $this->assertCount(1, $porOs->json('data.documentos'));
    }

    public function test_periodo_filtra_pela_data_de_emissao(): void
    {
        $agosto = $this->rascunho();
        $this->comToken()->postJson('/api/v1/fiscal/documentos/'.$agosto.'/emissao', [
            'numero' => '000123', 'serie' => '1', 'emitido_em' => '2026-08-27',
        ])->assertOk();

        $setembro = $this->rascunho($this->criarOrdem('OS2609002'));
        $this->comToken()->postJson('/api/v1/fiscal/documentos/'.$setembro.'/emissao', [
            'numero' => '000124', 'serie' => '1', 'emitido_em' => '2026-09-02',
        ])->assertOk();

        $resposta = $this->comToken()
            ->getJson('/api/v1/fiscal/documentos?de=2026-09-01&ate=2026-09-30')
            ->assertOk();

        $this->assertCount(1, $resposta->json('data.documentos'));
        $this->assertSame('000124', $resposta->json('data.documentos.0.numero'));
    }

    public function test_status_desconhecido_nao_esvazia_a_lista(): void
    {
        // `whereIn` com lista vazia devolveria zero linhas, e a tela diria
        // "nenhuma nota" para quem so' errou o filtro na URL.
        $documento = $this->rascunho();
        $this->comToken()->postJson('/api/v1/fiscal/documentos/'.$documento.'/emissao', [
            'numero' => '000123', 'serie' => '1',
        ])->assertOk();

        $this->comToken()
            ->getJson('/api/v1/fiscal/documentos?status=inventado')
            ->assertOk()
            ->assertJsonCount(1, 'data.documentos');
    }

    public function test_lista_exige_permissao_de_visualizar_fiscal(): void
    {
        $email = 'sem.fiscal@example.com';
        $this->createUserRecord(['nome' => 'Sem fiscal', 'email' => $email, 'perfil' => 'tecnico', 'grupo_id' => 2]);

        $this->withHeader('Authorization', 'Bearer '.$this->token($email))
            ->getJson('/api/v1/fiscal/documentos')
            ->assertStatus(403);
    }

    // ---- nota nova depois de cancelar -------------------------------

    /**
     * Cancelar devolvia a OS para a fila de pendentes, mas abrir a tela
     * continuava mostrando a nota cancelada — sem caminho para a substituta.
     */
    public function test_abre_documento_novo_depois_de_cancelar(): void
    {
        $os = $this->criarOrdem();
        $documento = $this->rascunho($os);

        $this->comToken()->postJson('/api/v1/fiscal/documentos/' . $documento . '/emissao', ['numero' => '000123'])->assertOk();
        $this->comToken()->postJson('/api/v1/fiscal/documentos/' . $documento . '/cancelamento', ['motivo_cancelamento' => 'valor errado'])->assertOk();

        $novo = (int) $this->comToken()
            ->postJson('/api/v1/orders/' . $os . '/documento-fiscal/novo')
            ->assertOk()
            ->assertJsonPath('data.documento.status', 'rascunho')
            ->json('data.documento.id');

        $this->assertNotSame($documento, $novo);
        $this->assertSame(2, DocumentoFiscal::query()->where('os_id', $os)->count());
    }

    /**
     * OS que nunca teve documento fiscal nenhum: o "novo" cai no fluxo normal.
     *
     * Prende a refatoração que fechou a corrida em `rascunhoDeOrdem()`: o ramo
     * "nunca houve documento" de `novoRascunhoAposCancelamento()` passou a
     * chamar `criarRascunho()` (que NÃO tenta a trava de novo) em vez de
     * `rascunhoDeOrdem()` — chamar o método que já tenta a MESMA trava de
     * dentro dela mesma travaria a requisição até o timeout de 5 segundos.
     */
    public function test_novo_documento_em_os_sem_documento_nenhum_nao_trava(): void
    {
        $os = $this->criarOrdem();

        $inicio = microtime(true);

        $novo = (int) $this->comToken()
            ->postJson('/api/v1/orders/' . $os . '/documento-fiscal/novo')
            ->assertOk()
            ->assertJsonPath('data.documento.status', 'rascunho')
            ->json('data.documento.id');

        // Bem abaixo do timeout de 5s da trava: se tivesse travado nela mesma,
        // a resposta so' viria depois de estourar o bloqueio.
        $this->assertLessThan(2.0, microtime(true) - $inicio);
        $this->assertSame(1, DocumentoFiscal::query()->where('os_id', $os)->count());
        $this->assertGreaterThan(0, $novo);
    }

    /**
     * Com nota emitida e válida, abrir outra ao lado produziria duas notas vivas
     * para o mesmo serviço — que é o erro caro deste fluxo.
     */
    public function test_nao_abre_documento_novo_com_nota_valida(): void
    {
        $os = $this->criarOrdem();
        $documento = $this->rascunho($os);

        $this->comToken()->postJson('/api/v1/fiscal/documentos/' . $documento . '/emissao', ['numero' => '000123'])->assertOk();

        $this->comToken()
            ->postJson('/api/v1/orders/' . $os . '/documento-fiscal/novo')
            ->assertStatus(422);

        $this->assertSame(1, DocumentoFiscal::query()->where('os_id', $os)->count());
    }

    // ---- envio da nota ao cliente ------------------------------------

    /**
     * A tela de envio abre com o contato do cadastro preenchido; sem isto o
     * operador teria de ir buscar o e-mail do cliente em outra tela.
     */
    public function test_traz_os_contatos_do_cliente_para_a_tela_de_envio(): void
    {
        $os = $this->criarOrdem('OS2609002', '11144477735');

        $this->comToken()
            ->postJson('/api/v1/orders/' . $os . '/documento-fiscal')
            ->assertOk()
            // `criarOrdem` cadastra telefone e nao e-mail: o whatsapp vem
            // preenchido e o e-mail vem vazio, sem quebrar a tela.
            ->assertJsonPath('data.documento.contatos.whatsapp', '(11) 95555-0000')
            ->assertJsonPath('data.documento.contatos.email', '');
    }

    public function test_envia_a_nota_por_email_para_o_destino_informado(): void
    {
        Mail::fake();

        $documento = $this->emitidaComXml();

        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/envio', [
                'canal' => 'email',
                'destino' => 'contador@exemplo.com',
            ])
            ->assertOk()
            ->assertJsonPath('data.envio.canal', 'email')
            // O destino volta mascarado: a resposta da API circula em log e em
            // tela, e o contato completo do cliente nao precisa circular junto.
            ->assertJsonPath('data.envio.destino', 'co******@exemplo.com');

        // A prova de que o fluxo terminou e' o registro na timeline da OS —
        // `Mail::html()` nao cria Mailable de classe propria para o fake
        // contar, e o que importa auditar e' que o envio ficou registrado.
        $this->assertDatabaseHas('os_eventos', [
            'categoria' => 'fiscal',
            'tipo' => 'nota_enviada',
        ]);
    }

    /**
     * A ordem dos anexos é a do leitor, não a da importância fiscal.
     *
     * Este caso é o de tomador com CNPJ, que recebe os dois arquivos.
     *
     * Saía o XML na frente carregando a mensagem, e o DANFSe por último e mudo —
     * o cliente recebia primeiro um arquivo que não abre no celular. Agora vai o
     * DANFSe com a mensagem, e o XML atrás com a legenda dele.
     */
    public function test_manda_o_danfse_antes_do_xml_e_explica_cada_anexo(): void
    {
        foreach ([
            'whatsapp_direct_provider' => 'evolution',
            'whatsapp_evolution_url' => 'https://evolution.exemplo',
            'whatsapp_evolution_apikey' => 'chave',
            'whatsapp_evolution_instance' => 'oficina',
        ] as $chave => $valor) {
            \App\Models\Configuration::query()->create(['chave' => $chave, 'valor' => $valor]);
        }

        Http::fake(['*/message/sendMedia/*' => Http::response(['key' => ['id' => 'x']], 201)]);

        $documento = $this->emitidaComXml();

        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/envio', [
                'canal' => 'whatsapp',
                'destino' => '(22) 99274-1004',
            ])
            ->assertOk();

        $enviados = [];

        Http::assertSent(function ($request) use (&$enviados): bool {
            $enviados[] = $request->data();

            return true;
        });

        $this->assertCount(2, $enviados, 'devem sair dois anexos: DANFSe e XML');

        // 1o: o documento que a pessoa abre, com a mensagem principal.
        $this->assertStringEndsWith('.pdf', (string) $enviados[0]['fileName']);
        $this->assertStringContainsString('Segue a nota fiscal de serviço', (string) $enviados[0]['caption']);

        // 2o: o XML, com uma legenda que diz para que ele serve.
        $this->assertStringEndsWith('.xml', (string) $enviados[1]['fileName']);
        $this->assertStringContainsString('seu contador precisa', (string) $enviados[1]['caption']);
    }

    /**
     * Pessoa física não recebe o XML.
     *
     * Quem tem CPF não tem contador para quem repassar o arquivo: chegaria um
     * anexo que não abre no celular e não serve para nada, no meio da nota que
     * a pessoa queria ver.
     */
    public function test_tomador_pessoa_fisica_recebe_so_o_danfse(): void
    {
        foreach ([
            'whatsapp_direct_provider' => 'evolution',
            'whatsapp_evolution_url' => 'https://evolution.exemplo',
            'whatsapp_evolution_apikey' => 'chave',
            'whatsapp_evolution_instance' => 'oficina',
        ] as $chave => $valor) {
            \App\Models\Configuration::query()->create(['chave' => $chave, 'valor' => $valor]);
        }

        Http::fake(['*/message/sendMedia/*' => Http::response(['key' => ['id' => 'x']], 201)]);

        $documento = $this->emitidaComXml();

        // A regra olha o tomador GRAVADO NA NOTA, e nao o cadastro do cliente:
        // o cadastro pode ter mudado depois da emissao.
        DocumentoFiscal::query()->whereKey($documento)->update(['tomador_documento' => '52998224725']);

        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/envio', [
                'canal' => 'whatsapp',
                'destino' => '(22) 99274-1004',
            ])
            ->assertOk();

        $enviados = [];

        Http::assertSent(function ($request) use (&$enviados): bool {
            $enviados[] = $request->data();

            return true;
        });

        $this->assertCount(1, $enviados, 'para CPF sai só o DANFSe');
        $this->assertStringEndsWith('.pdf', (string) $enviados[0]['fileName']);
    }

    /**
     * A mensagem padrão identifica o aparelho.
     *
     * Quem deixa três equipamentos na assistência recebe três notas parecidas, e
     * o número da OS sozinho não diz qual é qual — o cliente teria de abrir o
     * anexo para descobrir.
     */
    public function test_mensagem_padrao_identifica_o_equipamento(): void
    {
        Mail::fake();

        $documento = $this->emitidaComXml();
        $this->descreverEquipamentoDaNota($documento);

        $mensagem = (string) $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/envio', [
                'canal' => 'email',
                'destino' => 'cliente@exemplo.com',
            ])
            ->assertOk()
            ->json('data.envio.mensagem');

        $this->assertStringContainsString('Segue a nota fiscal de serviço nº 2', $mensagem);
        $this->assertStringContainsString('Equipamento: Smartphone Samsung Galaxy S21', $mensagem);
        $this->assertStringContainsString('Número de série: RF8N123ABC', $mensagem);

        // O serviço vem do item da OS. A peça (tipo `peca`) não é serviço
        // executado e não pode entrar na lista.
        $this->assertStringContainsString("Serviço executado:\nTroca de tela", $mensagem);
        $this->assertStringNotContainsString('Tela LCD', $mensagem);
    }

    /**
     * Sem item na OS, mas com orçamento aprovado, o serviço vem de lá — é o
     * caso comum de quem nasceu de orçamento formal: de 32 orçamentos
     * aprovados com OS vinculada, só 1 também tinha item lançado na própria OS.
     */
    public function test_sem_item_na_os_usa_o_orcamento_aprovado(): void
    {
        Mail::fake();

        $documento = $this->emitidaComXml();
        $osId = (int) DocumentoFiscal::query()->whereKey($documento)->value('os_id');

        DB::table('os_itens')->where('os_id', $osId)->where('tipo', 'servico')->delete();

        $orcamentoId = DB::table('orcamentos')->insertGetId([
            'numero' => 'ORC-TEST-001', 'versao' => 1, 'status' => 'aprovado',
            'os_id' => $osId, 'subtotal' => 130, 'total' => 130, 'validade_dias' => 7,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orcamento_itens')->insert([
            'orcamento_id' => $orcamentoId, 'tipo_item' => 'servico',
            'descricao' => 'Formatação e configuração do sistema operacional',
            'quantidade' => 1, 'valor_unitario' => 130, 'total' => 130, 'ordem' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $mensagem = (string) $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/envio', [
                'canal' => 'email',
                'destino' => 'cliente@exemplo.com',
            ])
            ->assertOk()
            ->json('data.envio.mensagem');

        $this->assertStringContainsString(
            "Serviço executado:\nFormatação e configuração do sistema operacional",
            $mensagem
        );
    }

    /**
     * Orçamento rascunho ou rejeitado não descreve o que foi feito — descreve o
     * que foi proposto e não vingou. Não pode entrar na mensagem.
     */
    public function test_orcamento_nao_aprovado_nao_entra_na_mensagem(): void
    {
        Mail::fake();

        $documento = $this->emitidaComXml();
        $osId = (int) DocumentoFiscal::query()->whereKey($documento)->value('os_id');

        DB::table('os_itens')->where('os_id', $osId)->where('tipo', 'servico')->delete();

        $orcamentoId = DB::table('orcamentos')->insertGetId([
            'numero' => 'ORC-TEST-002', 'versao' => 1, 'status' => 'rejeitado',
            'os_id' => $osId, 'subtotal' => 900, 'total' => 900, 'validade_dias' => 7,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orcamento_itens')->insert([
            'orcamento_id' => $orcamentoId, 'tipo_item' => 'servico',
            'descricao' => 'Troca de placa-mãe completa', 'quantidade' => 1,
            'valor_unitario' => 900, 'total' => 900, 'ordem' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $mensagem = (string) $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/envio', [
                'canal' => 'email',
                'destino' => 'cliente@exemplo.com',
            ])
            ->assertOk()
            ->json('data.envio.mensagem');

        $this->assertStringNotContainsString('Troca de placa-mãe completa', $mensagem);
    }

    /**
     * Sem item na OS nem orçamento, a solução aplicada pelo técnico entra no
     * lugar — é o último recurso.
     */
    public function test_sem_os_e_sem_orcamento_usa_a_solucao_aplicada(): void
    {
        Mail::fake();

        $documento = $this->emitidaComXml();
        $osId = (int) DocumentoFiscal::query()->whereKey($documento)->value('os_id');

        DB::table('os_itens')->where('os_id', $osId)->where('tipo', 'servico')->delete();
        DB::table('os')->where('id', $osId)->update([
            'solucao_aplicada' => 'Reforço da solda do FPC',
            // Diario do tecnico: registro interno, nao pode vazar para o cliente.
            'procedimentos_executados' => '[feito teste no botao pwr - 08/06/26 - tecnico: fulano]',
        ]);

        $mensagem = (string) $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/envio', [
                'canal' => 'email',
                'destino' => 'cliente@exemplo.com',
            ])
            ->assertOk()
            ->json('data.envio.mensagem');

        $this->assertStringContainsString("Serviço executado:\nReforço da solda do FPC", $mensagem);
        $this->assertStringNotContainsString('tecnico: fulano', $mensagem);
    }

    /**
     * Sem serviço nem solução registrados, o bloco some — genérico
     * ("serviços de assistência técnica") ocuparia linha sem dizer nada.
     */
    public function test_omite_o_servico_quando_a_os_nao_tem_o_que_dizer(): void
    {
        Mail::fake();

        $documento = $this->emitidaComXml();
        $osId = (int) DocumentoFiscal::query()->whereKey($documento)->value('os_id');

        DB::table('os_itens')->where('os_id', $osId)->where('tipo', 'servico')->delete();

        $mensagem = (string) $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/envio', [
                'canal' => 'email',
                'destino' => 'cliente@exemplo.com',
            ])
            ->assertOk()
            ->json('data.envio.mensagem');

        $this->assertStringNotContainsString('Serviço executado', $mensagem);
        $this->assertStringNotContainsString('Serviços executados', $mensagem);
    }

    /**
     * Aparelho sem tipo, marca, modelo nem série não pode produzir
     * "Equipamento: " com buraco no meio — a linha some inteira.
     */
    public function test_mensagem_padrao_omite_o_equipamento_quando_nao_ha_dados(): void
    {
        Mail::fake();

        $mensagem = (string) $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $this->emitidaComXml() . '/envio', [
                'canal' => 'email',
                'destino' => 'cliente@exemplo.com',
            ])
            ->assertOk()
            ->json('data.envio.mensagem');

        $this->assertStringNotContainsString('Equipamento:', $mensagem);
        $this->assertStringNotContainsString('Número de série:', $mensagem);
    }

    /**
     * Preenche tipo, marca, modelo e número de série do equipamento da OS a que
     * o documento pertence.
     */
    private function descreverEquipamentoDaNota(int $documento): void
    {
        $osId = (int) DocumentoFiscal::query()->whereKey($documento)->value('os_id');
        $equipamentoId = (int) DB::table('os')->where('id', $osId)->value('equipamento_id');

        $tipoId = DB::table('equipamentos_tipos')->insertGetId(['nome' => 'Smartphone', 'ativo' => 1]);
        $marcaId = DB::table('equipamentos_marcas')->insertGetId(['nome' => 'Samsung', 'ativo' => 1]);
        $modeloId = DB::table('equipamentos_modelos')->insertGetId([
            'marca_id' => $marcaId, 'nome' => 'Galaxy S21', 'ativo' => 1,
        ]);

        DB::table('equipamentos')->where('id', $equipamentoId)->update([
            'tipo_id' => $tipoId,
            'marca_id' => $marcaId,
            'modelo_id' => $modeloId,
            'numero_serie' => 'RF8N123ABC',
        ]);
    }

    /**
     * O destino é sempre conferido no servidor: a tela oferece o contato do
     * cadastro, mas o campo é livre, e é digitando que a nota do cliente vai
     * para o endereço errado.
     */
    public function test_recusa_destino_invalido(): void
    {
        $documento = $this->emitidaComXml();

        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/envio', [
                'canal' => 'email',
                'destino' => 'nao-e-email',
            ])
            ->assertStatus(422);

        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $documento . '/envio', [
                'canal' => 'whatsapp',
                'destino' => '1234',
            ])
            ->assertStatus(422);
    }

    public function test_nao_envia_rascunho(): void
    {
        $this->comToken()
            ->postJson('/api/v1/fiscal/documentos/' . $this->rascunho() . '/envio', [
                'canal' => 'email',
                'destino' => 'cliente@exemplo.com',
            ])
            ->assertStatus(422);
    }

    /**
     * Nota emitida, com o XML do portal guardado — que é o que o envio anexa.
     */
    private function emitidaComXml(): int
    {
        Storage::fake('local');

        // A OS tem de ser do tomador do XML — e' o que a guarda de tomador
        // exige, e o envio anexa justamente esse arquivo.
        $documento = $this->rascunho($this->criarOrdem('OS2609030', '72063654001309'));

        $this->comToken()
            ->post('/api/v1/fiscal/documentos/' . $documento . '/importar-xml', [
                'arquivo' => new UploadedFile(
                    base_path('tests/Fixtures/nfse/nfse-real-mei.xml'), 'nfse.xml', 'application/xml', null, true
                ),
            ])
            ->assertOk();

        return $documento;
    }

    private function rascunho(?int $os = null): int
    {
        $os ??= $this->criarOrdem();

        return (int) $this->comToken()
            ->postJson('/api/v1/orders/' . $os . '/documento-fiscal')
            ->json('data.documento.id');
    }

    private function criarOrdem(string $numero = 'OS2609001', string $documentoCliente = '52998224725'): int
    {
        $clienteId = DB::table('clientes')->insertGetId([
            'tipo_pessoa' => 'fisica',
            'nome_razao' => 'Cliente Fiscal',
            'cpf_cnpj' => $documentoCliente,
            'telefone1' => '(11) 95555-0000',
            'status_cadastro' => 'completo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $equipamentoId = DB::table('equipamentos')->insertGetId([
            'cliente_id' => $clienteId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $osId = DB::table('os')->insertGetId([
            'numero_os' => $numero,
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'relato_cliente' => 'Aparelho nao liga',
            'status' => 'entregue_reparado_pago',
            'valor_mao_obra' => 300,
            'valor_pecas' => 120,
            'valor_total' => 420,
            'valor_final' => 420,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('os_itens')->insert([
            ['os_id' => $osId, 'tipo' => 'servico', 'descricao' => 'Troca de tela', 'quantidade' => 1, 'valor_unitario' => 300, 'valor_total' => 300, 'created_at' => now()],
            ['os_id' => $osId, 'tipo' => 'peca', 'descricao' => 'Tela LCD', 'quantidade' => 1, 'valor_unitario' => 120, 'valor_total' => 120, 'created_at' => now()],
        ]);

        return $osId;
    }

    private ?string $tokenAdmin = null;

    /**
     * Cache por INSTANCIA, nao `static`: `RefreshDatabase` apaga o usuario
     * entre um teste e outro, e um cache estatico guardaria o e-mail de um
     * usuario que ja' nao existe — o login falharia com 401 so' a partir do
     * segundo teste do arquivo.
     */
    private function comToken(): self
    {
        if ($this->tokenAdmin === null) {
            $email = 'admin.fiscal@example.com';
            $this->createUserRecord(['nome' => 'Administrador', 'email' => $email, 'perfil' => 'admin', 'grupo_id' => 1]);
            $this->tokenAdmin = $this->token($email);
        }

        return $this->withHeader('Authorization', 'Bearer ' . $this->tokenAdmin);
    }

    private function token(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => 'Senha@123', 'device_name' => 'fiscal',
        ])->json('data.access_token');
    }
}
