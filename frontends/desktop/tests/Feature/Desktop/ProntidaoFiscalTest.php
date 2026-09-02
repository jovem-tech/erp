<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tela de prontidao fiscal (spec 041).
 *
 * O que precisa aparecer e' o numero de pendencia: e' ele que diz se ja' da'
 * para emitir nota. Uma tela que renderiza mas engole o numero e' pior do que
 * nao existir, porque passa a sensacao de que alguem esta' medindo.
 */
class ProntidaoFiscalTest extends TestCase
{
    use RefreshDatabase;

    public function test_mostra_o_numero_de_clientes_sem_documento(): void
    {
        $this->fakeProntidao($this->payload([
            'clientes' => [
                'total' => 1326,
                'sem_documento' => 1323,
                'documento_invalido' => 0,
                'prontos' => 3,
                'pendencias' => 1323,
                'percentual_pronto' => 0.2,
            ],
        ], 1323));

        $this->withSession($this->desktopSession(['clientes' => ['visualizar']]))
            ->get('/fiscal/prontidao')
            ->assertOk()
            ->assertSee('Prontidão fiscal')
            ->assertSee('1.326')
            ->assertSee('1.323');
    }

    public function test_separa_documento_invalido_de_ausente(): void
    {
        // A distincao importa: ausente se resolve pedindo ao cliente; invalido
        // e' erro de digitacao ja' gravado, e o tratamento e' outro.
        $this->fakeProntidao($this->payload([
            'clientes' => [
                'total' => 10,
                'sem_documento' => 4,
                'documento_invalido' => 2,
                'prontos' => 4,
                'pendencias' => 6,
                'percentual_pronto' => 40.0,
            ],
        ], 6));

        $this->withSession($this->desktopSession(['clientes' => ['visualizar']]))
            ->get('/fiscal/prontidao')
            ->assertOk()
            ->assertSee('sem CPF/CNPJ')
            ->assertSee('com documento inválido');
    }

    public function test_lista_os_campos_fiscais_faltando_na_empresa(): void
    {
        $this->fakeProntidao($this->payload([
            'empresa' => [
                'total' => 11,
                'prontos' => 9,
                'pendencias' => 2,
                'campos_faltando' => ['Código IBGE do município', 'Inscrição municipal'],
                'percentual_pronto' => 81.8,
            ],
        ], 2));

        $this->withSession($this->desktopSession(['clientes' => ['visualizar']]))
            ->get('/fiscal/prontidao')
            ->assertOk()
            ->assertSee('Código IBGE do município')
            ->assertSee('Inscrição municipal');
    }

    public function test_mostra_pendencia_de_servico_e_peca(): void
    {
        $this->fakeProntidao($this->payload([
            'servicos' => ['total' => 10, 'sem_codigo_tributacao' => 10, 'prontos' => 0, 'pendencias' => 10, 'percentual_pronto' => 0.0],
            'pecas' => ['total' => 9, 'sem_ncm' => 9, 'prontos' => 0, 'pendencias' => 9, 'percentual_pronto' => 0.0],
        ], 19));

        $this->withSession($this->desktopSession(['clientes' => ['visualizar']]))
            ->get('/fiscal/prontidao')
            ->assertOk()
            ->assertSee('sem código de tributação nacional')
            ->assertSee('sem NCM');
    }

    public function test_base_completa_nao_mostra_alerta_de_pendencia(): void
    {
        $this->fakeProntidao($this->payload([], 0));

        $this->withSession($this->desktopSession(['clientes' => ['visualizar']]))
            ->get('/fiscal/prontidao')
            ->assertOk()
            ->assertSee('Nenhuma pendência de cadastro.')
            ->assertDontSee('pendência(s) de cadastro.');
    }

    public function test_sem_permissao_de_clientes_nao_entra(): void
    {
        $this->fakeProntidao($this->payload([], 0));

        // `EnsureRoutePermission` redireciona para a primeira rota permitida em
        // vez de 403 — so' aborta quando a rota negada JA' e' o destino. Quem
        // tem apenas `os` cai na tela de OS com o aviso.
        $this->withSession($this->desktopSession(['os' => ['visualizar']]))
            ->get('/fiscal/prontidao')
            ->assertRedirect()
            ->assertSessionHas('error', 'Você não tem permissão para acessar este recurso.');
    }

    /**
     * Base pronta por padrao; cada teste sobrescreve so' a area que exercita.
     *
     * @param  array<string, array<string, mixed>>  $areas
     * @return array<string, mixed>
     */
    private function payload(array $areas, int $pendencias): array
    {
        $padrao = [
            'empresa' => ['total' => 11, 'prontos' => 11, 'pendencias' => 0, 'campos_faltando' => [], 'percentual_pronto' => 100.0],
            'clientes' => ['total' => 5, 'sem_documento' => 0, 'documento_invalido' => 0, 'prontos' => 5, 'pendencias' => 0, 'percentual_pronto' => 100.0],
            'servicos' => ['total' => 3, 'sem_codigo_tributacao' => 0, 'prontos' => 3, 'pendencias' => 0, 'percentual_pronto' => 100.0],
            'pecas' => ['total' => 2, 'sem_ncm' => 0, 'prontos' => 2, 'pendencias' => 0, 'percentual_pronto' => 100.0],
        ];

        return [
            'areas' => array_replace($padrao, $areas),
            'pendencias_totais' => $pendencias,
            'pronto' => $pendencias === 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fakeProntidao(array $payload): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/fiscal/prontidao*' => Http::response([
                'status' => 'success',
                'data' => $payload,
                'error' => null,
                'meta' => [],
            ], 200),
        ]);
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
