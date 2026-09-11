<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinanceiroAnexoTest extends TestCase
{
    use RefreshDatabase;

    public function test_criar_lancamento_com_anexo_envia_o_arquivo_depois_de_criar(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 200, 'tipo' => 'pagar', 'status' => 'pendente']],
                'error' => null,
                'meta' => [],
            ], 201),
            'http://127.0.0.1:8000/api/v1/financeiro/200/anexos' => Http::response([
                'status' => 'success',
                'data' => ['anexo' => ['id' => 1]],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar']]))
            ->post('/financeiro', [
                'tipo' => 'pagar',
                'categoria' => 'Energia',
                'descricao' => 'Conta de luz',
                'valor' => 680.0,
                'data_vencimento' => now()->addDays(5)->toDateString(),
                'anexo' => UploadedFile::fake()->create('boleto.pdf', 300, 'application/pdf'),
                'anexo_descricao' => 'Boleto setembro/2026',
            ]);

        $response->assertRedirect(route('financeiro.index'))
            ->assertSessionHas('success', static fn ($msg) => str_contains($msg, 'Anexo salvo.'));

        Http::assertSent(static fn ($request): bool =>
            $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro' && $request->method() === 'POST'
        );
        Http::assertSent(static fn ($request): bool =>
            $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro/200/anexos' && $request->isMultipart()
        );
    }

    public function test_criar_lancamento_sem_anexo_nao_chama_endpoint_de_anexos(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 201, 'tipo' => 'pagar', 'status' => 'pendente']],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar']]))
            ->post('/financeiro', [
                'tipo' => 'pagar',
                'categoria' => 'Energia',
                'descricao' => 'Conta de luz',
                'valor' => 680.0,
                'data_vencimento' => now()->addDays(5)->toDateString(),
            ]);

        $response->assertRedirect(route('financeiro.index'))
            ->assertSessionHas('success', static fn ($msg) => ! str_contains($msg, 'Anexo'));

        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '/anexos'));
    }

    public function test_criar_lancamento_recusa_anexo_de_tipo_invalido(): void
    {
        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar']]))
            ->post('/financeiro', [
                'tipo' => 'pagar',
                'categoria' => 'Energia',
                'descricao' => 'Conta de luz',
                'valor' => 680.0,
                'data_vencimento' => now()->addDays(5)->toDateString(),
                'anexo' => UploadedFile::fake()->create('planilha.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ]);

        $response->assertSessionHasErrors('anexo');
        Http::assertNothingSent();
    }

    public function test_pagina_de_novo_lancamento_tem_campo_de_anexo_e_formulario_multipart(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => [], 'dre_grupos' => []],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $html = (string) $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->get('/financeiro/novo')
            ->assertOk()
            ->assertSee('ANEXO')
            ->getContent();

        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="anexo"', $html);
    }

    public function test_lista_anexos_em_json_para_o_ver_anexos_da_listagem(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/155/anexos' => Http::response([
                'status' => 'success',
                'data' => [
                    'anexos' => [
                        [
                            'id' => 9,
                            'nome_original' => 'boleto-energia.pdf',
                            'descricao' => null,
                            'mime' => 'application/pdf',
                            'tamanho_bytes' => 204800,
                            'created_at' => '2026-09-11T10:00:00-03:00',
                            'uploaded_by' => ['id' => 1, 'nome' => 'Ana Operadora'],
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->getJson('/financeiro/155/anexos');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('anexos.0.id', 9)
            ->assertJsonPath('anexos.0.nome', 'boleto-energia.pdf')
            ->assertJsonPath('anexos.0.mime', 'application/pdf')
            ->assertJsonPath('anexos.0.tamanho_kb', '200')
            ->assertJsonPath('anexos.0.uploaded_by', 'Ana Operadora')
            ->assertJsonPath('anexos.0.url', route('financeiro.anexos.download', [155, 9]));
    }

    public function test_usuario_sem_permissao_visualizar_nao_lista_anexos(): void
    {
        $response = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->getJson('/financeiro/155/anexos');

        $response->assertRedirect();
    }

    public function test_listagem_renderiza_acao_ver_anexos_e_os_modais_de_leitura(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamentos' => [
                        [
                            'id' => 155, 'tipo' => 'pagar', 'categoria' => 'Energia',
                            'valor' => 680.0, 'status' => 'pago', 'data_vencimento' => '2026-08-28',
                            'anexos_count' => 1,
                        ],
                    ],
                    'status_options' => [],
                    'totais_despesas' => ['fixas' => 0.0, 'variaveis' => 0.0],
                ],
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1, 'from' => 1, 'to' => 1]],
                'error' => null,
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);

        // Só "visualizar": nem anexar nem excluir, mas precisa conseguir
        // conferir os anexos de uma linha sem abrir Detalhes.
        $html = (string) $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro')
            ->assertOk()
            ->assertSee('Ver anexos')
            ->assertDontSee('Anexar arquivo')
            ->getContent();

        $this->assertStringContainsString('id="anexosListModal"', $html);
        $this->assertStringContainsString('id="anexoPreviewModal"', $html);
        $this->assertStringContainsString(
            route('financeiro.anexos.index', ['financeiro' => '__FINANCEIRO_ID__']),
            $html
        );
    }

    public function test_anexa_arquivo_e_redireciona_com_sucesso(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/155/anexos' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 155]],
                'error' => null,
                'meta' => [],
            ], 201),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'editar']]))
            ->post('/financeiro/155/anexos', [
                'arquivo' => UploadedFile::fake()->create('boleto.pdf', 500, 'application/pdf'),
                'descricao' => 'Boleto setembro/2026',
            ]);

        $response->assertRedirect()->assertSessionHas('success', 'Arquivo anexado.');

        Http::assertSent(static fn ($request): bool =>
            $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro/155/anexos'
            && $request->isMultipart()
        );
    }

    public function test_pagina_de_detalhe_renderiza_o_card_de_anexos(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/155' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamento' => [
                        'id' => 155,
                        'tipo' => 'pagar',
                        'categoria' => 'Energia',
                        'descricao' => 'Conta de luz',
                        'valor' => 680.0,
                        'status' => 'pago',
                        'data_vencimento' => '2026-08-28',
                        'anexos' => [
                            [
                                'id' => 9,
                                'nome_original' => 'boleto-energia.pdf',
                                'descricao' => 'Boleto setembro/2026',
                                'tamanho_bytes' => 204800,
                                'created_at' => '2026-09-11T10:00:00-03:00',
                                'uploaded_by' => ['id' => 1, 'nome' => 'Ana Operadora'],
                            ],
                        ],
                    ],
                    'resumo' => ['total_movimentos' => 1, 'valor_aberto' => 0.0],
                    'detalhes' => [],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);

        $html = (string) $this->withSession($this->desktopSession(['financeiro' => ['visualizar', 'editar']]))
            ->get('/financeiro/155')
            ->assertOk()
            ->assertSee('Boleto setembro/2026')
            ->assertSee('Ana Operadora')
            ->assertSee(route('financeiro.anexos.download', [155, 9]), false)
            ->assertSee('Anexar arquivo')
            ->getContent();

        // Regressão: um "data-botao-anexo-financeiro"> com aspa sobrando
        // vira, para o navegador, um atributo cujo NOME literal termina em
        // aspa — o seletor [data-botao-anexo-financeiro] do JS nunca bate, e
        // o botão de enviar fica desabilitado para sempre mesmo com arquivo
        // escolhido. Só passa se o atributo estiver limpo (fechado por
        // espaço ou '>', nunca por aspa).
        $this->assertMatchesRegularExpression(
            '/disabled[^>]*\bdata-botao-anexo-financeiro(?:\s|>)/s',
            $html,
            'o botão de anexar no detalhe do lançamento tem um atributo malformado (aspa sobrando)'
        );
    }

    public function test_pagina_de_detalhe_expoe_preview_inline_mesmo_so_com_visualizar(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/155' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamento' => [
                        'id' => 155,
                        'tipo' => 'pagar',
                        'categoria' => 'Energia',
                        'descricao' => 'Conta de luz',
                        'valor' => 680.0,
                        'status' => 'pago',
                        'data_vencimento' => '2026-08-28',
                        'anexos' => [
                            [
                                'id' => 9,
                                'nome_original' => 'boleto-energia.pdf',
                                'descricao' => 'Boleto setembro/2026',
                                'mime' => 'application/pdf',
                                'tamanho_bytes' => 204800,
                                'created_at' => '2026-09-11T10:00:00-03:00',
                                'uploaded_by' => ['id' => 1, 'nome' => 'Ana Operadora'],
                            ],
                        ],
                    ],
                    'resumo' => ['total_movimentos' => 1, 'valor_aberto' => 0.0],
                    'detalhes' => [],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);

        // Só "visualizar" (sem "editar"): não pode anexar/excluir, mas
        // precisa poder ver o que já está anexado sem sair da página — o
        // pedido original era exatamente esse.
        $html = (string) $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro/155')
            ->assertOk()
            ->assertDontSee('Anexar arquivo')
            ->getContent();

        $this->assertStringContainsString('id="anexoPreviewModal"', $html);
        $this->assertStringContainsString('data-anexo-url="' . route('financeiro.anexos.download', [155, 9]) . '"', $html);
        $this->assertStringContainsString('data-anexo-mime="application/pdf"', $html);
        $this->assertStringContainsString('assets/js/financeiro-anexos.js', $html);
    }

    public function test_recusa_upload_sem_arquivo(): void
    {
        Http::fake(['http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200)]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'editar']]))
            ->post('/financeiro/155/anexos', []);

        $response->assertSessionHasErrors('arquivo');
    }

    public function test_usuario_sem_permissao_editar_nao_pode_anexar(): void
    {
        Http::fake(['http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200)]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->post('/financeiro/155/anexos', [
                'arquivo' => UploadedFile::fake()->create('boleto.pdf', 100, 'application/pdf'),
            ]);

        $response->assertRedirect()->assertSessionHas('error');

        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '/anexos'));
    }

    public function test_listagem_mostra_indicador_de_anexo_e_acao_rapida(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamentos' => [
                        [
                            'id' => 155, 'tipo' => 'pagar', 'categoria' => 'Energia',
                            'valor' => 680.0, 'status' => 'pago', 'data_vencimento' => '2026-08-28',
                            'anexos_count' => 2,
                        ],
                        [
                            'id' => 156, 'tipo' => 'pagar', 'categoria' => 'Internet',
                            'valor' => 120.0, 'status' => 'pendente', 'data_vencimento' => '2026-09-20',
                            'anexos_count' => 0,
                        ],
                    ],
                    'status_options' => [],
                    'totais_despesas' => ['fixas' => 0.0, 'variaveis' => 0.0],
                ],
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 20, 'total' => 2, 'last_page' => 1, 'from' => 1, 'to' => 2]],
                'error' => null,
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);

        $html = (string) $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'editar']]))
            ->get('/financeiro')
            ->assertOk()
            ->assertSee('Anexar arquivo')
            ->getContent();

        $this->assertStringContainsString('bi-paperclip', $html);
        $this->assertMatchesRegularExpression(
            '/data-financeiro-id="155"/',
            $html,
            'a ação rápida de anexar deveria expor o id do lançamento 155'
        );

        // Mesma regressão do modal do detalhe: o botão do modal único
        // (compartilhado por todas as linhas) precisa ter o atributo
        // data-botao-anexo-financeiro limpo, sem aspa sobrando colada nele.
        $this->assertMatchesRegularExpression(
            '/disabled[^>]*\bdata-botao-anexo-financeiro(?:\s|>)/s',
            $html,
            'o botão de anexar do modal compartilhado da listagem tem um atributo malformado (aspa sobrando)'
        );
    }

    public function test_download_repassa_corpo_e_cabecalhos_do_backend(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/155/anexos/9/download' => Http::response(
                '%PDF-1.4 conteudo fake',
                200,
                ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="boleto.pdf"']
            ),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro/155/anexos/9/download');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('%PDF-1.4', $response->getContent());
    }

    public function test_usuario_sem_permissao_visualizar_nao_baixa_anexo(): void
    {
        Http::fake(['http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200)]);

        $response = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->get('/financeiro/155/anexos/9/download');

        $response->assertRedirect();
        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_exclui_anexo_e_redireciona_com_sucesso(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/155/anexos/9' => Http::response([
                'status' => 'success', 'data' => null, 'error' => null, 'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'editar']]))
            ->delete('/financeiro/155/anexos/9');

        $response->assertRedirect()->assertSessionHas('success', 'Anexo movido para a lixeira.');

        Http::assertSent(static fn ($request): bool =>
            $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro/155/anexos/9'
            && $request->method() === 'DELETE'
        );
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
     * @param array<string, array<int, string>> $permissions
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
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function fakeUser(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 99,
            'nome' => 'Usuário de Teste',
            'email' => 'usuario@teste.local',
            'perfil' => 'atendente',
            'group' => [
                'id' => 2,
                'nome' => 'Financeiro',
                'descricao' => 'Grupo de teste',
                'sistema' => false,
            ],
            'modules' => [],
            'permissions' => [],
            'foto' => '',
            'ativo' => true,
        ], $overrides);
    }
}
