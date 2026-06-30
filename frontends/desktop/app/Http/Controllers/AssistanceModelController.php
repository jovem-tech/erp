<?php

namespace App\Http\Controllers;

use App\Services\DesktopOrderStatusFlowService;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class AssistanceModelController extends DesktopController
{
    public function __construct(
        private readonly DesktopOrderStatusFlowService $orderStatusFlowService
    ) {
    }

    public function index(): View
    {
        return view('knowledge.assistance-model.index', array_merge(
            $this->buildViewData(),
            $this->buildNaturalJourneyViewData()
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(): array
    {
        return [
            'pageTitle' => 'Modelo Ideal da Assistência Técnica',
            'visualSummary' => [
                [
                    'label' => 'Fila única',
                    'value' => '1',
                    'detail' => 'Uma única fila por prioridade, sem filas paralelas invisíveis.',
                ],
                [
                    'label' => 'Triagem inicial',
                    'value' => '15 min',
                    'detail' => 'Tempo máximo para classificar, registrar e encaminhar a OS.',
                ],
                [
                    'label' => 'Orçamento',
                    'value' => '24 h',
                    'detail' => 'Janela curta para resposta e escalonamento automático.',
                ],
                [
                    'label' => 'WIP técnico',
                    'value' => '3',
                    'detail' => 'Limite de OS simultâneas por técnico para reduzir multitarefa.',
                ],
            ],
            'workflowTrail' => [
                'Recepção',
                'Triagem',
                'Aguardando Avaliação',
                'Diagnóstico',
                'Orçamento',
                'Autorização',
                'Reparo',
                'Execução',
                'Qualidade',
                'Entrega',
                'Pós-venda',
            ],
            'modelPillars' => [
                [
                    'icon' => 'bi-person-check-fill',
                    'title' => 'Uma OS, um dono',
                    'description' => 'Cada ordem precisa de responsável, próximo passo e prazo. Sem dono, a fila quebra.',
                ],
                [
                    'icon' => 'bi-stopwatch-fill',
                    'title' => 'Tempo timebox',
                    'description' => 'Triagem, orçamento e retorno precisam de SLA visível para evitar espera silenciosa.',
                ],
                [
                    'icon' => 'bi-funnel-fill',
                    'title' => 'Prioridade por aging',
                    'description' => 'OS antigas sobem de prioridade mesmo quando entraram depois, evitando FIFO cego.',
                ],
                [
                    'icon' => 'bi-shield-check',
                    'title' => 'Garantia como via rápida',
                    'description' => 'Casos cobertos seguem fora da fila comercial comum, por verificação e cumprimento de garantia.',
                ],
                [
                    'icon' => 'bi-box-seam',
                    'title' => 'Peça bloqueia visível',
                    'description' => 'Aguardando peça vira estado explícito com revisão agendada, não um “fica para depois”.',
                ],
                [
                    'icon' => 'bi-graph-up-arrow',
                    'title' => 'Escalonamento automático',
                    'description' => 'Se a resposta ou a ação não acontece, o caso sobe de nível antes de virar gargalo.',
                ],
            ],
            'workflowLanes' => [
                [
                    'key' => 'recepcao',
                    'label' => 'Recepção e Triagem',
                    'description' => 'A OS entra, ganha prioridade e sai com um dono definido. Se não houver triagem, a fila começa errada.',
                    'accent' => '#0ea5e9',
                    'soft_accent' => 'rgba(14, 165, 233, 0.12)',
                    'chips' => ['SLA 15 min', 'Fila única', 'Prioridade por aging'],
                    'steps' => [
                        [
                            'order' => '01',
                            'title' => 'Recepção',
                            'code' => 'Entrada controlada',
                            'owner' => 'Atendimento',
                            'timebox' => 'imediato',
                            'entry' => 'OS criada ou recebida',
                            'exit' => 'Cadastro mínimo validado',
                            'risk' => 'Pedido sem dados vira retrabalho',
                        ],
                        [
                            'order' => '02',
                            'title' => 'Triagem',
                            'code' => 'Classificação + prioridade',
                            'owner' => 'Atendimento / líder',
                            'timebox' => '15 min',
                            'entry' => 'OS liberada para fila',
                            'exit' => 'Encaminhada ao caminho correto',
                            'risk' => 'Fila quebra quando todo caso “é urgente”',
                        ],
                        [
                            'order' => '03',
                            'title' => 'Verificação de Garantia',
                            'code' => 'Cobertura ativa',
                            'owner' => 'Supervisor',
                            'timebox' => 'prioritária',
                            'entry' => 'Cobertura confirmada',
                            'exit' => 'Cumprimento de garantia ou solução imediata',
                            'risk' => 'Garantia misturada ao fluxo comercial',
                        ],
                    ],
                ],
                [
                    'key' => 'diagnostico',
                    'label' => 'Diagnóstico e Orçamento',
                    'description' => 'O técnico valida a causa, decide se há reparo e só então abre orçamento. A aprovação precisa de prazo curto e cobrança ativa.',
                    'accent' => '#6f5afc',
                    'soft_accent' => 'rgba(111, 90, 252, 0.12)',
                    'chips' => ['Dono técnico', 'Orçamento 24 h', 'Retorno ativo'],
                    'steps' => [
                        [
                            'order' => '04',
                            'title' => 'Diagnóstico Técnico',
                            'code' => 'Causa raiz',
                            'owner' => 'Técnico',
                            'timebox' => '30 min',
                            'entry' => 'Avaliação concluída',
                            'exit' => 'Tem reparo? decidido',
                            'risk' => 'OS parada sem ação concreta',
                        ],
                        [
                            'order' => '05',
                            'title' => 'Orçamento',
                            'code' => 'Aprovação do cliente',
                            'owner' => 'Atendimento / comercial',
                            'timebox' => '24 h',
                            'entry' => 'Reparo com custo adicional',
                            'exit' => 'Aprovado, recusado ou cancelado',
                            'risk' => 'Espera infinita sem escalonamento',
                        ],
                        [
                            'order' => '06',
                            'title' => 'Aguardando Peça',
                            'code' => 'Peça pendente',
                            'owner' => 'Almoxarifado / compras',
                            'timebox' => 'bloqueado',
                            'entry' => 'Orçamento aprovado',
                            'exit' => 'Material liberado para execução',
                            'risk' => 'Reparo travado sem visibilidade da peça',
                        ],
                    ],
                ],
                [
                    'key' => 'execucao',
                    'label' => 'Execução e Qualidade',
                    'description' => 'A produção precisa de limite de WIP, execução focada e teste obrigatório antes da saída. Sem isso, a fila vira multitarefa permanente.',
                    'accent' => '#16a34a',
                    'soft_accent' => 'rgba(22, 163, 74, 0.12)',
                    'chips' => ['WIP 3', 'Teste obrigatório', 'Retrabalho controlado'],
                    'steps' => [
                        [
                            'order' => '07',
                            'title' => 'Execução',
                            'code' => 'Reparo em andamento',
                            'owner' => 'Técnico',
                            'timebox' => 'com limite',
                            'entry' => 'Peça e aprovação liberadas',
                            'exit' => 'Serviço concluído',
                            'risk' => 'Multitarefa e procrastinação de bancada',
                        ],
                        [
                            'order' => '08',
                            'title' => 'Qualidade',
                            'code' => 'Teste final',
                            'owner' => 'QC / líder',
                            'timebox' => 'imediato',
                            'entry' => 'Reparo concluído',
                            'exit' => 'Entrega autorizada',
                            'risk' => 'Entrega sem validação final',
                        ],
                    ],
                ],
                [
                    'key' => 'encerramento',
                    'label' => 'Entrega e Pós-venda',
                    'description' => 'A saída é controlada: entrega, pagamento pendente, devolução sem reparo, cancelamento ou garantia retornam como terminal claro.',
                    'accent' => '#f59e0b',
                    'soft_accent' => 'rgba(245, 158, 11, 0.14)',
                    'chips' => ['Saída controlada', 'Cobrança separada', 'Garantia ativa'],
                    'steps' => [
                        [
                            'order' => '09',
                            'title' => 'Entrega',
                            'code' => 'Encerramento operacional',
                            'owner' => 'Atendimento',
                            'timebox' => 'imediato',
                            'entry' => 'Teste aprovado',
                            'exit' => 'OS entregue ao cliente',
                            'risk' => 'Retirada sem orientação final',
                        ],
                        [
                            'order' => '10',
                            'title' => 'Pós-venda',
                            'code' => 'Garantia e retorno',
                            'owner' => 'CRM / atendimento',
                            'timebox' => 'follow-up',
                            'entry' => 'OS entregue',
                            'exit' => 'Cobrança, garantia ou reabertura controlada',
                            'risk' => 'Perda de feedback e recompra',
                        ],
                    ],
                ],
            ],
            'queueRules' => [
                [
                    'title' => 'Fila única',
                    'icon' => 'bi-inboxes-fill',
                    'rule' => 'não criar filas paralelas por técnico, pessoa ou telefone',
                    'impact' => 'reduz favorecimento informal e facilita priorização real',
                ],
                [
                    'title' => 'Próxima ação obrigatória',
                    'icon' => 'bi-arrow-right-circle-fill',
                    'rule' => 'cada OS precisa sair de uma etapa com a próxima ação definida',
                    'impact' => 'evita casos sem dono e sem direção',
                ],
                [
                    'title' => 'SLA timebox',
                    'icon' => 'bi-alarm-fill',
                    'rule' => 'triagem, orçamento e revisão precisam de prazo curto de retorno',
                    'impact' => 'impede espera silenciosa e procrastinação acumulada',
                ],
                [
                    'title' => 'Aging primeiro',
                    'icon' => 'bi-sort-down',
                    'rule' => 'casos mais antigos sobem de prioridade quando empatam em urgência',
                    'impact' => 'evita FIFO cego e melhora justiça operacional',
                ],
                [
                    'title' => 'WIP limitado',
                    'icon' => 'bi-layers-fill',
                    'rule' => 'cada técnico trabalha poucas OS ao mesmo tempo para reduzir troca de contexto',
                    'impact' => 'melhora fluxo de conclusão e diminui retrabalho',
                ],
                [
                    'title' => 'Escalonamento',
                    'icon' => 'bi-exclamation-triangle-fill',
                    'rule' => 'se o prazo expira, o caso sobe para liderança ou atendimento ativo',
                    'impact' => 'impede que um caso travado consuma a fila inteira',
                ],
            ],
            'specialCases' => [
                [
                    'title' => 'Garantia',
                    'icon' => 'bi-shield-check',
                    'color' => '#0ea5e9',
                    'entry' => 'cobertura confirmada',
                    'rule' => 'segue via de garantia, fora da fila comercial comum, até o cumprimento ou a conclusão da garantia',
                    'exit' => 'execução ou encerramento sem cobrança comum',
                ],
                [
                    'title' => 'Aguardando peça',
                    'icon' => 'bi-box-seam',
                    'color' => '#8b5cf6',
                    'entry' => 'reparo depende de componente',
                    'rule' => 'fica marcado como bloqueio visível com revisão agendada',
                    'exit' => 'reparo libera quando a peça entra',
                ],
                [
                    'title' => 'Pagamento pendente',
                    'icon' => 'bi-credit-card-2-front',
                    'color' => '#f59e0b',
                    'entry' => 'cobrança ou saldo em aberto',
                    'rule' => 'não trava a produção concluída, mas mantém follow-up ativo e saldo visível',
                    'exit' => 'quitação, acordo financeiro ou retorno controlado para a fila',
                ],
                [
                    'title' => 'Cancelada / sem reparo',
                    'icon' => 'bi-x-circle-fill',
                    'color' => '#64748b',
                    'entry' => 'cliente recusa, sem viabilidade ou sem solução',
                    'rule' => 'encerra com motivo classificado para análise de perda',
                    'exit' => 'baixa operacional sem falsa permanência na fila',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildNaturalJourneyViewData(): array
    {
        $statuses = [];

        try {
            $result = $this->orderStatusFlowService->index();
            $statuses = is_array($result['statuses'] ?? null) ? $result['statuses'] : [];
        } catch (Throwable $exception) {
            report($exception);
        }

        $journeySteps = $this->buildNaturalJourneySteps($statuses);

        return [
            'naturalJourney' => [
                'title' => 'Fluxo natural de uma OS reparada e entregue',
                'subtitle' => 'Simulação do caminho feliz com base nos status atuais do catálogo da assistência, incluindo avaliação, orçamento, autorização, execução, testes, entrega e ramos de garantia, peça e pendência financeira.',
                'trail' => collect($journeySteps)
                    ->pluck('resolved_label')
                    ->filter(static fn (mixed $label): bool => is_string($label) && trim($label) !== '')
                    ->values()
                    ->all(),
                'steps' => $journeySteps,
                'branch' => $this->buildFinancialBranchStep($statuses),
                'status_count' => count($statuses),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $statuses
     * @return array<int, array<string, mixed>>
     */
    private function buildNaturalJourneySteps(array $statuses): array
    {
        $definitions = [
            [
                'key' => 'entrada',
                'label' => 'Recepção',
                'resolved_label' => 'Recepção',
                'code' => '',
                'kind' => 'context',
                'accent' => '#0ea5e9',
                'timebox' => 'imediato',
                'owner' => 'Atendimento',
                'description' => 'Cliente entrega o equipamento e a OS nasce com cadastro mínimo.',
                'entry' => 'Chegada física do equipamento',
                'exit' => 'OS pronta para a fila de triagem',
                'risk' => 'Sem cadastro a OS já começa quebrada',
            ],
            [
                'key' => 'triagem',
                'label' => 'Triagem',
                'resolved_label' => 'Triagem',
                'code' => 'triagem',
                'kind' => 'status',
                'accent' => '#38bdf8',
                'timebox' => '15 min',
                'owner' => 'Atendimento / líder',
                'description' => 'Classifica prioridade, registra contexto e define o próximo passo.',
                'entry' => 'OS recebida na assistência',
                'exit' => 'Caminho correto definido',
                'risk' => 'Fila sem classificação vira procrastinação',
            ],
            [
                'key' => 'avaliacao',
                'label' => 'Aguardando Avaliação',
                'resolved_label' => 'Aguardando Avaliação',
                'code' => 'aguardando_avaliacao',
                'kind' => 'status',
                'accent' => '#f59e0b',
                'timebox' => '24 h',
                'owner' => 'Atendimento / comercial',
                'description' => 'A OS aguarda avaliação inicial antes de entrar no diagnóstico técnico.',
                'entry' => 'OS recebida na assistência',
                'exit' => 'Caminho para diagnóstico liberado',
                'risk' => 'Fila sem avaliação vira espera invisível',
            ],
            [
                'key' => 'diagnostico',
                'label' => 'Diagnóstico Técnico',
                'resolved_label' => 'Diagnóstico Técnico',
                'code' => 'diagnostico',
                'kind' => 'status',
                'accent' => '#6f5afc',
                'timebox' => '30 min',
                'owner' => 'Técnico',
                'description' => 'O técnico aprofunda a causa, confirma viabilidade e prepara o orçamento.',
                'entry' => 'Avaliação concluída',
                'exit' => 'Diagnóstico e encaminhamento definidos',
                'risk' => 'OS parada sem ação concreta',
            ],
            [
                'key' => 'orcamento',
                'label' => 'Aguardando Orçamento',
                'resolved_label' => 'Aguardando Orçamento',
                'code' => 'aguardando_orcamento',
                'kind' => 'status',
                'accent' => '#4f46e5',
                'timebox' => '24 h',
                'owner' => 'Atendimento / comercial',
                'description' => 'O diagnóstico vira preço, prazo e escopo antes de qualquer reparo.',
                'entry' => 'Diagnóstico concluído',
                'exit' => 'Orçamento emitido',
                'risk' => 'Sem orçamento a OS perde tração',
            ],
            [
                'key' => 'autorizacao',
                'label' => 'Aguardando Autorização',
                'resolved_label' => 'Aguardando Autorização',
                'code' => 'aguardando_autorizacao',
                'kind' => 'status',
                'accent' => '#8b5cf6',
                'timebox' => '24 h',
                'owner' => 'Atendimento / comercial',
                'description' => 'O cliente aprova, recusa ou pede ajuste no orçamento enviado.',
                'entry' => 'Orçamento emitido',
                'exit' => 'Autorização registrada',
                'risk' => 'Aprovação sem SLA vira fila parada',
            ],
            [
                'key' => 'reparo',
                'label' => 'Aguardando Reparo',
                'resolved_label' => 'Aguardando Reparo',
                'code' => 'aguardando_reparo',
                'kind' => 'status',
                'accent' => '#16a34a',
                'timebox' => 'conforme peça',
                'owner' => 'Técnico / estoque',
                'description' => 'Autorizado, o item entra na bancada ou aguarda material.',
                'entry' => 'Autorização confirmada',
                'exit' => 'Bancada liberada',
                'risk' => 'Reparo travado sem visibilidade da peça',
            ],
            [
                'key' => 'execucao',
                'label' => 'Em Execução do Serviço',
                'resolved_label' => 'Em Execução do Serviço',
                'code' => 'reparo_execucao',
                'kind' => 'status',
                'accent' => '#f59e0b',
                'timebox' => 'com limite',
                'owner' => 'Técnico',
                'description' => 'Reparo em andamento com WIP limitado por técnico.',
                'entry' => 'Bancada liberada',
                'exit' => 'Serviço concluído',
                'risk' => 'Multitarefa e procrastinação de bancada',
            ],
            [
                'key' => 'qualidade_operacional',
                'label' => 'Testes Operacionais',
                'resolved_label' => 'Testes Operacionais',
                'code' => 'testes_operacionais',
                'kind' => 'status',
                'accent' => '#0ea5e9',
                'timebox' => 'rápido',
                'owner' => 'Técnico / QC',
                'description' => 'Funcionamento básico validado antes da conferência final.',
                'entry' => 'Reparo executado',
                'exit' => 'Funcionamento básico validado',
                'risk' => 'Falha simples virar retorno',
            ],
            [
                'key' => 'qualidade_final',
                'label' => 'Testes Finais',
                'resolved_label' => 'Testes Finais',
                'code' => 'testes_finais',
                'kind' => 'status',
                'accent' => '#38bdf8',
                'timebox' => 'imediato',
                'owner' => 'QC / líder',
                'description' => 'Checagem final antes do encerramento ou da entrega.',
                'entry' => 'Funcionamento básico validado',
                'exit' => 'Pronto para conclusão',
                'risk' => 'Entrega sem validação final',
            ],
            [
                'key' => 'conclusao',
                'label' => 'Reparo Concluído',
                'resolved_label' => 'Reparo Concluído',
                'code' => 'reparo_concluido',
                'kind' => 'status',
                'accent' => '#16a34a',
                'timebox' => 'imediato',
                'owner' => 'Atendimento',
                'description' => 'OS encerrada tecnicamente e pronta para saída.',
                'entry' => 'Testes finais aprovados',
                'exit' => 'Entrega liberada',
                'risk' => 'Esquecer a baixa operacional',
            ],
            [
                'key' => 'entrega',
                'label' => 'Equipamento Entregue',
                'resolved_label' => 'Equipamento Entregue',
                'code' => 'entregue_reparado',
                'kind' => 'status',
                'accent' => '#0f766e',
                'timebox' => 'imediato',
                'owner' => 'Atendimento',
                'description' => 'Cliente retira o equipamento e o ciclo fica encerrado.',
                'entry' => 'OS liberada para saída',
                'exit' => 'Cliente recebe o equipamento',
                'risk' => 'Cobrança misturada com fila produtiva',
            ],
        ];

        return array_map(
            function (array $definition) use ($statuses): array {
                $resolvedStatus = $definition['code'] !== ''
                    ? $this->findCurrentStatus($statuses, (string) $definition['code'], (string) $definition['label'])
                    : null;

                $resolvedLabel = is_array($resolvedStatus)
                    ? (string) ($resolvedStatus['nome'] ?? $definition['resolved_label'])
                    : (string) $definition['resolved_label'];

                $resolvedCode = is_array($resolvedStatus)
                    ? (string) ($resolvedStatus['codigo'] ?? $definition['code'])
                    : (string) $definition['code'];

                $resolvedColor = is_array($resolvedStatus)
                    ? $this->normalizeWorkflowColor((string) ($resolvedStatus['cor'] ?? $definition['accent']))
                    : (string) $definition['accent'];

                return [
                    'key' => $definition['key'],
                    'kind' => $definition['kind'],
                    'label' => $definition['label'],
                    'resolved_label' => $resolvedLabel,
                    'code' => $resolvedCode,
                    'accent' => $resolvedColor,
                    'timebox' => $definition['timebox'],
                    'owner' => $definition['owner'],
                    'description' => $definition['description'],
                    'entry' => $definition['entry'],
                    'exit' => $definition['exit'],
                    'risk' => $definition['risk'],
                    'resolved' => is_array($resolvedStatus),
                    'status_final' => is_array($resolvedStatus) ? (bool) ($resolvedStatus['status_final'] ?? false) : false,
                    'status_pausa' => is_array($resolvedStatus) ? (bool) ($resolvedStatus['status_pausa'] ?? false) : false,
                    'status_ativo' => is_array($resolvedStatus) ? (bool) ($resolvedStatus['ativo'] ?? false) : false,
                ];
            },
            $definitions
        );
    }

    /**
     * @param array<int, array<string, mixed>> $statuses
     * @return array<string, mixed>|null
     */
    private function buildFinancialBranchStep(array $statuses): ?array
    {
        $branchStatus = $this->findCurrentStatus($statuses, 'entregue_pagamento_pendente', 'Entregue Pagamento Pendente');

        if (! is_array($branchStatus)) {
            return null;
        }

        return [
            'key' => 'pagamento_pendente',
            'label' => 'Entregue - Pendência Financeira',
            'resolved_label' => (string) ($branchStatus['nome'] ?? 'Entregue - Pendência Financeira'),
            'code' => (string) ($branchStatus['codigo'] ?? 'entregue_pagamento_pendente'),
            'accent' => $this->normalizeWorkflowColor((string) ($branchStatus['cor'] ?? '#f59e0b')),
            'description' => 'Ramo financeiro terminal para entrega concluída com saldo em aberto.',
            'entry' => 'OS já entregue ao cliente',
            'exit' => 'Cobrança e follow-up financeiro continuam fora da bancada',
            'risk' => 'Misturar cobrança com fila produtiva',
            'resolved' => true,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $statuses
     * @return array<string, mixed>|null
     */
    private function findCurrentStatus(array $statuses, string $code, string $label): ?array
    {
        $codeToken = $this->normalizeToken($code);
        $labelToken = $this->normalizeToken($label);

        foreach ($statuses as $status) {
            if (! is_array($status)) {
                continue;
            }

            $statusCode = $this->normalizeToken((string) ($status['codigo'] ?? ''));
            $statusLabel = $this->normalizeToken((string) ($status['nome'] ?? ''));

            if ($codeToken !== '' && $statusCode === $codeToken) {
                return $status;
            }

            if ($labelToken !== '' && $statusLabel === $labelToken) {
                return $status;
            }
        }

        return null;
    }

    private function normalizeToken(string $value): string
    {
        return Str::slug($value, '_');
    }

    private function normalizeWorkflowColor(string $color): string
    {
        $color = trim($color);

        if ($color === '') {
            return '#6f5afc';
        }

        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color) === 1) {
            return $color;
        }

        if (preg_match('/^rgba?\(\s*(?:\d{1,3}\s*,\s*){2}\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/i', $color) === 1) {
            return $color;
        }

        if (preg_match('/^hsla?\(\s*(?:\d{1,3}(?:\.\d+)?)\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(?:,\s*(?:0|1|0?\.\d+))?\s*\)$/i', $color) === 1) {
            return $color;
        }

        if (preg_match('/^var\(--[A-Za-z0-9_-]+\)$/', $color) === 1) {
            return $color;
        }

        return match (mb_strtolower($color)) {
            'primary' => '#0d6efd',
            'secondary' => '#64748b',
            'success' => '#16a34a',
            'info' => '#0ea5e9',
            'warning' => '#f59e0b',
            'danger' => '#ef4444',
            'dark' => '#334155',
            'light' => '#cbd5e1',
            'indigo' => '#4f46e5',
            'purple' => '#8b5cf6',
            'orange' => '#f97316',
            default => '#6f5afc',
        };
    }
}
