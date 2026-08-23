<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackupPanelTest extends TestCase
{
    public function test_aba_de_backup_nao_aparece_sem_permissao(): void
    {
        $this->fakeNotifications();

        $this
            ->withSession($this->desktopSession(['configuracoes' => ['visualizar']]))
            ->get('/configuracoes/sistema')
            ->assertOk()
            ->assertSee('Configurações do Sistema')
            ->assertDontSee('Gerar backup agora')
            ->assertDontSee('data-config-subpanel="backups"', false);
    }

    public function test_aba_de_backup_aparece_com_permissao(): void
    {
        $this->fakeNotifications();

        $this
            ->withSession($this->desktopSession([
                'configuracoes' => ['visualizar'],
                'backups' => ['visualizar', 'criar'],
            ]))
            ->get('/configuracoes/sistema')
            ->assertOk()
            ->assertSee('Backup e Restauração')
            ->assertSee('Gerar backup agora')
            ->assertSee('data-config-subpanel="backups"', false);
    }

    public function test_botao_de_gerar_some_sem_permissao_de_criar(): void
    {
        $this->fakeNotifications();

        // Ver o catálogo e disparar uma cópia são poderes distintos.
        $this
            ->withSession($this->desktopSession([
                'configuracoes' => ['visualizar'],
                'backups' => ['visualizar'],
            ]))
            ->get('/configuracoes/sistema')
            ->assertOk()
            ->assertSee('Backup e Restauração')
            ->assertDontSee('Gerar backup agora');
    }

    public function test_rota_de_dados_exige_permissao_de_visualizar(): void
    {
        $this->fakeNotifications();

        $this
            ->withSession($this->desktopSession(['configuracoes' => ['visualizar']]))
            ->get('/configuracoes/backups/dados')
            ->assertRedirect();
    }

    public function test_link_de_download_nunca_devolve_os_bytes_pelo_painel(): void
    {
        $this->fakeNotifications();
        Http::fake([
            'http://127.0.0.1:8000/api/v1/backups/*/link-download' => Http::response([
                'data' => ['url' => 'https://exemplo/backups/abc/arquivo?signature=x', 'nome' => 'p.tar'],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'configuracoes' => ['visualizar'],
                'backups' => ['visualizar', 'baixar'],
            ]))
            ->post('/configuracoes/backups/abc/link');

        // O BFF devolve só o link: ApiClient::download() carregaria os 130 MB
        // inteiros em memória, contra um memory_limit de 256M.
        $response->assertOk()->assertJsonPath('data.url', 'https://exemplo/backups/abc/arquivo?signature=x');
    }

    private function fakeNotifications(): void
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
                    'perfil' => 'admin',
                    'group' => ['id' => 1, 'nome' => 'Administrador', 'descricao' => 'Grupo completo', 'sistema' => true],
                    'modules' => array_keys($permissions),
                    'permissions' => $permissions,
                    'foto' => '',
                    'ativo' => true,
                ],
            ],
        ];
    }
}
