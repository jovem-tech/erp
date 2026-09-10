<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClienteHttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Emissão direta pelo sistema (spec 041) — lado do desktop.
 *
 * O que estes testes protegem, em ordem de importância:
 *
 *  1. Que a tela diga em qual AMBIENTE vai emitir. Emitir em produção achando
 *     que testava gera documento fiscal real, com obrigação tributária.
 *  2. Que o encerramento da OS NÃO dependa da emissão. A OS já foi encerrada
 *     quando a nota é transmitida; falha ali vira aviso, nunca desfaz a baixa.
 */
class EmissaoNfseAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tela_oferece_emitir_quando_o_certificado_esta_pronto(): void
    {
        $this->fakeApi(['orders/10/documento-fiscal' => [
            'documento' => $this->documento(),
            'emissao' => ['ambiente' => 2, 'disponivel' => true, 'impedimento' => null],
        ]]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('Emitir agora pelo sistema')
            ->assertSee(route('fiscal.documentos.emitir', 7), false)
            // Os caminhos manuais continuam disponiveis como alternativa.
            ->assertSee('Importar o XML da nota');
    }

    public function test_avisa_que_a_emissao_e_de_teste_em_homologacao(): void
    {
        $this->fakeApi(['orders/10/documento-fiscal' => [
            'documento' => $this->documento(),
            'emissao' => ['ambiente' => 2, 'disponivel' => true, 'impedimento' => null],
        ]]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('Homologação')
            ->assertSee('a nota é de teste');
    }

    public function test_avisa_com_todas_as_letras_quando_a_emissao_e_real(): void
    {
        // Este e' o aviso que separa "testei" de "emiti uma nota fiscal".
        $this->fakeApi(['orders/10/documento-fiscal' => [
            'documento' => $this->documento(),
            'emissao' => ['ambiente' => 1, 'disponivel' => true, 'impedimento' => null],
        ]]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            ->assertSee('Produção')
            ->assertSee('documento fiscal de verdade');
    }

    public function test_sem_certificado_o_botao_fica_desabilitado_com_o_motivo(): void
    {
        $this->fakeApi(['orders/10/documento-fiscal' => [
            'documento' => $this->documento(),
            'emissao' => [
                'ambiente' => 2,
                'disponivel' => false,
                'impedimento' => 'Certificado venceu em 01/01/2026.',
            ],
        ]]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/fiscal/os/10/nota')
            ->assertOk()
            // Dizer POR QUE, e nao so' esconder o botao: sumir sem explicacao
            // manda o operador procurar defeito na tela errada.
            ->assertSee('Certificado venceu em 01/01/2026.')
            ->assertSee(route('configurations.integrations.index'), false);
    }

    public function test_emitir_encaminha_para_a_api_e_confirma_com_o_numero(): void
    {
        $this->fakeApi(['fiscal/documentos/7/emitir' => [
            'documento' => $this->documento(['numero' => '5', 'status' => 'emitido']),
        ]]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->post('/fiscal/documentos/7/emitir', ['os_id' => 10])
            ->assertRedirect(route('fiscal.nota', 10))
            ->assertSessionHas('success');

        Http::assertSent(fn (ClienteHttpRequest $r): bool => str_contains($r->url(), '/fiscal/documentos/7/emitir')
            && $r->method() === 'POST');
    }

    public function test_quem_nao_edita_os_nao_emite(): void
    {
        $this->fakeApi([]);

        $this->withSession($this->desktopSession(['os' => ['visualizar']]))
            ->post('/fiscal/documentos/7/emitir', ['os_id' => 10])
            ->assertRedirect()
            ->assertSessionHas('error', 'Você não tem permissão para acessar este recurso.');

        // O importante nao e' o codigo HTTP: e' que nada foi transmitido.
        Http::assertNotSent(fn (ClienteHttpRequest $r): bool => str_contains($r->url(), '/emitir'));
    }

    public function test_a_baixa_pode_emitir_a_nota_no_mesmo_ato(): void
    {
        $this->fakeApi([
            'orders/10/documento-fiscal' => ['documento' => $this->documento()],
            'fiscal/documentos/7/emitir' => [
                'documento' => $this->documento(['numero' => '5', 'status' => 'emitido']),
            ],
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->post('/os/10/baixa', [
                'classificacao_baixa' => 'baixa',
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-01',
                'emitir_nfse_automatico' => '1',
            ])
            ->assertRedirect(route('fiscal.nota', 10))
            ->assertSessionHas('success');

        Http::assertSent(fn (ClienteHttpRequest $r): bool => str_contains($r->url(), '/fiscal/documentos/7/emitir'));
    }

    public function test_falha_na_emissao_nao_desfaz_o_encerramento_da_os(): void
    {
        // O caso que importa: a OS JA FOI ENCERRADA quando a nota e'
        // transmitida. Se a emissao falhasse a baixa junto, o operador
        // tentaria encerrar de novo uma OS ja' fechada.
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/orders/10/documento-fiscal' => Http::response([
                'status' => 'success',
                'data' => ['documento' => $this->documento()],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/fiscal/documentos/7/emitir' => Http::response([
                'status' => 'error',
                'data' => null,
                'error' => ['message' => 'Ambiente Nacional recusou: E0042 - Competencia encerrada'],
                'meta' => [],
            ], 422),
            '*' => Http::response(['status' => 'success', 'data' => [], 'error' => null, 'meta' => []], 200),
        ]);

        $resposta = $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->post('/os/10/baixa', [
                'classificacao_baixa' => 'baixa',
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-01',
                'emitir_nfse_automatico' => '1',
            ]);

        $resposta->assertRedirect(route('fiscal.nota', 10));

        $erro = (string) session('error');
        $this->assertStringContainsString('OS foi encerrada', $erro);
        // E o motivo do fisco chega inteiro ate' o operador.
        $this->assertStringContainsString('Competencia encerrada', $erro);
    }

    public function test_ligar_producao_exige_digitar_a_palavra(): void
    {
        // Um checkbox marcado por engano passa a valer para TODA emissao
        // seguinte. Digitar obriga a saber o que se esta fazendo.
        $this->fakeApi([]);

        $this->withSession($this->desktopSession(['configuracoes' => ['editar']]))
            ->post('/fiscal/ambiente', ['ambiente' => 1, 'confirmacao' => 'sim'])
            ->assertRedirect(route('configurations.integrations.index'))
            ->assertSessionHas('error');

        Http::assertNotSent(fn (ClienteHttpRequest $r): bool => str_contains($r->url(), '/fiscal/ambiente'));
    }

    public function test_com_a_palavra_certa_a_producao_e_ligada(): void
    {
        $this->fakeApi(['fiscal/ambiente' => ['ambiente' => 1, 'rotulo' => 'Produção']]);

        $this->withSession($this->desktopSession(['configuracoes' => ['editar']]))
            ->post('/fiscal/ambiente', ['ambiente' => 1, 'confirmacao' => 'PRODUCAO'])
            ->assertRedirect(route('configurations.integrations.index'))
            ->assertSessionHas('success');

        Http::assertSent(fn (ClienteHttpRequest $r): bool => str_contains($r->url(), '/fiscal/ambiente')
            && $r->method() === 'POST');
    }

    public function test_voltar_para_homologacao_nao_pede_confirmacao(): void
    {
        // Reduzir risco tem de ser o caminho mais facil dos dois.
        $this->fakeApi(['fiscal/ambiente' => ['ambiente' => 2, 'rotulo' => 'Homologação']]);

        $this->withSession($this->desktopSession(['configuracoes' => ['editar']]))
            ->post('/fiscal/ambiente', ['ambiente' => 2])
            ->assertRedirect(route('configurations.integrations.index'))
            ->assertSessionHas('success');
    }

    public function test_quem_nao_edita_configuracoes_nao_troca_o_ambiente(): void
    {
        $this->fakeApi([]);

        $this->withSession($this->desktopSession(['os' => ['editar']]))
            ->post('/fiscal/ambiente', ['ambiente' => 2])
            ->assertRedirect()
            ->assertSessionHas('error', 'Você não tem permissão para acessar este recurso.');
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
            $fakes['http://127.0.0.1:8000/api/v1/'.$rota] = Http::response([
                'status' => 'success',
                'data' => $dados,
                'error' => null,
                'meta' => [],
            ], 200);
        }

        $fakes['*'] = Http::response([
            'status' => 'success', 'data' => [], 'error' => null, 'meta' => [],
        ], 200);

        Http::fake($fakes);
    }

    /**
     * @param  array<string, mixed>  $sobrescreve
     * @return array<string, mixed>
     */
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
            'discriminacao' => "Ordem de servico OS2609001\nTroca de tela",
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
            'group' => [
                'id' => 1,
                'nome' => 'Administrador',
                'descricao' => 'Grupo completo',
                'sistema' => true,
            ],
            'modules' => [],
            'permissions' => [],
            'foto' => '',
            'ativo' => true,
        ], $overrides);
    }
}
