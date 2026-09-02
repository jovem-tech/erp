<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Instalação do certificado A1 pela tela de Integrações.
 *
 * Existe porque o sistema é vendido: o novo dono precisa instalar o próprio
 * certificado sem `scp` nem editar `.env`. O que estes testes protegem é a
 * porta de entrada — que ela apareça para quem administra, e não apareça para
 * quem não administra.
 */
class CertificadoFiscalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_aba_do_certificado_aparece_em_integracoes(): void
    {
        // A queixa que originou isto foi "não acho onde inserir o certificado".
        $this->fakeIntegracoes();

        $this->withSession($this->desktopSession(['configuracoes' => ['visualizar', 'editar']]))
            ->get('/configuracoes/integracoes')
            ->assertOk()
            ->assertSee('Certificado A1')
            ->assertSee('Enviar certificado')
            ->assertSee('Arquivo do certificado');
    }

    public function test_quem_nao_administra_ve_a_aba_mas_nao_o_upload(): void
    {
        $this->fakeIntegracoes();

        $resposta = $this->withSession($this->desktopSession(['configuracoes' => ['visualizar']]))
            ->get('/configuracoes/integracoes')
            ->assertOk();

        $resposta->assertSee('Certificado A1');
        $resposta->assertDontSee('Enviar certificado');
    }

    public function test_instalar_encaminha_arquivo_e_senha_e_confirma(): void
    {
        $this->fakeIntegracoes([
            'http://127.0.0.1:8000/api/v1/fiscal/certificado' => Http::response([
                'status' => 'success',
                'data' => ['certificado' => [
                    'instalado' => true,
                    'usavel' => true,
                    'titular' => 'JOVEM TECH',
                    'documento_titular' => '11222333000181',
                    'expira_em' => '2027-09-01',
                    'dias_ate_vencimento' => 365,
                    'problemas' => [],
                ]],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $this->withSession($this->desktopSession(['configuracoes' => ['visualizar', 'editar']]))
            ->post('/fiscal/certificado', [
                'certificado_arquivo' => UploadedFile::fake()->createWithContent('cert.pfx', 'conteudo-binario'),
                'certificado_senha' => 'segredo',
            ])
            ->assertRedirect(route('configurations.integrations.index'))
            ->assertSessionHas('success');
    }

    public function test_senha_errada_volta_com_erro_de_validacao(): void
    {
        $this->fakeIntegracoes();

        $this->withSession($this->desktopSession(['configuracoes' => ['visualizar', 'editar']]))
            ->post('/fiscal/certificado', ['certificado_senha' => 'só a senha'])
            ->assertSessionHasErrors('certificado_arquivo');
    }

    public function test_sem_permissao_de_editar_nao_instala(): void
    {
        $this->fakeIntegracoes();

        $this->withSession($this->desktopSession(['configuracoes' => ['visualizar']]))
            ->post('/fiscal/certificado', [
                'certificado_arquivo' => UploadedFile::fake()->createWithContent('cert.pfx', 'x'),
                'certificado_senha' => 'segredo',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Você não tem permissão para acessar este recurso.');
    }

    /**
     * @param  array<string, mixed>  $extras
     */
    private function fakeIntegracoes(array $extras = []): void
    {
        Http::fake(array_merge([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            '*' => Http::response(['status' => 'success', 'data' => [], 'error' => null, 'meta' => []], 200),
        ], $extras));
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
