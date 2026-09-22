<?php

namespace App\Services\Pdf\Contexts;

use App\Models\Budget;
use App\Models\BudgetApproval;
use App\Models\BudgetItem;
use App\Models\Order;
use App\Services\Budgets\BudgetCommercialTermsService;
use App\Services\Budgets\BudgetOfferedOptionsService;
use App\Support\BudgetTotals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Contexto do documento de orçamento: tudo do OrderPdfContextFactory
 * (quando o orçamento tem OS vinculada) + orcamento.* + itens do orçamento
 * (que substituem os itens da OS na coleção `itens`).
 */
class BudgetPdfContextFactory extends OrderPdfContextFactory
{
    public function build(array $subject, array $options = []): array
    {
        $budget = $this->resolveBudget($subject);
        if (! $budget instanceof Budget) {
            return [];
        }

        $budget->loadMissing([
            'client',
            'equipment',
            'equipment.type',
            'equipment.brand',
            'equipment.model',
            'order',
            'items',
            'paymentMethods',
        ]);

        $this->imageRefs = [];

        $context = [];
        if ($budget->order instanceof Order) {
            $context = parent::build(['order' => $budget->order], $options);
        }

        // Orçamento sem OS vinculada: monta cliente/equipamento direto do orçamento.
        if ($context === []) {
            $context = [
                'os' => [],
                'cliente' => [
                    'nome' => (string) ($budget->client?->nome_razao ?? ''),
                    'telefone' => (string) ($budget->client?->telefone1 ?? $budget->client?->telefone_contato ?? ''),
                    'email' => (string) ($budget->client?->email ?? ''),
                    'documento' => (string) ($budget->client?->cpf_cnpj ?? ''),
                    'endereco' => '',
                ],
                'equipamento' => [
                    'descricao' => (string) ($budget->equipment?->resumo_tecnico ?? ''),
                    'tipo' => (string) ($budget->equipment?->type?->nome ?? ''),
                    'marca' => (string) ($budget->equipment?->brand?->nome ?? ''),
                    'modelo' => (string) ($budget->equipment?->model?->nome ?? ''),
                    'serie' => (string) ($budget->equipment?->numero_serie ?? ''),
                    'foto_principal_base64' => $this->shouldIncludeEquipmentPhoto($options)
                        ? $this->equipmentPhotoBase64($budget->equipment, $this->photoProfile($options))
                        : '',
                ],
                'acessorios' => [],
                'estado_fisico' => [],
                '_refs' => ['imagens' => $this->imageRefs],
            ];
        }

        // Níveis de manutenção. Antes da decisão, `nivel` (vindo da página
        // pública: ?opcao=N) projeta itens e totais daquela opção sem gravar
        // nada; depois da aprovação a lista já é o escopo e `nivel_aprovado`
        // nomeia a opção. Orçamento comum: tudo vazio, PDF idêntico ao de hoje.
        $projectedLevel = null;
        if ($budget->hasTiers()) {
            $candidate = Budget::normalizeLevel($options['nivel'] ?? null);
            $projectedLevel = $candidate !== null && $candidate <= $budget->maxLevel() ? $candidate : null;
        }

        // Condições comerciais (formas aceitas, chave Pix, parcelamento,
        // garantia, entrega e diferenciais) vêm do mesmo serviço que alimenta
        // a tela e o link público, já resolvidas para a opção projetada (ou
        // para o nível aprovado): o cliente lê o mesmo texto nos três lugares.
        $terms = app(BudgetCommercialTermsService::class)->forBudget($budget, $projectedLevel);
        $projectedTotals = $projectedLevel !== null
            ? (BudgetTotals::perLevel($budget)[$projectedLevel - 1] ?? null)
            : null;
        $items = $projectedLevel !== null
            ? BudgetTotals::itemsForLevel($budget, $projectedLevel)
            : $budget->items;
        $approvedLevel = Budget::normalizeLevel($budget->nivel_aprovado);
        $opcaoNivel = $projectedLevel ?? $approvedLevel;
        $opcaoTexto = Budget::levelLabel($opcaoNivel);
        // Seção "Opção de manutenção / Opção aprovada" do modelo padrão: o
        // título diz em que pé a opção está (em análise ou já aprovada), e o
        // resto resume o que ela inclui. Tudo vazio em orçamento comum — os
        // blocos são condicionais e o PDF de sempre não muda.
        $opcaoTitulo = '';
        if ($projectedLevel !== null) {
            $opcaoTitulo = 'Opção de manutenção: '.$opcaoTexto;
        } elseif ($approvedLevel !== null) {
            $opcaoTitulo = 'Opção aprovada: '.$opcaoTexto;
        }
        $opcaoAprovacaoTexto = $projectedLevel === null && $approvedLevel !== null
            ? $this->approvalSentence($budget)
            : '';
        $approvalLink = trim((string) ($options['approval_link'] ?? ''));
        if ($approvalLink !== '' && $projectedLevel !== null) {
            // O botão do PDF da opção cai direto no passo 2 daquela opção.
            $approvalLink .= (str_contains($approvalLink, '?') ? '&' : '?').'opcao='.$projectedLevel;
        }

        $context['orcamento'] = [
            'numero' => trim((string) ($budget->numero ?? ('ORC-' . (int) $budget->id))),
            'titulo' => (string) ($budget->titulo ?? ''),
            'validade_dias' => (int) ($budget->validade_dias ?? 0),
            // Data limite para o cliente responder: vai na legenda do botao de
            // aprovacao, para ele saber ate quando o link vale.
            'validade_data' => $budget->validade_data,
            // Prazo que o backend realmente honra no link publico. Em geral
            // coincide com validade_data (o token expira no fim daquele dia),
            // mas quando o envio renovou o prazo é este que vale — a legenda do
            // botao nao pode prometer uma data que o 410 vai desmentir.
            'validade_link' => $budget->token_expira_em ?? $budget->validade_data,
            'prazo_execucao' => (string) ($budget->prazo_execucao ?? ''),
            'condicoes' => (string) ($budget->condicoes ?? ''),
            'observacoes' => (string) ($budget->observacoes ?? ''),
            'subtotal' => (float) ($projectedTotals['subtotal'] ?? $budget->subtotal ?? 0),
            'desconto' => (float) ($projectedTotals['desconto'] ?? $budget->desconto ?? 0),
            'total' => (float) ($projectedTotals['total'] ?? $budget->total ?? 0),
            'opcao_texto' => $opcaoTexto,
            'opcao_titulo' => $opcaoTitulo,
            'opcao_subtitulo' => $opcaoNivel !== null ? (string) (Budget::NIVEIS[$opcaoNivel]['subtitle'] ?? '') : '',
            'opcao_itens_texto' => $opcaoNivel !== null ? $this->itemsCountSentence($items) : '',
            'opcao_aprovacao_texto' => $opcaoAprovacaoTexto,
            // Orçamento vencido, ou já decidido (aprovado/pendente de OS): nos dois
            // casos não faz sentido convidar o cliente a "aprovar ou recusar" de
            // novo — no vencido porque o link já devolve 410, no já decidido
            // porque a decisão já foi tomada. O botão some do documento; o
            // condicional do modelo (`orcamento.link_aprovacao` preenchido) cuida
            // do resto — vale para qualquer modelo, não só o padrão.
            'link_aprovacao' => ($budget->publicLinkExpired() || in_array((string) $budget->status, Budget::approvedForOrderLinkStatuses(), true))
                ? ''
                : $approvalLink,
            'formas_pagamento' => (string) $terms['formas_pagamento_texto'],
            'chaves_pix' => (string) $terms['chaves_pix_texto'],
            'parcelamento' => (string) $terms['parcelamento_texto'],
            'garantia_dias' => $terms['garantia_dias'],
            'garantia_prazo' => (string) $terms['garantia_label'],
            'garantia_texto' => (string) $terms['garantia_texto'],
            'entrega_domicilio_texto' => (string) $terms['entrega_domicilio_texto'],
            'entrega_domicilio_label' => (string) $terms['entrega_domicilio_label'],
            'beneficios_texto' => (string) $terms['beneficios_texto'],
            'condicoes_comerciais' => (string) $terms['resumo'],
        ];

        $context['formas_pagamento'] = array_map(
            static fn (array $forma): array => ['nome' => $forma['nome']],
            $terms['formas_pagamento']
        );

        $context['beneficios'] = array_map(
            static fn (string $descricao): array => ['descricao' => $descricao],
            $terms['beneficios']
        );

        $context['chaves_pix'] = array_map(
            static fn (array $chave): array => [
                'tipo' => $chave['tipo_label'],
                'chave' => $chave['chave'],
                'titular' => $chave['titular'],
                'instituicao' => $chave['instituicao'],
            ],
            $terms['chaves_pix']
        );

        // A coleção `itens` do documento de orçamento são os itens comerciais
        // do orçamento, não os itens operacionais da OS.
        $context['itens'] = $items
            ->map(static fn (BudgetItem $item): array => [
                'tipo' => (string) ($item->tipo_item ?? ''),
                'descricao' => (string) ($item->descricao ?? ''),
                'nivel' => implode(', ', Budget::normalizeLevels($item->niveis)),
                // float, nao int: orcamento_itens.quantidade sempre foi decimal.
                // Com (int), um orcamento de 1,5 h de servico imprimia "1" no PDF
                // que o cliente assina — divergindo do valor total, que usava a
                // quantidade real.
                'quantidade' => (float) ($item->quantidade ?? 0),
                'valor_unitario' => (float) ($item->valor_unitario ?? 0),
                'desconto' => (float) ($item->desconto ?? 0),
                'acrescimo' => (float) ($item->acrescimo ?? 0),
                'valor_total' => (float) ($item->total ?? 0),
                'observacoes' => (string) ($item->observacoes ?? ''),
            ])
            ->values()
            ->all();

        return $context;
    }

    private function resolveBudget(array $subject): ?Budget
    {
        $budget = $subject['budget'] ?? null;
        if ($budget instanceof Budget) {
            return $budget;
        }

        $budgetId = (int) ($subject['budget_id'] ?? 0);

        return $budgetId > 0 ? Budget::query()->find($budgetId) : null;
    }

    /**
     * "3 itens (2 peças, 1 serviço)" — o tamanho da opção numa linha.
     *
     * @param  Collection<int, BudgetItem>  $items
     */
    private function itemsCountSentence(Collection $items): string
    {
        $total = $items->count();
        if ($total === 0) {
            return 'Nenhum item';
        }

        $pecas = $items->filter(static fn (BudgetItem $item): bool => (string) $item->tipo_item === 'peca')->count();
        $servicos = $items->filter(static fn (BudgetItem $item): bool => (string) $item->tipo_item === 'servico')->count();

        $partes = [];
        if ($pecas > 0) {
            $partes[] = $pecas.' '.($pecas === 1 ? 'peça' : 'peças');
        }
        if ($servicos > 0) {
            $partes[] = $servicos.' '.($servicos === 1 ? 'serviço' : 'serviços');
        }
        $outros = $total - $pecas - $servicos;
        if ($outros > 0) {
            $partes[] = $outros.' '.($outros === 1 ? 'outro' : 'outros');
        }

        return $total.' '.($total === 1 ? 'item' : 'itens').($partes !== [] ? ' ('.implode(', ', $partes).')' : '');
    }

    /**
     * "Aprovada pelo cliente em 22/09/2026 00:59 pelo link público." — quem,
     * quando e por onde a opção foi aprovada, a partir da última aprovação
     * registrada (cai na data do orçamento se não houver linha de auditoria).
     */
    private function approvalSentence(Budget $budget): string
    {
        $budget->loadMissing('approvals');
        $approval = $budget->approvals
            ->filter(static fn (BudgetApproval $approval): bool => (string) $approval->acao === 'aprovado')
            ->sortByDesc(static fn (BudgetApproval $approval): string => ($approval->created_at instanceof Carbon ? $approval->created_at->format('YmdHis') : '').'-'.(int) $approval->id)
            ->first();

        $quando = $approval?->created_at instanceof Carbon
            ? $approval->created_at
            : ($budget->aprovado_em instanceof Carbon ? $budget->aprovado_em : null);
        if ($quando === null) {
            return '';
        }

        $origem = (string) ($approval?->origem ?? '');
        $quem = trim((string) ($approval?->usuario_nome ?? ''));
        $sujeito = $origem === 'painel' && $quem !== ''
            ? 'Aprovada em nome do cliente por '.$quem
            : 'Aprovada pelo cliente';
        $porOnde = BudgetOfferedOptionsService::approvalOriginLabel($origem);

        return trim(sprintf('%s em %s%s.', $sujeito, $quando->format('d/m/Y H:i'), $porOnde !== '' ? ' '.$porOnde : ''));
    }
}
