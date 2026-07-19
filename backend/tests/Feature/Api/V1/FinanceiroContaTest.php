<?php

namespace Tests\Feature\Api\V1;

use App\Models\Financeiro;
use App\Models\FinanceiroMovimento;
use App\Models\FinanceiroMovimentoCartao;
use App\Services\Auth\RbacAuthorizationService;
use App\Services\Financeiro\FinanceiroContaService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PDOException;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class FinanceiroContaTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private int $authenticatedUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();
        $this->grantGroupPermissions(1, [
            'financeiro' => ['visualizar', 'criar', 'editar', 'excluir'],
            'contas_saldos' => ['visualizar', 'criar', 'editar'],
            'os' => ['visualizar'],
        ]);

        $user = $this->createUserRecord(['grupo_id' => 1]);
        $this->authenticatedUserId = (int) $user->id;
        Sanctum::actingAs($user, ['*']);
    }

    public function test_finance_permissions_do_not_unlock_accounts_module(): void
    {
        $moduleId = (int) DB::table('modulos')
            ->where('slug', 'contas_saldos')
            ->value('id');

        DB::table('grupo_permissoes')
            ->where('grupo_id', 1)
            ->where('modulo_id', $moduleId)
            ->delete();
        app(RbacAuthorizationService::class)->forgetUser($this->authenticatedUserId);

        $this->getJson('/api/v1/financeiro')->assertOk();
        $this->getJson('/api/v1/financeiro/contas')->assertForbidden();
        $this->getJson('/api/v1/financeiro/contas/relatorios/consolidado')->assertForbidden();
        $this->postJson('/api/v1/financeiro/contas', [
            'nome' => 'Conta sem acesso',
            'tipo' => 'banco',
            'data_inicio_controle' => now()->toDateString(),
            'saldo_inicial' => 0,
        ])->assertForbidden();
    }

    public function test_accounts_view_permission_does_not_allow_mutations(): void
    {
        $moduleId = (int) DB::table('modulos')
            ->where('slug', 'contas_saldos')
            ->value('id');
        $viewPermissionId = (int) DB::table('permissoes')
            ->where('slug', 'visualizar')
            ->value('id');

        DB::table('grupo_permissoes')
            ->where('grupo_id', 1)
            ->where('modulo_id', $moduleId)
            ->delete();
        DB::table('grupo_permissoes')->insert([
            'grupo_id' => 1,
            'modulo_id' => $moduleId,
            'permissao_id' => $viewPermissionId,
        ]);
        app(RbacAuthorizationService::class)->forgetUser($this->authenticatedUserId);

        $this->getJson('/api/v1/financeiro/contas')->assertOk();
        $this->getJson('/api/v1/financeiro/contas/relatorios/consolidado')->assertOk();
        $this->postJson('/api/v1/financeiro/contas', [
            'nome' => 'Conta bloqueada',
            'tipo' => 'banco',
            'data_inicio_controle' => now()->toDateString(),
            'saldo_inicial' => 0,
        ])->assertForbidden();
    }

    public function test_opening_balances_build_position_without_creating_revenue(): void
    {
        $cash = $this->createAccount('Caixa físico', 'caixa', 3000, ['dinheiro']);
        $inter = $this->createAccount('Conta Inter', 'banco', 1900, ['pix']);
        $tom = $this->createAccount('TOM a receber', 'adquirente', 3000, ['cartao_credito', 'cartao_debito']);

        $this->assertDatabaseCount('financeiro', 0);
        $this->assertDatabaseCount('financeiro_movimentos', 0);
        $this->assertDatabaseCount('financeiro_conta_movimentos', 3);

        $dashboard = $this->getJson('/api/v1/financeiro/contas?mes='.now()->format('Y-m'));

        $dashboard->assertOk()
            ->assertJsonPath('data.resumo.disponivel_operacional', 7900.0)
            ->assertJsonPath('data.resumo.total_em_contas', 7900.0)
            ->assertJsonPath('data.resumo.cartao_a_receber', 0.0)
            ->assertJsonPath('data.resumo.posicao_total', 7900.0)
            ->assertJsonPath('data.opcoes.contas_padrao.dinheiro', $cash)
            ->assertJsonPath('data.opcoes.contas_padrao.pix', $inter)
            ->assertJsonPath('data.opcoes.contas_padrao.cartao_credito', $tom);
    }

    public function test_consolidated_report_reconciles_operations_adjustments_and_internal_transfers(): void
    {
        $cash = $this->createAccount('Caixa fÃ­sico', 'caixa', 3000, ['dinheiro']);
        $inter = $this->createAccount('Conta Inter', 'banco', 1900, ['pix']);
        $reserve = $this->createAccount('Reserva de lucro', 'reserva', 0, [], false);
        $clientId = $this->createClientRecord();
        $title = $this->createReceivable($clientId, 100);

        $this->postJson("/api/v1/financeiro/{$title}/baixar", [
            'valor_movimento' => 100,
            'forma_pagamento' => 'pix',
        ])->assertOk();
        $this->postJson('/api/v1/financeiro/contas/'.$cash.'/ajustes', [
            'natureza' => 'saida',
            'valor' => 25,
            'data_movimento' => now()->toDateString(),
            'descricao' => 'DiferenÃ§a da contagem fÃ­sica',
        ])->assertCreated();
        $this->postJson('/api/v1/financeiro/contas-transferencias', [
            'conta_origem_id' => $inter,
            'conta_destino_id' => $reserve,
            'valor' => 900,
            'data_transferencia' => now()->toDateString(),
            'descricao' => 'SeparaÃ§Ã£o do lucro lÃ­quido',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/financeiro/contas/relatorios/consolidado?mes='.now()->format('Y-m'));
        $response->assertOk()
            ->assertJsonPath('data.resumo.saldo_anterior', 0.0)
            ->assertJsonPath('data.resumo.saldos_iniciais_periodo', 4900.0)
            ->assertJsonPath('data.resumo.entradas_operacionais', 100.0)
            ->assertJsonPath('data.resumo.saidas_operacionais', 0.0)
            ->assertJsonPath('data.resumo.ajustes_saida', 25.0)
            ->assertJsonPath('data.resumo.transferencias_entrada', 900.0)
            ->assertJsonPath('data.resumo.transferencias_saida', 900.0)
            ->assertJsonPath('data.resumo.conferencia_transferencias', 0.0)
            ->assertJsonPath('data.resumo.saldo_final', 4975.0)
            ->assertJsonPath('data.resumo.disponivel_operacional', 4075.0)
            ->assertJsonPath('data.resumo.reservado', 900.0)
            ->assertJsonPath('data.resumo.posicao_total', 4975.0);

        $accounts = collect($response->json('data.contas'));
        $this->assertSame(2975.0, $accounts->firstWhere('id', $cash)['saldo_final']);
        $this->assertSame(1100.0, $accounts->firstWhere('id', $inter)['saldo_final']);
        $this->assertSame(900.0, $accounts->firstWhere('id', $reserve)['saldo_final']);
    }

    public function test_payment_uses_default_account_and_requires_account_when_no_default_exists(): void
    {
        $inter = $this->createAccount('Conta Inter', 'banco', 1000, ['pix']);
        $cash = $this->createAccount('Caixa físico', 'caixa', 500, []);
        $clientId = $this->createClientRecord();

        $title = $this->createReceivable($clientId, 100);
        $this->postJson("/api/v1/financeiro/{$title}/baixar", [
            'valor_movimento' => 100,
            'forma_pagamento' => 'pix',
        ])->assertOk();

        $movement = FinanceiroMovimento::query()->where('financeiro_id', $title)->firstOrFail();
        $this->assertSame($inter, (int) $movement->conta_financeira_id);

        $unmappedTitle = $this->createReceivable($clientId, 50);
        $this->postJson("/api/v1/financeiro/{$unmappedTitle}/baixar", [
            'valor_movimento' => 50,
            'forma_pagamento' => 'boleto',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'FINANCEIRO_BAIXA_FAILED');

        $this->postJson("/api/v1/financeiro/{$unmappedTitle}/baixar", [
            'valor_movimento' => 50,
            'forma_pagamento' => 'boleto',
            'conta_financeira_id' => $cash,
        ])->assertOk();

        $dashboard = $this->getJson('/api/v1/financeiro/contas');
        $dashboard->assertOk()
            ->assertJsonPath('data.resumo.total_em_contas', 1650.0);

        $statement = $this->getJson('/api/v1/financeiro/contas/'.$inter.'/extrato?data_inicio='.now()->toDateString().'&data_fim='.now()->toDateString());
        $statement->assertOk()
            ->assertJsonPath('data.periodo.entradas', 1100.0)
            ->assertJsonPath('data.periodo.saidas', 0.0)
            ->assertJsonPath('data.paginacao.total', 2);
        $this->assertSame(
            ['financeiro', 'patrimonial'],
            collect($statement->json('data.movimentos'))->pluck('origem')->sort()->values()->all()
        );
    }

    public function test_database_errors_are_logged_without_exposing_sql_to_the_client(): void
    {
        $service = \Mockery::mock(FinanceiroContaService::class);
        $service->shouldReceive('dashboard')
            ->once()
            ->andThrow(new QueryException(
                'mysql',
                'select segredo_interno from tabela_privada',
                [],
                new PDOException('Falha confidencial do banco')
            ));
        $this->app->instance(FinanceiroContaService::class, $service);

        $response = $this->getJson('/api/v1/financeiro/contas');

        $response->assertStatus(500)
            ->assertJsonPath('error.code', 'FINANCEIRO_CONTAS_QUERY_FAILED')
            ->assertJsonPath('error.message', 'Não foi possível concluir a operação financeira.');
        $this->assertStringNotContainsString('segredo_interno', (string) $response->getContent());
        $this->assertStringNotContainsString('tabela_privada', (string) $response->getContent());
        $this->assertStringNotContainsString('Falha confidencial', (string) $response->getContent());
    }

    public function test_card_is_pending_until_effective_credit_and_enters_at_net_value(): void
    {
        $catalog = $this->seedCardCatalog();
        $tom = $this->createAccount('TOM', 'adquirente', 0, ['cartao_credito']);
        $clientId = $this->createClientRecord();
        $title = $this->createReceivable($clientId, 100);

        $this->postJson("/api/v1/financeiro/{$title}/baixar", [
            'valor_movimento' => 100,
            'forma_pagamento' => 'cartao_credito',
            'operadora_id' => $catalog['operadora_id'],
            'bandeira_id' => $catalog['bandeira_id'],
            'modalidade' => 'credito',
            'parcelas' => 1,
        ])->assertOk();

        $card = FinanceiroMovimentoCartao::query()->firstOrFail();
        $this->assertSame($tom, (int) $card->movimento->conta_financeira_id);

        $pending = $this->getJson('/api/v1/financeiro/contas');
        $pending->assertOk()
            ->assertJsonPath('data.resumo.total_em_contas', 0.0)
            ->assertJsonPath('data.resumo.cartao_a_receber', 96.81)
            ->assertJsonPath('data.resumo.posicao_total', 96.81);
        $this->getJson('/api/v1/financeiro/contas/relatorios/consolidado')
            ->assertOk()
            ->assertJsonPath('data.resumo.entradas_operacionais', 0.0)
            ->assertJsonPath('data.resumo.cartao_a_receber', 96.81)
            ->assertJsonPath('data.resumo.posicao_total', 96.81);

        $this->postJson('/api/v1/financeiro/contas-cartoes/'.$card->id.'/confirmar', [
            'data_credito_efetivo' => now()->toDateString(),
        ])->assertOk();

        $available = $this->getJson('/api/v1/financeiro/contas');
        $available->assertOk()
            ->assertJsonPath('data.resumo.total_em_contas', 96.81)
            ->assertJsonPath('data.resumo.cartao_a_receber', 0.0)
            ->assertJsonPath('data.resumo.posicao_total', 96.81);
        $this->getJson('/api/v1/financeiro/contas/relatorios/consolidado')
            ->assertOk()
            ->assertJsonPath('data.resumo.entradas_operacionais', 96.81)
            ->assertJsonPath('data.resumo.cartao_a_receber', 0.0)
            ->assertJsonPath('data.resumo.posicao_total', 96.81);
    }

    public function test_transfer_moves_balance_atomically_without_creating_dre_entries_and_can_be_cancelled(): void
    {
        $inter = $this->createAccount('Conta Inter', 'banco', 1900, ['pix']);
        $reserve = $this->createAccount('Reserva de lucro', 'reserva', 0, [], false);
        $financialRowsBefore = Financeiro::query()->count();

        $transfer = $this->postJson('/api/v1/financeiro/contas-transferencias', [
            'conta_origem_id' => $inter,
            'conta_destino_id' => $reserve,
            'valor' => 900,
            'data_transferencia' => now()->toDateString(),
            'descricao' => 'Separação do lucro líquido',
        ])->assertCreated();

        $transferId = (int) $transfer->json('data.transferencia.id');
        $this->assertSame($financialRowsBefore, Financeiro::query()->count());
        $this->assertDatabaseCount('financeiro_conta_movimentos', 3);

        $moved = $this->getJson('/api/v1/financeiro/contas');
        $moved->assertOk()
            ->assertJsonPath('data.resumo.disponivel_operacional', 1000.0)
            ->assertJsonPath('data.resumo.reservado', 900.0)
            ->assertJsonPath('data.resumo.total_em_contas', 1900.0);

        $this->postJson("/api/v1/financeiro/contas-transferencias/{$transferId}/cancelar", [
            'motivo' => 'Transferência registrada em conta incorreta',
        ])->assertOk();

        $cancelled = $this->getJson('/api/v1/financeiro/contas');
        $cancelled->assertOk()
            ->assertJsonPath('data.resumo.disponivel_operacional', 1900.0)
            ->assertJsonPath('data.resumo.reservado', 0.0)
            ->assertJsonPath('data.resumo.total_em_contas', 1900.0);
    }

    public function test_transfer_rejects_insufficient_origin_balance(): void
    {
        $origin = $this->createAccount('Caixa', 'caixa', 100, ['dinheiro']);
        $destination = $this->createAccount('Reserva', 'reserva', 0, [], false);

        $this->postJson('/api/v1/financeiro/contas-transferencias', [
            'conta_origem_id' => $origin,
            'conta_destino_id' => $destination,
            'valor' => 150,
            'data_transferencia' => now()->toDateString(),
            'descricao' => 'Transferência acima do saldo',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'FINANCEIRO_TRANSFERENCIA_FAILED');

        $this->assertDatabaseCount('financeiro_transferencias', 0);
    }

    public function test_reconciliation_adjustment_changes_only_the_patrimonial_balance(): void
    {
        $cash = $this->createAccount('Caixa', 'caixa', 3000, ['dinheiro']);
        $financialRowsBefore = Financeiro::query()->count();

        $this->postJson('/api/v1/financeiro/contas/'.$cash.'/ajustes', [
            'natureza' => 'saida',
            'valor' => 25,
            'data_movimento' => now()->toDateString(),
            'descricao' => 'Diferença encontrada na contagem física',
            'documento_ref' => 'CONC-001',
        ])->assertCreated();

        $this->assertSame($financialRowsBefore, Financeiro::query()->count());
        $this->getJson('/api/v1/financeiro/contas')
            ->assertOk()
            ->assertJsonPath('data.resumo.total_em_contas', 2975.0);

        $this->postJson('/api/v1/financeiro/contas/'.$cash.'/ajustes', [
            'natureza' => 'saida',
            'valor' => 3000,
            'data_movimento' => now()->toDateString(),
            'descricao' => 'Ajuste maior que o saldo disponível',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'FINANCEIRO_AJUSTE_FAILED');
    }

    /** @param array<int, string> $defaults */
    private function createAccount(string $name, string $type, float $opening, array $defaults, bool $available = true): int
    {
        $response = $this->postJson('/api/v1/financeiro/contas', [
            'nome' => $name,
            'tipo' => $type,
            'data_inicio_controle' => now()->toDateString(),
            'saldo_inicial' => $opening,
            'considera_disponivel' => $available,
            'ativo' => true,
            'formas_padrao' => $defaults,
        ])->assertCreated();

        return (int) $response->json('data.conta.id');
    }

    private function createReceivable(int $clientId, float $value): int
    {
        $response = $this->postJson('/api/v1/financeiro', [
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Serviço',
            'descricao' => 'Recebimento para teste de conta',
            'cliente_id' => $clientId,
            'avulso' => true,
            'valor' => $value,
            'data_vencimento' => now()->toDateString(),
        ])->assertCreated();

        return (int) $response->json('data.lancamento.id');
    }

    /** @return array{operadora_id: int, bandeira_id: int} */
    private function seedCardCatalog(): array
    {
        $operatorId = (int) DB::table('financeiro_cartao_operadoras')->insertGetId([
            'nome' => 'TOM',
            'descricao' => 'Maquininha principal',
            'ordem_exibicao' => 1,
            'prazo_padrao_dias' => 30,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $brandId = (int) DB::table('financeiro_cartao_bandeiras')->insertGetId([
            'nome' => 'Visa',
            'ordem_exibicao' => 1,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('financeiro_cartao_taxas')->insert([
            'operadora_id' => $operatorId,
            'bandeira_id' => $brandId,
            'modalidade' => 'credito',
            'parcelas_inicial' => 1,
            'parcelas_final' => 1,
            'taxa_percentual' => 3.19,
            'taxa_fixa' => 0,
            'prazo_recebimento_dias' => 30,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['operadora_id' => $operatorId, 'bandeira_id' => $brandId];
    }
}
