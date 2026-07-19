<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Telas do motor central de templates PDF (página Modelos PDF).
 */
class PdfTemplateEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_registered_document_types_with_status(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/knowledge/pdf-engine/types' => Http::response([
                'status' => 'success',
                'data' => ['tipos' => [
                    [
                        'tipo_codigo' => 'os_abertura',
                        'nome' => 'Comprovante de abertura',
                        'descricao' => 'Comprovante entregue ao cliente na abertura da OS.',
                        'template_id' => 1,
                        'arquivado' => false,
                        'versao_publicada' => 1,
                        'publicado_em' => '2026-07-18 08:00:00',
                        'tem_rascunho' => false,
                        'versao_rascunho' => null,
                        'total_versoes' => 1,
                        'gatilhos_automaticos' => ['criacao_os'],
                    ],
                    [
                        'tipo_codigo' => 'os_laudo_tecnico',
                        'nome' => 'Laudo técnico',
                        'descricao' => 'Laudo com diagnóstico e solução.',
                        'template_id' => 2,
                        'arquivado' => false,
                        'versao_publicada' => 3,
                        'publicado_em' => '2026-07-18 09:00:00',
                        'tem_rascunho' => true,
                        'versao_rascunho' => 4,
                        'total_versoes' => 4,
                        'gatilhos_automaticos' => ['status_tecnico'],
                    ],
                ]],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['conhecimento' => ['visualizar', 'editar']]))
            ->get('/conhecimento/modelos-pdf');

        $response->assertOk()
            ->assertSee('Modelos PDF')
            ->assertSee('Motor central de documentos')
            ->assertSee('Comprovante de abertura')
            ->assertSee('os_abertura')
            ->assertSee('Laudo técnico')
            ->assertSee('v4 em edição')
            ->assertSee('criacao_os')
            ->assertSee('Novo documento')
            ->assertSee('Criar documento do zero')
            ->assertSee('Clonar')
            ->assertSee('clone-document-form', false)
            ->assertDontSee('Modelos legados (HTML)')
            ->assertSee(route('knowledge.pdf-engine.edit', ['template' => 1]), false);
    }

    public function test_create_and_clone_actions_redirect_to_the_new_editor(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/knowledge/pdf-engine/templates' => Http::response([
                'status' => 'success',
                'data' => ['template' => ['id' => 81]],
                'error' => null,
                'meta' => [],
            ], 201),
            'http://127.0.0.1:8000/api/v1/knowledge/pdf-engine/templates/5/clone' => Http::response([
                'status' => 'success',
                'data' => ['template' => ['id' => 82]],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $session = $this->desktopSession(['conhecimento' => ['visualizar', 'editar']]);

        $this->withSession($session)->post('/conhecimento/modelos-pdf', [
            'nome' => 'Termo de garantia',
            'descricao' => 'Documento novo.',
            'tipo_base_codigo' => 'os_encerramento',
        ])->assertRedirect(route('knowledge.pdf-engine.edit', ['template' => 81]));

        $this->withSession($session)->post('/conhecimento/modelos-pdf/motor/5/clonar', [
            'nome' => 'Laudo de seguradora',
            'descricao' => 'Cópia do laudo.',
        ])->assertRedirect(route('knowledge.pdf-engine.edit', ['template' => 82]));

        Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:8000/api/v1/knowledge/pdf-engine/templates'
            && $request['tipo_base_codigo'] === 'os_encerramento');
        Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:8000/api/v1/knowledge/pdf-engine/templates/5/clone'
            && $request['nome'] === 'Laudo de seguradora');
    }

    public function test_legacy_template_manager_redirects_to_the_central_engine(): void
    {
        $response = $this
            ->withSession($this->desktopSession(['conhecimento' => ['visualizar']]))
            ->get('/conhecimento/modelos-pdf/legado');

        $response
            ->assertRedirect(route('knowledge.pdf-engine.index'))
            ->assertSessionHas('info');
    }

    public function test_edit_screen_boots_block_editor_with_schema_and_metadata(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/knowledge/pdf-engine/templates/5' => Http::response([
                'status' => 'success',
                'data' => ['template' => [
                    'id' => 5,
                    'tipo_codigo' => 'os_laudo_tecnico',
                    'nome' => 'Laudo técnico',
                    'descricao' => 'Laudo com diagnóstico e solução.',
                    'arquivado' => false,
                    'versao_publicada' => [
                        'id' => 51,
                        'versao' => 1,
                        'status' => 'publicado',
                        'papel' => 'a4',
                        'orientacao' => 'retrato',
                        'publicado_em' => '2026-07-18 08:00:00',
                        'updated_at' => '2026-07-18 08:00:00',
                        'schema' => [
                            'pagina' => ['papel' => 'a4'],
                            'cabecalho' => [['tipo' => 'titulo', 'texto' => '{{ documento.nome }}']],
                            'corpo' => [['tipo' => 'paragrafo', 'texto' => '{{ os.diagnostico_tecnico }}']],
                            'rodape' => [],
                        ],
                    ],
                    'rascunho' => null,
                    'versoes' => [
                        ['id' => 51, 'versao' => 1, 'status' => 'publicado', 'publicado_em' => '2026-07-18 08:00:00', 'updated_at' => '2026-07-18 08:00:00'],
                    ],
                ]],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/knowledge/pdf-engine/types/os_laudo_tecnico/variables' => Http::response([
                'status' => 'success',
                'data' => ['tipo' => [
                    'tipo_codigo' => 'os_laudo_tecnico',
                    'nome' => 'Laudo técnico',
                    'variaveis' => [
                        ['caminho' => 'os.diagnostico_tecnico', 'tipo' => 'string'],
                        ['caminho' => 'empresa.nome_fantasia', 'tipo' => 'string'],
                    ],
                    'colecoes' => [
                        ['nome' => 'itens', 'colunas' => [['campo' => 'descricao', 'tipo' => 'string']]],
                    ],
                    'tokens_imagem' => ['logo_empresa', 'foto_equipamento_principal'],
                    'formatadores' => ['moeda', 'data'],
                    'blocos' => ['titulo', 'paragrafo', 'tabela'],
                    'papeis' => ['a4', '80mm'],
                    'orientacoes' => ['retrato', 'paisagem'],
                    'operadores_condicao' => ['preenchido', 'vazio', 'igual', 'diferente'],
                ]],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['conhecimento' => ['visualizar', 'editar', 'publicar', 'restaurar']]))
            ->get('/conhecimento/modelos-pdf/motor/5');

        $response->assertOk()
            ->assertSee('Laudo técnico')
            ->assertSee('Estrutura do documento')
            ->assertSee('Configuração do bloco')
            ->assertSee('pdf-editor-structure-column', false)
            ->assertSee('col-xl-4 col-lg-5', false)
            ->assertSee('pdf-editor-config-column', false)
            ->assertSee('col-xl-8 col-lg-7', false)
            ->assertSee('min-height: clamp(320px, 50vh, 560px)', false)
            ->assertSee('Variáveis disponíveis')
            ->assertSee('__PDF_TEMPLATE_EDITOR', false)
            ->assertSee('os.diagnostico_tecnico', false)
            ->assertSee('foto_equipamento_principal', false)
            ->assertSee('pdf-template-editor.js', false)
            ->assertSee('Salvar rascunho')
            ->assertSee('Publicar')
            ->assertSee('Restaurar como novo rascunho', false);
    }

    public function test_edit_screen_hides_publish_and_restore_without_granular_permission(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/knowledge/pdf-engine/templates/5' => Http::response([
                'status' => 'success',
                'data' => ['template' => [
                    'id' => 5,
                    'tipo_codigo' => 'os_laudo_tecnico',
                    'nome' => 'Laudo técnico',
                    'descricao' => 'Laudo com diagnóstico e solução.',
                    'arquivado' => false,
                    'versao_publicada' => [
                        'id' => 51,
                        'versao' => 1,
                        'status' => 'publicado',
                        'papel' => 'a4',
                        'orientacao' => 'retrato',
                        'publicado_em' => '2026-07-18 08:00:00',
                        'updated_at' => '2026-07-18 08:00:00',
                        'schema' => [
                            'pagina' => ['papel' => 'a4'],
                            'cabecalho' => [['tipo' => 'titulo', 'texto' => '{{ documento.nome }}']],
                            'corpo' => [['tipo' => 'paragrafo', 'texto' => '{{ os.diagnostico_tecnico }}']],
                            'rodape' => [],
                        ],
                    ],
                    'rascunho' => null,
                    'versoes' => [
                        ['id' => 51, 'versao' => 1, 'status' => 'publicado', 'publicado_em' => '2026-07-18 08:00:00', 'updated_at' => '2026-07-18 08:00:00'],
                    ],
                ]],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/knowledge/pdf-engine/types/os_laudo_tecnico/variables' => Http::response([
                'status' => 'success',
                'data' => ['tipo' => [
                    'tipo_codigo' => 'os_laudo_tecnico',
                    'nome' => 'Laudo técnico',
                    'variaveis' => [],
                    'colecoes' => [],
                    'tokens_imagem' => ['logo_empresa'],
                    'formatadores' => ['moeda', 'data'],
                    'blocos' => ['titulo', 'paragrafo', 'tabela'],
                    'papeis' => ['a4', '80mm'],
                    'orientacoes' => ['retrato', 'paisagem'],
                    'operadores_condicao' => ['preenchido', 'vazio', 'igual', 'diferente'],
                ]],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['conhecimento' => ['visualizar', 'editar']]))
            ->get('/conhecimento/modelos-pdf/motor/5');

        $response->assertOk()
            ->assertSee('Salvar rascunho')
            ->assertDontSee('Publicar')
            ->assertDontSee('Restaurar como novo rascunho', false);
    }

    public function test_publish_route_requires_publicar_permission_not_just_editar(): void
    {
        $this
            ->withSession($this->desktopSession(['conhecimento' => ['visualizar', 'editar']]))
            ->post('/conhecimento/modelos-pdf/motor/5/publicar')
            ->assertRedirect();
    }

    public function test_restore_route_requires_restaurar_permission_not_just_editar(): void
    {
        $this
            ->withSession($this->desktopSession(['conhecimento' => ['visualizar', 'editar']]))
            ->post('/conhecimento/modelos-pdf/motor/5/versoes/1/restaurar')
            ->assertRedirect();
    }

    public function test_index_requires_conhecimento_visualizar_permission(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->get('/conhecimento/modelos-pdf');

        $response->assertStatus(302);
    }

    public function test_editor_asset_offers_safe_plain_text_organization(): void
    {
        $asset = file_get_contents(public_path('assets/js/pdf-template-editor.js'));

        $this->assertIsString($asset);
        $this->assertStringContainsString('Organizar texto automaticamente', $asset);
        $this->assertStringContainsString('buildStructuredBlocks', $asset);
        $this->assertStringContainsString("tipo: 'cabecalho_secao'", $asset);
        $this->assertStringContainsString("tipo: 'lista'", $asset);
        $this->assertStringContainsString("Colunas (até 3)", $asset);
        $this->assertStringContainsString('tokens_imagem', $asset);
        $this->assertStringContainsString('foto_equipamento_principal', $asset);
        $this->assertStringContainsString('Assinatura do responsável (técnico ou usuário emissor)', $asset);
        $this->assertStringContainsString('{{ cliente.nome }} - Cliente', $asset);
        $this->assertStringContainsString("kind: 'signature_label'", $asset);
        $this->assertStringNotContainsString('Rótulos (JSON: até 2 textos)', $asset);
        $this->assertStringNotContainsString('innerHTML = block.texto', $asset);
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationsPayload(): array
    {
        return [
            'status' => 'success',
            'data' => ['notifications' => [], 'unread_count' => 0],
            'error' => null,
            'meta' => [],
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
                'user' => [
                    'id' => 1,
                    'nome' => 'Gerente',
                    'email' => 'gerente@example.com',
                    'perfil' => 'gerente',
                    'ativo' => true,
                    'modules' => array_keys($permissions),
                    'permissions' => $permissions,
                ],
            ],
        ];
    }
}
