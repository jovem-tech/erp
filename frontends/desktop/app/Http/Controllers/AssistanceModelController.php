<?php

namespace App\Http\Controllers;

use App\Services\DesktopOrderStatusFlowService;
use App\Support\OrderStatusMacroGroups;
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
        // O catalogo e' lido uma vez e compartilhado entre os dois blocos da
        // pagina: antes cada um fazia a propria chamada a API central.
        $statuses = $this->fetchStatuses();

        return view('knowledge.assistance-model.index', array_merge(
            $this->buildViewData($statuses),
            $this->buildNaturalJourneyViewData($statuses)
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchStatuses(): array
    {
        try {
            $result = $this->orderStatusFlowService->index();

            return is_array($result['statuses'] ?? null) ? $result['statuses'] : [];
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * Conteudo do modelo operacional.
     *
     * Reescrito em 23/08/2026: a versao anterior era texto fixo que afirmava
     * coisas que o sistema nunca fez — SLA de 15 min na triagem, 30 min no
     * diagnostico, 24 h no orcamento, limite de WIP de 3 OS por tecnico,
     * prioridade por aging e escalonamento automatico. Nada disso existe:
     * `os_status` nao tem coluna de prazo e a string "WIP" so aparecia neste
     * arquivo. A trilha tambem citava etapas inexistentes ("Qualidade" como
     * status, "Pos-venda") e apelidava as reais ("Diagnostico" no lugar de
     * "Diagnostico Tecnico").
     *
     * Agora tudo que e numero ou nome de etapa sai do catalogo vivo, e o texto
     * fixo se limita a comportamento que existe no codigo.
     *
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<string, mixed>
     */
    private function buildViewData(array $statuses): array
    {
        $active = collect($statuses)
            ->filter(static fn (array $status): bool => (bool) ($status['ativo'] ?? false))
            ->sortBy(static fn (array $status): string => sprintf(
                '%010d-%s',
                (int) ($status['ordem_fluxo'] ?? 0),
                mb_strtolower(trim((string) ($status['nome'] ?? '')))
            ))
            ->values();

        $groups = $active
            ->groupBy(static fn (array $status): string => trim((string) ($status['grupo_macro'] ?? '')))
            ->sortBy(static fn ($groupStatuses): int => (int) $groupStatuses->min('ordem_fluxo'));

        $pauseCount = $active->filter(static fn (array $s): bool => (bool) ($s['status_pausa'] ?? false))->count();
        $finalCount = $active->filter(static fn (array $s): bool => (bool) ($s['status_final'] ?? false))->count();

        return [
            'pageTitle' => 'Modelo da Assistência Técnica',
            'visualSummary' => [
                [
                    'label' => 'Status ativos',
                    'value' => (string) $active->count(),
                    'detail' => 'Situações que uma OS pode assumir no catálogo atual.',
                ],
                [
                    'label' => 'Macrofases',
                    'value' => (string) $groups->count(),
                    'detail' => 'Agrupamentos usados para organizar o catálogo de status.',
                ],
                [
                    'label' => 'Status de pausa',
                    'value' => (string) $pauseCount,
                    'detail' => 'Marcam a OS como parada, com o motivo declarado.',
                ],
                [
                    'label' => 'Saídas finais',
                    'value' => (string) $finalCount,
                    'detail' => 'Status terminais: encerram a OS em vez de deixá-la na fila.',
                ],
            ],
            'workflowTrail' => $groups
                ->keys()
                ->map(static fn (string $grupoMacro): string => OrderStatusMacroGroups::label($grupoMacro))
                ->all(),
            'modelPillars' => $this->buildModelPillars($pauseCount, $finalCount),
            'workflowLanes' => $this->buildWorkflowLanes($groups),
            'queueRules' => $this->buildQueueRules(),
            'specialCases' => $this->buildSpecialCases(),
        ];
    }

    /**
     * Principios do modelo. Cada um corresponde a comportamento que existe de
     * fato no codigo — a lista antiga misturava intencao com implementacao.
     *
     * @return array<int, array<string, string>>
     */
    private function buildModelPillars(int $pauseCount, int $finalCount): array
    {
        return [
            [
                'icon' => 'bi-signpost-split-fill',
                'title' => 'Status sugerido, nunca imposto',
                'description' => 'A OS aceita qualquer status ativo. O catálogo de transições apenas destaca as próximas etapas prováveis na tela — desde 09/08/2026 ele deixou de bloquear o destino, porque o técnico avança várias etapas do atendimento antes de registrar no sistema.',
            ],
            [
                'icon' => 'bi-lock-fill',
                'title' => 'Encerramento só pela baixa',
                'description' => 'Os status do grupo Encerramento não podem ser aplicados pelo modal de "Alterar status": só a baixa da OS os grava. Isso impede marcar entrega sem passar pelo fechamento financeiro, e sair desse estado exige cancelar a baixa.',
            ],
            [
                'icon' => 'bi-pause-circle-fill',
                'title' => 'Pausa é estado declarado',
                'description' => sprintf(
                    '%d dos status ativos marcam a OS como parada — espera de peça, de autorização ou de pagamento. A espera aparece como parada declarada, não como uma OS eternamente "em andamento".',
                    $pauseCount
                ),
            ],
            [
                'icon' => 'bi-box-arrow-right',
                'title' => 'Saída classificada',
                'description' => sprintf(
                    'Não existe um único "fechado": as %d saídas finais estão separadas entre concluído, finalizado sem reparo, encerrado e cancelado, para que perda e desistência possam ser analisadas em vez de somem num só balde.',
                    $finalCount
                ),
            ],
            [
                'icon' => 'bi-shield-check',
                'title' => 'Garantia com via própria',
                'description' => 'Garantia não entra na fila comercial comum: tem status próprios de verificação, cumprimento, conclusão e entrega, o que permite separar retorno coberto de serviço novo nos relatórios.',
            ],
            [
                'icon' => 'bi-clock-history',
                'title' => 'Toda mudança vira evento',
                'description' => 'Cada troca de status grava um evento na linha do tempo da OS, com autor e momento. É o que permite reconstruir o atendimento depois — o histórico não depende de alguém ter escrito uma observação.',
            ],
        ];
    }

    /**
     * Uma raia por macrofase, com os status reais dela. Antes as raias eram
     * quatro blocos fixos com etapas inventadas e prazos que ninguem media.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, array<string, mixed>>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function buildWorkflowLanes(\Illuminate\Support\Collection $groups): array
    {
        return $groups
            ->map(static function ($groupStatuses, string $grupoMacro): array {
                $groupStatuses = $groupStatuses->values();

                $pause = $groupStatuses->filter(static fn (array $s): bool => (bool) ($s['status_pausa'] ?? false))->count();
                $final = $groupStatuses->filter(static fn (array $s): bool => (bool) ($s['status_final'] ?? false))->count();

                $chips = [sprintf('%d %s', $groupStatuses->count(), $groupStatuses->count() === 1 ? 'status' : 'status')];
                if ($pause > 0) {
                    $chips[] = sprintf('%d em pausa', $pause);
                }
                if ($final > 0) {
                    $chips[] = sprintf('%d final%s', $final, $final === 1 ? '' : 'is');
                }

                return [
                    'key' => $grupoMacro,
                    'label' => OrderStatusMacroGroups::label($grupoMacro),
                    'description' => OrderStatusMacroGroups::description($grupoMacro),
                    'accent' => OrderStatusMacroGroups::accent($grupoMacro),
                    'soft_accent' => OrderStatusMacroGroups::softAccent($grupoMacro),
                    'chips' => $chips,
                    'steps' => $groupStatuses
                        ->map(static function (array $status): array {
                            $flags = [];
                            if (! empty($status['status_pausa'])) {
                                $flags[] = 'Pausa';
                            }
                            if (! empty($status['status_final'])) {
                                $flags[] = 'Final';
                            }

                            return [
                                'order' => str_pad((string) (int) ($status['ordem_fluxo'] ?? 0), 2, '0', STR_PAD_LEFT),
                                'title' => trim((string) ($status['nome'] ?? '')) ?: 'Sem nome',
                                'code' => trim((string) ($status['codigo'] ?? '')),
                                'flow_state' => OrderStatusMacroGroups::flowStateLabel((string) ($status['estado_fluxo_padrao'] ?? '')),
                                'flags' => $flags,
                            ];
                        })
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildQueueRules(): array
    {
        return [
            [
                'title' => 'Status e estado juntos',
                'icon' => 'bi-link-45deg',
                'rule' => 'a mudança de status grava também o estado de fluxo da OS (em atendimento, em execução, pausado, pronto, encerrado ou cancelado)',
                'impact' => 'a leitura macro da fila não depende de decorar o significado de cada um dos status do catálogo',
            ],
            [
                'title' => 'Pausa visível',
                'icon' => 'bi-pause-circle',
                'rule' => 'esperar peça, autorização ou pagamento exige mover a OS para um status marcado como pausa',
                'impact' => 'a espera fica contável e separada do trabalho realmente em andamento',
            ],
            [
                'title' => 'Baixa fecha a OS',
                'icon' => 'bi-lock',
                'rule' => 'os status de encerramento são gravados pela baixa, não pelo modal de alterar status',
                'impact' => 'não existe OS entregue sem o fechamento financeiro correspondente ter sido feito',
            ],
            [
                'title' => 'Evento por mudança',
                'icon' => 'bi-clock-history',
                'rule' => 'cada troca de status registra autor e momento na linha do tempo da OS',
                'impact' => 'dá para reconstruir o atendimento sem depender da memória de quem atendeu',
            ],
            [
                'title' => 'Prazo é da OS',
                'icon' => 'bi-calendar-check',
                'rule' => 'a previsão de entrega fica na OS (data de previsão), não em cada status',
                'impact' => 'o compromisso com o cliente é um só, em vez de uma soma de prazos por etapa que ninguém acompanha',
            ],
        ];
    }

    /**
     * Ramos que saem do caminho feliz, descritos pelos status reais que os
     * representam no catalogo.
     *
     * @return array<int, array<string, string>>
     */
    private function buildSpecialCases(): array
    {
        return [
            [
                'title' => 'Garantia',
                'icon' => 'bi-shield-check',
                'color' => '#0ea5e9',
                'entry' => 'Verificação de Garantia',
                'rule' => 'segue por Cumprimento de Garantia em vez da execução comercial comum',
                'exit' => 'Garantia Concluída ou Entregue - Reparado em Garantia',
            ],
            [
                'title' => 'Aguardando peça',
                'icon' => 'bi-box-seam',
                'color' => '#d79b00',
                'entry' => 'Aguardando Peça',
                'rule' => 'status de pausa: a OS sai da execução e fica declaradamente parada',
                'exit' => 'volta para Aguardando Reparo ou Em Execução do Serviço',
            ],
            [
                'title' => 'Pendência financeira',
                'icon' => 'bi-credit-card-2-front',
                'color' => '#f59e0b',
                'entry' => 'Pagamento Pendente ou Entregue - Pendência Financeira',
                'rule' => 'não trava a produção já concluída, mas mantém o saldo visível como pausa',
                'exit' => 'Entregue - Reparado e Pago após a quitação',
            ],
            [
                'title' => 'Sem reparo',
                'icon' => 'bi-x-circle-fill',
                'color' => '#b85450',
                'entry' => 'Irreparável ou Reparo Recusado',
                'rule' => 'encerra por um caminho próprio, separado do reparo concluído',
                'exit' => 'Devolvido Sem Reparo, Equipamento Descartado ou Cancelado',
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<string, mixed>
     */
    private function buildNaturalJourneyViewData(array $statuses): array
    {
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
                'owner' => 'Atendimento / comercial',
                'description' => 'O cliente aprova, recusa ou pede ajuste no orçamento enviado.',
                'entry' => 'Orçamento emitido',
                'exit' => 'Autorização registrada',
                'risk' => 'Aprovação sem retorno ativo deixa a OS parada',
            ],
            [
                'key' => 'reparo',
                'label' => 'Aguardando Reparo',
                'resolved_label' => 'Aguardando Reparo',
                'code' => 'aguardando_reparo',
                'kind' => 'status',
                'accent' => '#16a34a',
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
                'owner' => 'Técnico',
                'description' => 'Reparo em andamento, com o técnico responsável registrado na OS.',
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
                'owner' => 'Atendimento',
                'description' => 'OS encerrada tecnicamente e pronta para saída.',
                'entry' => 'Testes finais aprovados',
                'exit' => 'Entrega liberada',
                'risk' => 'Esquecer a baixa operacional',
            ],
            [
                'key' => 'entrega',
                'label' => 'Entregue - Reparado e Pago',
                'resolved_label' => 'Entregue - Reparado e Pago',
                'code' => 'entregue_reparado_pago',
                'kind' => 'status',
                'accent' => '#0f766e',
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
