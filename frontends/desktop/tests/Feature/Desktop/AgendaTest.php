<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgendaTest extends TestCase
{
    public function test_agenda_nao_aparece_na_sidebar_sem_permissao(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->get('/dashboard')
            ->assertOk()
            // A sidebar monta href absoluto (APP_URL + path); casar pelo
            // final do path funciona nos dois formatos.
            ->assertDontSee('/agenda"', false);
    }

    public function test_agenda_aparece_logo_abaixo_do_dashboard(): void
    {
        $this->fakeBackend();

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'agenda' => ['visualizar'],
            ]))
            ->get('/dashboard')
            ->assertOk();

        $html = $response->getContent();

        $posDashboard = strpos($html, '/dashboard"');
        $posAgenda = strpos($html, '/agenda"');

        $this->assertNotFalse($posAgenda, 'O item Agenda deveria estar na sidebar.');
        // O usuário pediu explicitamente "abaixo do dashboard": a ordem
        // importa, não só a presença.
        $this->assertLessThan($posAgenda, $posDashboard);
    }

    public function test_rota_da_agenda_exige_permissao(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->get('/agenda')
            ->assertRedirect();
    }

    public function test_calendario_mostra_os_compromissos_do_mes(): void
    {
        $this->fakeBackend([
            [
                'id' => 1, 'titulo' => 'Pagar: conta de energia', 'descricao' => null,
                'tipo' => 'conta_pagar', 'origem_tipo' => 'conta_pagar', 'origem_id' => 5,
                'gerido' => true, 'inicio_em' => '2026-09-10T09:00:00-03:00', 'fim_em' => null,
                'data' => '2026-09-10', 'hora' => null, 'dia_inteiro' => true,
                'status' => 'pendente', 'prioridade' => 'alta', 'atrasado' => false,
                'responsavel_id' => null, 'responsavel_nome' => null,
                'cliente_id' => null, 'os_id' => null, 'lembrete_minutos' => null,
                'google_event_id' => null, 'google_sync_estado' => 'desligado', 'concluido_em' => null,
            ],
            [
                'id' => 2, 'titulo' => 'Retorno pós-serviço da OS 42', 'descricao' => 'Ligar para o cliente',
                'tipo' => 'retorno_pos_servico', 'origem_tipo' => 'retorno_pos_servico', 'origem_id' => 3,
                'gerido' => true, 'inicio_em' => '2026-09-12T10:00:00-03:00', 'fim_em' => null,
                'data' => '2026-09-12', 'hora' => '10:00', 'dia_inteiro' => false,
                'status' => 'pendente', 'prioridade' => 'normal', 'atrasado' => false,
                'responsavel_id' => 99, 'responsavel_nome' => 'Usuário de Teste',
                'cliente_id' => 7, 'os_id' => 42, 'lembrete_minutos' => 30,
                'google_event_id' => null, 'google_sync_estado' => 'desligado', 'concluido_em' => null,
            ],
        ]);

        $this
            ->withSession($this->desktopSession(['agenda' => ['visualizar', 'criar', 'editar']]))
            ->get('/agenda?data=2026-09-10&view=mes')
            ->assertOk()
            ->assertSee('Setembro de 2026')
            ->assertSee('Pagar: conta de energia')
            ->assertSee('Retorno pós-serviço da OS 42')
            // Cor por tipo: é o que deixa a categoria legível sem leitura.
            ->assertSee('agenda-tipo-conta_pagar', false)
            ->assertSee('agenda-tipo-retorno_pos_servico', false);
    }

    public function test_visao_de_lista_agrupa_por_data(): void
    {
        $this->fakeBackend([
            [
                'id' => 3, 'titulo' => 'Ligar para o fornecedor', 'descricao' => 'Cotação da tela',
                'tipo' => 'manual', 'origem_tipo' => null, 'origem_id' => null,
                'gerido' => false, 'inicio_em' => '2026-09-15T14:00:00-03:00', 'fim_em' => null,
                'data' => '2026-09-15', 'hora' => '14:00', 'dia_inteiro' => false,
                'status' => 'pendente', 'prioridade' => 'normal', 'atrasado' => false,
                'responsavel_id' => 99, 'responsavel_nome' => 'Usuário de Teste',
                'cliente_id' => null, 'os_id' => null, 'lembrete_minutos' => 30,
                'google_event_id' => null, 'google_sync_estado' => 'desligado', 'concluido_em' => null,
            ],
        ]);

        $this
            ->withSession($this->desktopSession(['agenda' => ['visualizar']]))
            ->get('/agenda?data=2026-09-10&view=lista')
            ->assertOk()
            ->assertSee('Ligar para o fornecedor')
            ->assertSee('Cotação da tela')
            ->assertSee('15/09');
    }

    public function test_visao_de_dia_separa_dia_inteiro_de_hora_marcada(): void
    {
        $this->fakeBackend([
            $this->item(1, 'Pagar: conta de energia', '2026-09-10', null, 'conta_pagar', true),
            $this->item(2, 'Reunião com o contador', '2026-09-10', '09:00', 'manual', false, '2026-09-10T10:00:00-03:00'),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['agenda' => ['visualizar']]))
            ->get('/agenda?data=2026-09-10&view=dia')
            ->assertOk()
            ->assertSee('Quinta, 10 de setembro de 2026')
            ->assertSee('Dia inteiro')
            ->assertSee('Reunião com o contador');

        $html = $response->getContent();

        // Vencimento fica na faixa de dia inteiro; a reunião vira bloco
        // posicionado na coluna de horas. 09:00 = 37,5% do dia.
        $this->assertStringContainsString('agenda-timegrid-allday-cell', $html);
        $this->assertStringContainsString('top: 37.5%', $html);
    }

    public function test_visao_de_semana_mostra_os_sete_dias(): void
    {
        $this->fakeBackend([
            $this->item(1, 'Ligar para o cliente', '2026-09-10', '14:00', 'manual', false),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['agenda' => ['visualizar']]))
            ->get('/agenda?data=2026-09-10&view=semana')
            ->assertOk()
            // Semana de 10/09/2026 (quinta) = 07 a 13 de setembro.
            ->assertSee('7 a 13 de setembro de 2026')
            ->assertSee('Ligar para o cliente');

        // Sete colunas de dia, uma por dia da semana.
        $this->assertSame(7, substr_count($response->getContent(), 'agenda-timegrid-dayhead'));
    }

    public function test_visao_de_ano_mostra_doze_meses_com_densidade(): void
    {
        $this->fakeBackend([
            $this->item(1, 'Pagar: aluguel', '2026-09-10', null, 'conta_pagar', true),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['agenda' => ['visualizar']]))
            ->get('/agenda?data=2026-09-10&view=ano')
            ->assertOk()
            ->assertSee('2026')
            ->assertSee('Janeiro')
            ->assertSee('Dezembro');

        $html = $response->getContent();

        $this->assertSame(12, substr_count($html, 'agenda-year-month-title'));
        // O único dia com compromisso recebe o nível máximo da escala do ano.
        $this->assertStringContainsString('level-4', $html);
    }

    public function test_navegacao_anda_na_unidade_da_visao_corrente(): void
    {
        $this->fakeBackend();
        $session = $this->desktopSession(['agenda' => ['visualizar']]);

        // Dia anda um dia; semana anda sete; mês anda um mês; ano anda um ano.
        $casos = [
            ['dia', '2026-09-10', '2026-09-09', '2026-09-11'],
            ['semana', '2026-09-10', '2026-09-03', '2026-09-17'],
            ['mes', '2026-09-10', '2026-08-10', '2026-10-10'],
            ['ano', '2026-09-10', '2025-09-10', '2027-09-10'],
        ];

        foreach ($casos as [$view, $cursor, $anterior, $proximo]) {
            $html = $this->withSession($session)
                ->get("/agenda?data={$cursor}&view={$view}")
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString("data={$anterior}", $html, "view {$view}: anterior");
            $this->assertStringContainsString("data={$proximo}", $html, "view {$view}: próximo");
        }
    }

    public function test_cursor_invalido_cai_para_hoje_sem_quebrar(): void
    {
        $this->fakeBackend();

        // 31 de fevereiro passa no regex e não existe no calendário.
        $this
            ->withSession($this->desktopSession(['agenda' => ['visualizar']]))
            ->get('/agenda?data=2026-02-31&view=dia')
            ->assertOk();

        $this
            ->withSession($this->desktopSession(['agenda' => ['visualizar']]))
            ->get('/agenda?data=banana&view=xpto')
            ->assertOk();
    }

    public function test_link_antigo_com_mes_continua_funcionando(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['agenda' => ['visualizar']]))
            ->get('/agenda?mes=2026-09')
            ->assertOk()
            ->assertSee('Setembro de 2026');
    }

    public function test_conectar_ao_google_e_link_get_em_aba_nova(): void
    {
        // Regressão real em produção: como POST, o Chrome bloqueava o
        // redirecionamento para accounts.google.com por `form-action 'self'`
        // — a diretiva vale também para o 302 que um formulário segue.
        // Além disso, sair da aba do painel dispararia o `pagehide` que o guard
        // de sessão lê como "navegador fechado".
        $rota = app('router')->getRoutes()->getByName('configurations.integrations.agenda-google.connect');

        $this->assertNotNull($rota);
        $this->assertContains('GET', $rota->methods());
        $this->assertNotContains('POST', $rota->methods());
    }

    public function test_tela_de_integracoes_abre_o_consentimento_em_aba_nova(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [], 'unread_count' => 0],
                'error' => null,
                'meta' => ['pagination' => [
                    'current_page' => 1, 'per_page' => 6, 'total' => 0,
                    'last_page' => 1, 'from' => 0, 'to' => 0,
                ]],
            ], 200),
            'http://127.0.0.1:8000/api/v1/agenda/google/status*' => Http::response([
                'status' => 'success',
                'data' => [
                    'settings' => ['agenda_google_client_id' => '123.apps.googleusercontent.com'],
                    'secret_status' => ['agenda_google_client_secret' => ['configured' => true]],
                    'summary' => [
                        'configured' => true, 'connected' => false,
                        'status' => 'warning', 'status_label' => 'Credenciais salvas — falta autorizar',
                    ],
                    'redirect_uri' => 'https://api-erp.jovemtech.eco.br/api/v1/agenda/google/callback',
                ],
                'error' => null, 'meta' => [],
            ], 200),
            '*' => Http::response(['status' => 'success', 'data' => [], 'error' => null, 'meta' => []], 200),
        ]);

        $html = $this
            ->withSession($this->desktopSession(['configuracoes' => ['visualizar', 'editar']]))
            ->get('/configuracoes/integracoes')
            ->assertOk()
            ->assertSee('Conectar com o Google')
            ->getContent();

        // O link precisa carregar target="_blank"; sem isso a aba do painel sai
        // do ar e o guard de sessão desloga o usuário na volta.
        $this->assertMatchesRegularExpression(
            '/<a[^>]+agenda-google\/conectar"[^>]*target="_blank"/',
            $html
        );
        // E não pode existir formulário apontando para a rota de conexão.
        $this->assertDoesNotMatchRegularExpression(
            '/<form[^>]+agenda-google\/conectar"/',
            $html
        );
    }

    public function test_integracoes_mostra_a_conta_google_vinculada(): void
    {
        $this->fakeGoogleStatus([
            'configured' => true, 'connected' => true,
            'status' => 'success', 'status_label' => 'Conectado',
            'conta_email' => 'assistencia@jovemtech.eco.br',
            'calendar_id' => 'abc@group.calendar.google.com',
        ]);

        $this
            ->withSession($this->desktopSession(['configuracoes' => ['visualizar', 'editar']]))
            ->get('/configuracoes/integracoes')
            ->assertOk()
            ->assertSee('Vinculado à conta')
            ->assertSee('assistencia@jovemtech.eco.br');
    }

    public function test_agenda_mostra_a_conta_google_que_recebe_os_lembretes(): void
    {
        $this->fakeBackend([], [
            'configured' => true, 'connected' => true,
            'status' => 'success', 'status_label' => 'Conectado',
            'conta_email' => 'assistencia@jovemtech.eco.br',
        ]);

        $this
            ->withSession($this->desktopSession(['agenda' => ['visualizar']]))
            ->get('/agenda?data=2026-09-10&view=mes')
            ->assertOk()
            ->assertSee('assistencia@jovemtech.eco.br');
    }

    public function test_conta_sem_email_conhecido_nao_quebra_a_tela(): void
    {
        // Consentimento sem o escopo de e-mail, ou falha na busca: a tela
        // precisa continuar dizendo que está conectada.
        $this->fakeGoogleStatus([
            'configured' => true, 'connected' => true,
            'status' => 'success', 'status_label' => 'Conectado',
            'conta_email' => '',
        ]);

        $this
            ->withSession($this->desktopSession(['configuracoes' => ['visualizar', 'editar']]))
            ->get('/configuracoes/integracoes')
            ->assertOk()
            ->assertSee('e-mail não informado');
    }

    /** @param array<string, mixed> $summary */
    private function fakeGoogleStatus(array $summary): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [], 'unread_count' => 0],
                'error' => null,
                'meta' => ['pagination' => [
                    'current_page' => 1, 'per_page' => 6, 'total' => 0,
                    'last_page' => 1, 'from' => 0, 'to' => 0,
                ]],
            ], 200),
            'http://127.0.0.1:8000/api/v1/agenda/google/status*' => Http::response([
                'status' => 'success',
                'data' => [
                    'settings' => [], 'secret_status' => [], 'summary' => $summary,
                    'redirect_uri' => 'https://api-erp.jovemtech.eco.br/api/v1/agenda/google/callback',
                ],
                'error' => null, 'meta' => [],
            ], 200),
            '*' => Http::response(['status' => 'success', 'data' => [], 'error' => null, 'meta' => []], 200),
        ]);
    }

    /** @return array<string, mixed> */
    private function item(
        int $id,
        string $titulo,
        string $data,
        ?string $hora,
        string $tipo,
        bool $diaInteiro,
        ?string $fimEm = null
    ): array {
        return [
            'id' => $id, 'titulo' => $titulo, 'descricao' => null,
            'tipo' => $tipo, 'origem_tipo' => $tipo === 'manual' ? null : $tipo,
            'origem_id' => $tipo === 'manual' ? null : $id, 'gerido' => $tipo !== 'manual',
            'inicio_em' => $data.'T'.($hora ?? '00:00').':00-03:00', 'fim_em' => $fimEm,
            'data' => $data, 'hora' => $hora, 'dia_inteiro' => $diaInteiro,
            'status' => 'pendente', 'prioridade' => 'normal', 'atrasado' => false,
            'responsavel_id' => 99, 'responsavel_nome' => 'Usuário de Teste',
            'cliente_id' => null, 'os_id' => null, 'lembrete_minutos' => 30,
            'google_event_id' => null, 'google_sync_estado' => 'desligado', 'concluido_em' => null,
        ];
    }

    public function test_criar_compromisso_sem_hora_vira_dia_inteiro(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['agenda' => ['visualizar', 'criar']]))
            ->post('/agenda', [
                'titulo' => 'Renovar alvará',
                'data' => '2026-09-20',
                'hora' => '',
            ])
            ->assertRedirect();

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/api/v1/agenda')) {
                return false;
            }

            $data = $request->data();

            // Campo de hora vazio significa "o dia todo", não meia-noite em ponto.
            return $data['dia_inteiro'] === true
                && $data['inicio_em'] === '2026-09-20 00:00:00';
        });
    }

    public function test_botao_de_novo_compromisso_some_sem_permissao_de_criar(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['agenda' => ['visualizar']]))
            ->post('/agenda', ['titulo' => 'X', 'data' => '2026-09-20'])
            ->assertRedirect();
    }

    /**
     * @param array<int, array<string, mixed>> $compromissos
     * @param array<string, mixed>|null $googleSummary
     */
    private function fakeBackend(array $compromissos = [], ?array $googleSummary = null): void
    {
        $googleSummary ??= [
            'configured' => false, 'connected' => false,
            'status' => 'secondary', 'status_label' => 'Aguardando configuração',
        ];

        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [], 'unread_count' => 0],
                'error' => null,
                'meta' => ['pagination' => [
                    'current_page' => 1, 'per_page' => 6, 'total' => 0,
                    'last_page' => 1, 'from' => 0, 'to' => 0,
                ]],
            ], 200),
            'http://127.0.0.1:8000/api/v1/agenda/resumo*' => Http::response([
                'status' => 'success',
                'data' => ['atrasados' => 0, 'hoje' => 0, 'proximos_7_dias' => 0, 'proximos' => []],
                'error' => null, 'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/agenda/google/status*' => Http::response([
                'status' => 'success',
                'data' => ['settings' => [], 'secret_status' => [], 'summary' => $googleSummary],
                'error' => null, 'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/agenda*' => Http::response([
                'status' => 'success',
                'data' => [
                    'compromissos' => $compromissos,
                    'tipos' => [
                        ['key' => 'manual', 'label' => 'Meus lembretes', 'icon' => 'bi-bookmark-star'],
                        ['key' => 'conta_pagar', 'label' => 'Contas a pagar', 'icon' => 'bi-arrow-down-circle'],
                        ['key' => 'retorno_pos_servico', 'label' => 'Retorno pós-serviço', 'icon' => 'bi-telephone-outbound'],
                    ],
                    'pode_ver_todos' => false,
                ],
                'error' => null, 'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/dashboard/summary*' => Http::response([
                'status' => 'success', 'data' => [], 'error' => null, 'meta' => [],
            ], 200),
        ]);
    }

    /** @param array<string, array<int, string>> $permissions */
    private function desktopSession(array $permissions): array
    {
        return [
            'desktop_theme' => 'default',
            'desktop_auth' => [
                'token' => 'desktop-session-token',
                'synced_at' => time(),
                'user' => [
                    'id' => 99,
                    'nome' => 'Usuário de Teste',
                    'email' => 'usuario@teste.local',
                    'perfil' => 'operador',
                    'group' => ['id' => 2, 'nome' => 'Técnico', 'descricao' => 'Operação', 'sistema' => true],
                    'modules' => array_keys($permissions),
                    'permissions' => $permissions,
                    'foto' => '',
                    'ativo' => true,
                ],
            ],
        ];
    }
}
