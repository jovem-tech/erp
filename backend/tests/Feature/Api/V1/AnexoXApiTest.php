<?php

namespace Tests\Feature\Api\V1;

use App\Models\AnexoXFechamento;
use App\Services\Fiscal\AnexoXService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * API do Anexo X — leitura, PDFs, fechamento e reabertura.
 */
class AnexoXApiTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private string $competencia;

    private int $clienteId;

    private int $equipamentoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();
        $this->grantGroupPermissions(1, [
            'fiscal' => ['visualizar', 'criar', 'encerrar', 'editar'],
        ]);
        // Grupo 2 vê o fiscal, mas não encerra competência nem ajusta.
        $this->grantGroupPermissions(2, [
            'fiscal' => ['visualizar'],
        ]);

        $this->clienteId = $this->createClientRecord();
        $this->equipamentoId = $this->createEquipmentRecord($this->clienteId);
        $this->competencia = now()->format('Y-m');
    }

    private function osEntregue(float $pecas, float $maoObra): int
    {
        return $this->createOrderRecord([
            'cliente_id' => $this->clienteId,
            'equipamento_id' => $this->equipamentoId,
            'status' => 'entregue_reparado_pago',
            'data_conclusao' => now()->startOfMonth()->addDays(5),
            'data_entrega' => now()->startOfMonth()->addDays(6),
            'valor_pecas' => $pecas,
            'valor_mao_obra' => $maoObra,
            'valor_total' => $pecas + $maoObra,
            'desconto' => 0,
            'valor_final' => $pecas + $maoObra,
        ]);
    }

    private function comoAdmin(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);
    }

    public function test_relatorio_devolve_as_dez_linhas_do_formulario(): void
    {
        $this->comoAdmin();
        $this->osEntregue(200.00, 300.00);

        $resposta = $this->getJson('/api/v1/fiscal/anexo-x?competencia='.$this->competencia);

        $resposta->assertOk();
        $resposta->assertJsonPath('data.anexo_x.linhas.x.valor', 500.00);
        $resposta->assertJsonPath('data.anexo_x.linhas.iii.valor', 200.00);
        $resposta->assertJsonPath('data.anexo_x.regime', 'competencia');

        foreach (['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x'] as $linha) {
            $resposta->assertJsonStructure(['data' => ['anexo_x' => ['linhas' => [$linha => ['rotulo', 'valor']]]]]);
        }
    }

    public function test_seletor_de_regime_muda_a_apuracao(): void
    {
        $this->comoAdmin();
        $this->osEntregue(200.00, 300.00);

        // Sem baixa registrada, o regime de caixa não vê nada.
        $this->getJson('/api/v1/fiscal/anexo-x?competencia='.$this->competencia.'&regime=caixa')
            ->assertOk()
            ->assertJsonPath('data.anexo_x.linhas.x.valor', 0.0)
            ->assertJsonPath('data.anexo_x.regime', 'caixa');
    }

    public function test_regime_invalido_cai_em_competencia_em_vez_de_derrubar_a_tela(): void
    {
        $this->comoAdmin();

        $this->getJson('/api/v1/fiscal/anexo-x?competencia='.$this->competencia.'&regime=inventado')
            ->assertOk()
            ->assertJsonPath('data.anexo_x.regime', 'competencia');
    }

    public function test_pdf_do_formulario_e_da_relacao_sao_arquivos_diferentes(): void
    {
        $this->comoAdmin();
        $this->osEntregue(200.00, 300.00);

        $formulario = $this->get('/api/v1/fiscal/anexo-x/pdf?competencia='.$this->competencia);
        $formulario->assertOk();
        $formulario->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('anexo-x-'.$this->competencia.'.pdf', $formulario->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $formulario->getContent());

        $relacao = $this->get('/api/v1/fiscal/anexo-x/documentos/pdf?competencia='.$this->competencia);
        $relacao->assertOk();
        $this->assertStringContainsString('anexo-x-documentos-', $relacao->headers->get('content-disposition'));
    }

    // ----------------------------------------------------------- fechamento

    public function test_fechar_congela_a_competencia(): void
    {
        $this->comoAdmin();
        $this->osEntregue(200.00, 300.00);

        $this->postJson('/api/v1/fiscal/anexo-x/fechamento', [
            'competencia' => $this->competencia,
            'regime' => 'competencia',
        ])
            ->assertOk()
            ->assertJsonPath('data.fechamento.versao', 1)
            ->assertJsonPath('data.anexo_x.origem_dos_valores', 'fechamento');

        $this->assertSame(1, AnexoXFechamento::query()->count());
    }

    public function test_fechar_duas_vezes_a_mesma_competencia_e_recusado(): void
    {
        $this->comoAdmin();
        $this->osEntregue(200.00, 300.00);

        $payload = ['competencia' => $this->competencia, 'regime' => 'competencia'];

        $this->postJson('/api/v1/fiscal/anexo-x/fechamento', $payload)->assertOk();
        $this->postJson('/api/v1/fiscal/anexo-x/fechamento', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ANEXO_X_JA_FECHADO');
    }

    /**
     * Nas rotas de leitura, competência torta cai no mês corrente. Aqui não
     * pode: congelaria o mês errado.
     */
    public function test_fechar_recusa_competencia_malformada_em_vez_de_adivinhar(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/fiscal/anexo-x/fechamento', ['competencia' => 'setembro'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ANEXO_X_COMPETENCIA_INVALIDA');

        $this->assertSame(0, AnexoXFechamento::query()->count());
    }

    // ----------------------------------------------------------- reabertura

    public function test_reabrir_exige_credencial_de_administrador(): void
    {
        $this->comoAdmin();
        $this->osEntregue(200.00, 300.00);
        $this->postJson('/api/v1/fiscal/anexo-x/fechamento', ['competencia' => $this->competencia])->assertOk();

        $this->postJson('/api/v1/fiscal/anexo-x/fechamento/reabertura', [
            'competencia' => $this->competencia,
            'motivo' => 'Devolução lançada com atraso no mês',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ANEXO_X_ADMIN_AUTH_REQUIRED');

        $this->assertNotNull(
            AnexoXFechamento::query()->vigente($this->competencia, AnexoXService::REGIME_COMPETENCIA)->first()
        );
    }

    public function test_reabrir_exige_motivo(): void
    {
        $this->comoAdmin();
        $this->osEntregue(200.00, 300.00);
        $this->postJson('/api/v1/fiscal/anexo-x/fechamento', ['competencia' => $this->competencia])->assertOk();

        $this->postJson('/api/v1/fiscal/anexo-x/fechamento/reabertura', [
            'competencia' => $this->competencia,
            'motivo' => 'erro',
            'admin_email' => 'admin@teste.local',
            'admin_password' => 'segredo123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ANEXO_X_MOTIVO_OBRIGATORIO');
    }

    public function test_reabrir_com_credencial_valida_libera_a_competencia(): void
    {
        $admin = $this->createUserRecord([
            'grupo_id' => 1,
            // `perfil = admin` é uma das duas portas que o
            // AdminCredentialVerifier aceita para confirmar ato sensível.
            'perfil' => 'admin',
            'email' => 'admin@teste.local',
            'senha' => bcrypt('segredo123'),
        ]);
        Sanctum::actingAs($admin, ['*']);

        $this->osEntregue(200.00, 300.00);
        $this->postJson('/api/v1/fiscal/anexo-x/fechamento', ['competencia' => $this->competencia])->assertOk();

        $this->postJson('/api/v1/fiscal/anexo-x/fechamento/reabertura', [
            'competencia' => $this->competencia,
            'motivo' => 'Nota fiscal emitida depois do fechamento',
            'admin_email' => 'admin@teste.local',
            'admin_password' => 'segredo123',
        ])
            ->assertOk()
            ->assertJsonPath('data.fechamento.status', AnexoXFechamento::STATUS_REABERTO)
            ->assertJsonPath('data.fechamento.motivo_reabertura', 'Nota fiscal emitida depois do fechamento')
            ->assertJsonPath('data.anexo_x.origem_dos_valores', 'ao_vivo');
    }

    // ------------------------------------------------------------ resumo anual

    public function test_resumo_anual_devolve_os_doze_meses_e_o_grafico(): void
    {
        $this->comoAdmin();
        $this->osEntregue(200.00, 300.00);

        $resposta = $this->getJson('/api/v1/fiscal/anexo-x/resumo?ano='.now()->format('Y'));

        $resposta->assertOk()
            ->assertJsonCount(12, 'data.resumo.meses')
            ->assertJsonPath('data.resumo.regime_que_conta_para_o_limite', 'competencia')
            ->assertJsonPath('data.resumo.mostrar_industria', false);

        $this->assertCount(12, $resposta->json('data.resumo.grafico.regimes.competencia.bruto'));
        $this->assertCount(12, $resposta->json('data.resumo.grafico.regimes.caixa.bruto'));
    }

    public function test_resumo_sem_ano_cai_no_ano_corrente(): void
    {
        $this->comoAdmin();

        $this->getJson('/api/v1/fiscal/anexo-x/resumo')
            ->assertOk()
            ->assertJsonPath('data.resumo.ano', (int) now()->format('Y'));
    }

    // ---------------------------------------------------------------- ajustes

    public function test_lancar_ajuste_registra_e_reflete_no_relatorio(): void
    {
        $this->comoAdmin();
        $this->osEntregue(0.0, 210.00);

        $this->postJson('/api/v1/fiscal/anexo-x/ajustes', [
            'competencia' => $this->competencia,
            'regime' => 'competencia',
            'linha' => 'vii',
            'valor' => 90.00,
            'motivo' => 'Serviço cobrado em dinheiro, fora do sistema',
        ])
            ->assertOk()
            ->assertJsonPath('data.linhas.vii.valor', 300.00)
            ->assertJsonPath('data.linhas.vii.calculado', 210.00)
            ->assertJsonPath('data.linhas.vii.ajuste', 90.00)
            ->assertJsonPath('data.ajustes.quantidade', 1);

        $this->getJson('/api/v1/fiscal/anexo-x?competencia='.$this->competencia)
            ->assertOk()
            ->assertJsonPath('data.anexo_x.linhas.x.valor', 300.00);
    }

    public function test_lancar_ajuste_em_linha_calculada_e_recusado(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/fiscal/anexo-x/ajustes', [
            'competencia' => $this->competencia,
            'linha' => 'iii',
            'valor' => 50.00,
            'motivo' => 'Tentativa numa linha que é soma',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ANEXO_X_LINHA_NAO_AJUSTAVEL');
    }

    public function test_lancar_ajuste_com_motivo_curto_e_recusado(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/fiscal/anexo-x/ajustes', [
            'competencia' => $this->competencia,
            'linha' => 'vii',
            'valor' => 50.00,
            'motivo' => 'erro',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ANEXO_X_MOTIVO_OBRIGATORIO');
    }

    public function test_lancar_ajuste_de_valor_zero_e_recusado(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/fiscal/anexo-x/ajustes', [
            'competencia' => $this->competencia,
            'linha' => 'vii',
            'valor' => 0,
            'motivo' => 'Ajuste sem valor nenhum',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ANEXO_X_AJUSTE_VALOR_INVALIDO');
    }

    public function test_lancar_ajuste_em_competencia_fechada_e_recusado(): void
    {
        $this->comoAdmin();
        $this->osEntregue(0.0, 100.00);
        $this->postJson('/api/v1/fiscal/anexo-x/fechamento', ['competencia' => $this->competencia])->assertOk();

        $this->postJson('/api/v1/fiscal/anexo-x/ajustes', [
            'competencia' => $this->competencia,
            'linha' => 'vii',
            'valor' => 50.00,
            'motivo' => 'Tentativa com o mês encerrado',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ANEXO_X_COMPETENCIA_FECHADA');
    }

    public function test_ajuste_aplicado_nos_dois_regimes_cria_dois_lancamentos(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/fiscal/anexo-x/ajustes', [
            'competencia' => $this->competencia,
            'linha' => 'vii',
            'valor' => 40.00,
            'motivo' => 'Receita fora do sistema, nos dois regimes',
            'aplicar_no_outro_regime' => true,
        ])->assertOk();

        foreach (['competencia', 'caixa'] as $regime) {
            $this->getJson('/api/v1/fiscal/anexo-x/ajustes?competencia='.$this->competencia.'&regime='.$regime)
                ->assertOk()
                ->assertJsonPath('data.ajustes.quantidade', 1);
        }
    }

    public function test_cancelar_ajuste_remove_da_apuracao_mas_mantem_na_trilha(): void
    {
        $this->comoAdmin();
        $this->osEntregue(0.0, 100.00);

        $criado = $this->postJson('/api/v1/fiscal/anexo-x/ajustes', [
            'competencia' => $this->competencia,
            'linha' => 'vii',
            'valor' => 40.00,
            'motivo' => 'Lançamento a ser cancelado depois',
        ])->assertOk();

        $id = $criado->json('data.ajustes.por_linha.vii.0.id');

        $this->postJson('/api/v1/fiscal/anexo-x/ajustes/'.$id.'/cancelamento', [
            'motivo' => 'Lançado na competência errada',
        ])
            ->assertOk()
            ->assertJsonPath('data.linhas.vii.valor', 100.00)
            ->assertJsonPath('data.ajustes.quantidade', 0);

        $this->assertNotNull(
            $this->getJson('/api/v1/fiscal/anexo-x/ajustes?competencia='.$this->competencia)
                ->json('data.ajustes.por_linha.vii.0.cancelado_em')
        );
    }

    public function test_cancelar_ajuste_inexistente_devolve_404(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/fiscal/anexo-x/ajustes/99999/cancelamento', [
            'motivo' => 'Cancelando um ajuste que não existe',
        ])->assertStatus(404);
    }

    // ----------------------------------------------------------- permissões

    public function test_user_without_permission_cannot_view_anexo_x(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 3]), ['*']);

        $this->getJson('/api/v1/fiscal/anexo-x?competencia='.$this->competencia)->assertForbidden();
        $this->get('/api/v1/fiscal/anexo-x/pdf?competencia='.$this->competencia)->assertForbidden();
        $this->get('/api/v1/fiscal/anexo-x/documentos/pdf?competencia='.$this->competencia)->assertForbidden();
    }

    /**
     * Ver o relatório e congelar a competência são atos de peso diferente.
     */
    public function test_user_without_encerrar_cannot_close_the_month(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 2]), ['*']);

        $this->getJson('/api/v1/fiscal/anexo-x?competencia='.$this->competencia)->assertOk();

        $this->postJson('/api/v1/fiscal/anexo-x/fechamento', ['competencia' => $this->competencia])
            ->assertForbidden();

        $this->postJson('/api/v1/fiscal/anexo-x/fechamento/reabertura', [
            'competencia' => $this->competencia,
            'motivo' => 'Tentativa sem permissão de encerrar',
        ])->assertForbidden();
    }

    public function test_user_without_permission_cannot_view_resumo(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 3]), ['*']);

        $this->getJson('/api/v1/fiscal/anexo-x/resumo')->assertForbidden();
        $this->getJson('/api/v1/fiscal/anexo-x/ajustes?competencia='.$this->competencia)->assertForbidden();
    }

    /**
     * Corrigir o relatório e apenas vê-lo são poderes diferentes.
     */
    public function test_user_without_editar_cannot_lancar_ajuste(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 2]), ['*']);

        $this->getJson('/api/v1/fiscal/anexo-x/resumo')->assertOk();

        $this->postJson('/api/v1/fiscal/anexo-x/ajustes', [
            'competencia' => $this->competencia,
            'linha' => 'vii',
            'valor' => 50.00,
            'motivo' => 'Tentativa sem permissão de editar',
        ])->assertForbidden();

        $this->postJson('/api/v1/fiscal/anexo-x/ajustes/1/cancelamento', [
            'motivo' => 'Tentativa sem permissão de editar',
        ])->assertForbidden();
    }

    public function test_rotas_do_anexo_x_exigem_autenticacao(): void
    {
        $this->getJson('/api/v1/fiscal/anexo-x')->assertUnauthorized();
        $this->postJson('/api/v1/fiscal/anexo-x/fechamento', [])->assertUnauthorized();
        $this->getJson('/api/v1/fiscal/anexo-x/resumo')->assertUnauthorized();
        $this->postJson('/api/v1/fiscal/anexo-x/ajustes', [])->assertUnauthorized();
    }
}
