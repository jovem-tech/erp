<?php

namespace Tests\Feature\Api\V1;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Diagnostico de prontidao fiscal.
 *
 * O que este teste protege e' o numero: se ele parar de contar direito, a
 * decisao de "ja da' para emitir?" passa a ser tomada com dado errado.
 */
class ProntidaoFiscalTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();

        $this->grantGroupPermissions(1, ['clientes' => ['visualizar', 'criar', 'editar']]);
        // Grupo 3 fica sem `clientes` de proposito: e' o caso de 403.
        $this->grantGroupPermissions(3, ['os' => ['visualizar']]);
    }

    public function test_base_vazia_esta_pronta(): void
    {
        // Sem cliente nenhum nao ha pendencia — e' o unico caso em que 100% e'
        // verdade sem ninguem ter preenchido nada.
        $resposta = $this->consultar('admin.prontidao.vazia@example.com');

        $resposta->assertOk()
            ->assertJsonPath('data.areas.clientes.total', 0)
            ->assertJsonPath('data.areas.clientes.pendencias', 0)
            ->assertJsonPath('data.areas.clientes.percentual_pronto', 100.0);
    }

    public function test_conta_cliente_sem_documento_como_pendencia(): void
    {
        $this->criarCliente('Sem documento', null);
        $this->criarCliente('Vazio', '');
        $this->criarCliente('Pronto', '52998224725');

        $resposta = $this->consultar('admin.prontidao.pendente@example.com');

        $resposta->assertOk()
            ->assertJsonPath('data.areas.clientes.total', 3)
            ->assertJsonPath('data.areas.clientes.sem_documento', 2)
            ->assertJsonPath('data.areas.clientes.documento_invalido', 0)
            ->assertJsonPath('data.areas.clientes.prontos', 1)
            ->assertJsonPath('data.areas.clientes.pendencias', 2)
            ->assertJsonPath('data.pronto', false);
    }

    public function test_conta_documento_invalido_separado_de_ausente(): void
    {
        // A distincao importa: ausente se resolve pedindo ao cliente; invalido
        // e' erro de digitacao ja' gravado, e o tratamento e' outro.
        $this->criarCliente('Ausente', null);
        $this->criarCliente('Digito errado', '52998224726');
        $this->criarCliente('Sequencia repetida', '11111111111');

        $this->consultar('admin.prontidao.invalido@example.com')
            ->assertOk()
            ->assertJsonPath('data.areas.clientes.sem_documento', 1)
            ->assertJsonPath('data.areas.clientes.documento_invalido', 2)
            ->assertJsonPath('data.areas.clientes.prontos', 0)
            ->assertJsonPath('data.areas.clientes.pendencias', 3);
    }

    public function test_cnpj_alfanumerico_conta_como_pronto(): void
    {
        // Se a checagem fosse feita com `preg_replace('/\D+/')` este cliente
        // apareceria como pendencia, e alguem iria "corrigir" um CNPJ correto.
        $this->criarCliente('Alfanumerico', '12ABC34501DE35');

        $this->consultar('admin.prontidao.alfa@example.com')
            ->assertOk()
            ->assertJsonPath('data.areas.clientes.prontos', 1)
            ->assertJsonPath('data.areas.clientes.pendencias', 0);
    }

    public function test_base_completa_reporta_cem_por_cento(): void
    {
        $this->criarCliente('Um', '52998224725');
        $this->criarCliente('Dois', '11222333000181');

        $this->consultar('admin.prontidao.completa@example.com')
            ->assertOk()
            ->assertJsonPath('data.areas.clientes.percentual_pronto', 100.0)
            ->assertJsonPath('data.areas.clientes.pendencias', 0);
    }

    public function test_sem_permissao_de_clientes_recebe_403(): void
    {
        $usuario = $this->createUserRecord([
            'nome' => 'Atendente',
            'email' => 'atendente.prontidao@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]);

        $token = $this->loginAndGetToken($usuario->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/fiscal/prontidao')
            ->assertForbidden();
    }

    public function test_conta_servico_ativo_sem_codigo_de_tributacao(): void
    {
        DB::table('servicos')->insert([
            ['nome' => 'Troca de tela', 'status' => 'ativo', 'codigo_tributacao_nacional' => null],
            ['nome' => 'Formatacao', 'status' => 'ativo', 'codigo_tributacao_nacional' => '010701'],
            // Encerrado nao conta: item fora de catalogo nao vai para nota, e
            // inflar o numero faria o relatorio exagerar — e relatorio que
            // exagera deixa de ser usado.
            ['nome' => 'Servico antigo', 'status' => 'encerrado', 'codigo_tributacao_nacional' => null],
        ]);

        $this->consultar('admin.prontidao.servico@example.com')
            ->assertOk()
            ->assertJsonPath('data.areas.servicos.total', 2)
            ->assertJsonPath('data.areas.servicos.sem_codigo_tributacao', 1)
            ->assertJsonPath('data.areas.servicos.prontos', 1);
    }

    public function test_conta_peca_ativa_sem_ncm(): void
    {
        DB::table('pecas')->insert([
            ['nome' => 'Tela LCD', 'status' => 'ativo', 'ncm' => null],
            ['nome' => 'Bateria', 'status' => 'ativo', 'ncm' => '85076000'],
            ['nome' => 'Peca antiga', 'status' => 'encerrado', 'ncm' => null],
        ]);

        $this->consultar('admin.prontidao.peca@example.com')
            ->assertOk()
            ->assertJsonPath('data.areas.pecas.total', 2)
            ->assertJsonPath('data.areas.pecas.sem_ncm', 1)
            ->assertJsonPath('data.areas.pecas.prontos', 1);
    }

    public function test_lista_os_campos_fiscais_faltando_na_empresa(): void
    {
        DB::table('configuracoes')->insert([
            ['chave' => 'empresa_razao_social', 'valor' => 'Assistencia LTDA', 'tipo' => 'texto'],
            ['chave' => 'empresa_cnpj', 'valor' => '11222333000181', 'tipo' => 'texto'],
        ]);

        $resposta = $this->consultar('admin.prontidao.empresa@example.com')->assertOk();

        $faltando = $resposta->json('data.areas.empresa.campos_faltando');

        $this->assertNotContains('Razão social', $faltando);
        $this->assertNotContains('CNPJ', $faltando);
        $this->assertContains('Código IBGE do município', $faltando);
        $this->assertContains('Inscrição municipal', $faltando);
    }

    private function criarCliente(string $nome, ?string $documento): void
    {
        Client::query()->create([
            'tipo_pessoa' => 'fisica',
            'nome_razao' => $nome,
            'cpf_cnpj' => $documento,
            'telefone1' => '(11) 95555-0000',
            'status_cadastro' => 'completo',
        ]);
    }

    private function consultar(string $email): \Illuminate\Testing\TestResponse
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => $email,
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/fiscal/prontidao');
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-prontidao',
        ]);

        return (string) $response->json('data.access_token');
    }
}
