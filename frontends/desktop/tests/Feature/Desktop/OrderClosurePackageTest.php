<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClienteHttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tela de baixa x pacote de manutenção contratado.
 *
 * O que estes testes protegem é a diferença entre as duas telas: com pacote,
 * a baixa mostra o que foi vendido e separa as formas de pagamento em "do
 * pacote"/"fora do pacote"; sem pacote (o caso majoritário), a tela precisa
 * continuar exatamente como sempre foi.
 */
class OrderClosurePackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_baixa_com_pacote_mostra_o_contratado_e_separa_as_formas(): void
    {
        $this->fakeApi([
            'orders/10' => ['order' => $this->ordemParaBaixa()],
            'orders/10/closure' => $this->metadadosDeBaixa(['pacote' => $this->pacoteContratado()]),
        ]);

        $resposta = $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/os/10/baixa')
            ->assertOk()
            ->assertSee('Pacote contratado')
            ->assertSee('Manutenção Avançada')
            ->assertSee('ORC-2609-000014')
            // O prazo prometido precisa estar visível na hora de escolher.
            ->assertSee('prometido no pacote');

        $html = $resposta->getContent();

        // Agrupa, nunca filtra: boleto continua selecionável, só sinalizado.
        $this->assertStringContainsString('Do pacote contratado', $html);
        $this->assertStringContainsString('Fora do pacote', $html);
        $this->assertStringContainsString('data-outside-package="1"', $html);
        $this->assertStringContainsString('>Boleto</option>', $html);

        // Contrato blade <-> orders-closure.js.
        $this->assertStringContainsString('data-closure-package-ratify', $html);
        $this->assertStringContainsString('name="fora_pacote"', $html);
        $this->assertStringContainsString('name="fora_pacote_motivo"', $html);
        $this->assertStringContainsString('name="entrega_domicilio_cumprida"', $html);
        $this->assertStringContainsString('data-parcelas-package-hint', $html);
    }

    public function test_baixa_sem_pacote_continua_identica(): void
    {
        $this->fakeApi([
            'orders/10' => ['order' => $this->ordemParaBaixa()],
            // Sem a chave `pacote` — é o shape que o backend antigo devolvia e
            // o que a maioria das OS produz hoje.
            'orders/10/closure' => $this->metadadosDeBaixa(),
        ]);

        $resposta = $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/os/10/baixa')
            ->assertOk()
            ->assertDontSee('Pacote contratado')
            ->assertDontSee('Fora do pacote');

        $html = $resposta->getContent();

        $this->assertStringNotContainsString('data-closure-package-ratify', $html);
        $this->assertStringNotContainsString('name="fora_pacote"', $html);
        // O catálogo de formas segue inteiro e sem agrupamento.
        $this->assertStringNotContainsString('<optgroup', $html);
        $this->assertStringContainsString('>Boleto</option>', $html);
    }

    public function test_motivo_do_fora_do_pacote_chega_ao_backend(): void
    {
        $this->fakeApi([
            'orders/10' => ['order' => $this->ordemParaBaixa()],
            'orders/10/closure' => $this->metadadosDeBaixa(['pacote' => $this->pacoteContratado()]),
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->post('/os/10/baixa', [
                'classificacao_baixa' => 'baixa',
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-20',
                'garantia_dias' => 90,
                'fora_pacote' => '1',
                'fora_pacote_motivo' => 'Cliente aceitou garantia menor por escrito.',
                'entrega_domicilio_cumprida' => '0',
                'recebimentos' => [
                    ['valor' => '150.00', 'forma_pagamento' => 'boleto'],
                ],
            ])->assertSessionHasNoErrors();

        Http::assertSent(function (ClienteHttpRequest $request): bool {
            if (! str_contains($request->url(), 'orders/10/closure') || $request->method() !== 'POST') {
                return false;
            }

            return ($request['fora_pacote'] ?? null) === true
                && str_contains((string) ($request['fora_pacote_motivo'] ?? ''), 'garantia menor')
                && ($request['entrega_domicilio_cumprida'] ?? null) === false;
        });
    }

    public function test_sem_garantia_escolhido_chega_ao_backend_como_chave_vazia(): void
    {
        $this->fakeApi([
            'orders/10' => ['order' => $this->ordemParaBaixa()],
            'orders/10/closure' => $this->metadadosDeBaixa(['pacote' => $this->pacoteContratado()]),
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->post('/os/10/baixa', [
                'classificacao_baixa' => 'baixa',
                'encerrar_como' => 'entregue_reparado_pago',
                'data_entrega' => '2026-09-20',
                // "Sem garantia": o array_filter do controller descartava isto
                // e o backend nunca sabia que o operador escolheu nenhuma.
                'garantia_dias' => '',
                'fora_pacote' => '1',
                'fora_pacote_motivo' => 'Equipamento fora de linha.',
                'recebimentos' => [
                    ['valor' => '150.00', 'forma_pagamento' => 'pix'],
                ],
            ]);

        Http::assertSent(function (ClienteHttpRequest $request): bool {
            if (! str_contains($request->url(), 'orders/10/closure') || $request->method() !== 'POST') {
                return false;
            }

            return array_key_exists('garantia_dias', $request->data())
                && $request['garantia_dias'] === null;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function pacoteContratado(): array
    {
        return [
            'tem_pacote' => true,
            'orcamento_id' => 71,
            'orcamento_numero' => 'ORC-2609-000014',
            'nivel' => 2,
            'nivel_label' => 'Manutenção Avançada',
            'garantia_dias' => 180,
            'garantia_label' => '180 dias',
            'formas_pagamento' => [
                ['codigo' => 'dinheiro', 'nome' => 'Dinheiro', 'is_cartao' => false],
                ['codigo' => 'pix', 'nome' => 'Pix', 'is_cartao' => false],
                ['codigo' => 'cartao_credito', 'nome' => 'Cartão de crédito', 'is_cartao' => true],
            ],
            'formas_pagamento_codigos' => ['dinheiro', 'pix', 'cartao_credito'],
            'formas_pagamento_texto' => 'Dinheiro, Pix, Cartão de crédito',
            'parcelas_sem_juros' => 3,
            'parcelamento_texto' => 'Cartão de crédito em até 3x sem juros.',
            'entrega_domicilio' => true,
            'entrega_domicilio_label' => 'Entrega no seu endereço',
            'resumo' => 'Condições da Manutenção Avançada.',
        ];
    }

    /**
     * @param  array<string, mixed>  $sobrescreve
     * @return array<string, mixed>
     */
    private function ordemParaBaixa(array $sobrescreve = []): array
    {
        return array_replace([
            'id' => 10,
            'numero_os' => 'OS2609001',
            'status' => 'aguardando_reparo',
            'status_nome' => 'Aguardando Reparo',
            'estado_fluxo' => 'em_atendimento',
            'valor_final' => 420.0,
            'cliente_id' => 77,
            'cliente_nome' => 'Otavio Rosa',
            'equipamento_id' => 3,
        ], $sobrescreve);
    }

    /**
     * @param  array<string, mixed>  $sobrescreve
     * @return array<string, mixed>
     */
    private function metadadosDeBaixa(array $sobrescreve = []): array
    {
        return array_replace([
            'cliente_telefone' => '',
            'opcoes_encerramento' => [
                ['codigo' => 'entregue_reparado_pago', 'nome' => 'Entregue - Reparado e Pago'],
                ['codigo' => 'devolvido_sem_reparo', 'nome' => 'Devolvido sem reparo'],
            ],
            'financeiro' => [
                'valor_titulo' => 420.0,
                'valor_movimentado' => 0,
                'valor_aberto' => 420.0,
                'total_movimentos' => 0,
                'status_resolvido' => null,
                'percentual_quitado' => 0,
            ],
            'custo_summary' => ['pecas' => 0, 'servicos' => 0, 'total' => 0],
            'retorno_padrao' => now()->addDays(180)->toDateString(),
            'cartao' => ['operadoras' => [], 'bandeiras' => [], 'taxas' => []],
            'contas_financeiras' => ['contas' => [], 'contas_padrao' => []],
            'formas_pagamento' => [
                ['codigo' => 'dinheiro', 'nome' => 'Dinheiro', 'is_cartao' => false],
                ['codigo' => 'pix', 'nome' => 'Pix', 'is_cartao' => false],
                ['codigo' => 'cartao_credito', 'nome' => 'Cartão de crédito', 'is_cartao' => true],
                ['codigo' => 'boleto', 'nome' => 'Boleto', 'is_cartao' => false],
            ],
            'garantia' => [
                'opcoes' => [
                    ['value' => 90, 'label' => '90 dias'],
                    ['value' => 180, 'label' => '180 dias'],
                    ['value' => 365, 'label' => '1 ano'],
                    ['value' => 730, 'label' => '2 anos'],
                ],
                'dias_sugerido' => 180,
                'dias_prometido' => 180,
                'label_prometido' => '180 dias',
                'status_com_garantia' => ['entregue_reparado_pago', 'entregue_reparado_sem_custo', 'entregue_reparado_garantia'],
            ],
            'status_pagamento_pendente' => [
                'codigo' => 'entregue_pagamento_pendente',
                'nome' => 'Entregue - Pendência Financeira',
            ],
            'status_sem_reparo' => ['devolvido_sem_reparo', 'descartado'],
            'status_entregue' => 'entregue_reparado_pago',
        ], $sobrescreve);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rotas
     */
    private function fakeApi(array $rotas): void
    {
        $fakes = [
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [], 'unread_count' => 0],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 6, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0]],
            ], 200),
        ];

        foreach ($rotas as $rota => $dados) {
            $fakes['http://127.0.0.1:8000/api/v1/'.$rota] = Http::response([
                'status' => 'success',
                'data' => $dados,
                'error' => null,
                'meta' => [],
            ], 200);
        }

        $fakes['*'] = Http::response(['status' => 'success', 'data' => [], 'error' => null, 'meta' => []], 200);

        Http::fake($fakes);
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
