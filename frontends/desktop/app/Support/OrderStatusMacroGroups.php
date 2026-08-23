<?php

namespace App\Support;

/**
 * Vocabulário compartilhado das macrofases (`os_status.grupo_macro`) e dos
 * estados de fluxo (`os_status.estado_fluxo_padrao`) do catálogo de status de OS.
 *
 * Vive aqui, e não dentro de um controller, porque duas telas leem o mesmo
 * catálogo e precisam chamar as fases pelo mesmo nome: o cadastro de status
 * (`OrderStatusFlowController`) e o Modelo da Assistência Técnica
 * (`AssistanceModelController`). Quando isso era método privado de um dos dois,
 * o outro repetia os rótulos por conta própria e eles divergiam.
 */
class OrderStatusMacroGroups
{
    public static function label(string $grupoMacro): string
    {
        return match (mb_strtolower(trim($grupoMacro))) {
            'recepcao' => 'Recepção',
            'diagnostico' => 'Diagnóstico',
            'orcamento' => 'Orçamento',
            'execucao' => 'Execução',
            'qualidade' => 'Qualidade',
            'interrupcao' => 'Interrupção',
            'concluido' => 'Concluído',
            'finalizado_sem_reparo' => 'Finalizado sem Reparo',
            'encerrado' => 'Encerramento',
            'cancelado' => 'Cancelado',
            default => self::humanizeSlug($grupoMacro),
        };
    }

    public static function description(string $grupoMacro): string
    {
        return match (mb_strtolower(trim($grupoMacro))) {
            'recepcao' => 'Entrada, conferência inicial e triagem da OS.',
            'diagnostico' => 'Levantamento técnico, verificação de causa e definição do caminho.',
            'orcamento' => 'Aprovação comercial, retorno do cliente e decisão de continuidade.',
            'execucao' => 'Reparo, testes, validação técnica e acompanhamento da solução.',
            'qualidade' => 'Testes operacionais e finais antes de liberar a OS.',
            'interrupcao' => 'Pausas do fluxo: espera de peça, pagamento ou pendência financeira.',
            'concluido' => 'Reparo concluído, disponível na loja ou garantia concluída.',
            'finalizado_sem_reparo' => 'Sem reparo: irreparável, disponível para retirada ou recusado.',
            'encerrado' => 'Entrega, devolução sem reparo ou descarte do equipamento.',
            'cancelado' => 'Atendimento cancelado.',
            default => 'Fase operacional agrupada por macroprocesso.',
        };
    }

    public static function accent(string $grupoMacro): string
    {
        return match (mb_strtolower(trim($grupoMacro))) {
            'recepcao' => '#0ea5e9',
            'diagnostico' => '#6f5afc',
            'orcamento' => '#f59e0b',
            'execucao' => '#16a34a',
            'qualidade' => '#d6b656',
            'interrupcao' => '#d79b00',
            'concluido' => '#22c55e',
            'finalizado_sem_reparo' => '#b85450',
            'encerrado' => '#64748b',
            'cancelado' => '#ef4444',
            default => '#6f5afc',
        };
    }

    public static function softAccent(string $grupoMacro): string
    {
        return match (mb_strtolower(trim($grupoMacro))) {
            'recepcao' => 'rgba(14, 165, 233, 0.12)',
            'diagnostico' => 'rgba(111, 90, 252, 0.12)',
            'orcamento' => 'rgba(245, 158, 11, 0.14)',
            'execucao' => 'rgba(22, 163, 74, 0.12)',
            'qualidade' => 'rgba(214, 182, 86, 0.16)',
            'interrupcao' => 'rgba(215, 155, 0, 0.14)',
            'concluido' => 'rgba(34, 197, 94, 0.12)',
            'finalizado_sem_reparo' => 'rgba(184, 84, 80, 0.12)',
            'encerrado' => 'rgba(100, 116, 139, 0.12)',
            'cancelado' => 'rgba(239, 68, 68, 0.12)',
            default => 'rgba(111, 90, 252, 0.12)',
        };
    }

    /**
     * Rótulo de `estado_fluxo_padrao`. O catálogo real usa seis valores —
     * `pronto` e `cancelado` existem no banco e faltavam no match antigo,
     * caindo no humanizeSlug() por acidente.
     */
    public static function flowStateLabel(string $flowState): string
    {
        return match (mb_strtolower(trim($flowState))) {
            'em_atendimento' => 'Em atendimento',
            'em_execucao' => 'Em execução',
            'pausado' => 'Pausado',
            'pronto' => 'Pronto',
            'encerrado' => 'Encerrado',
            'cancelado' => 'Cancelado',
            default => self::humanizeSlug($flowState),
        };
    }

    public static function humanizeSlug(string $value): string
    {
        $value = trim(str_replace(['_', '-'], ' ', $value));

        return $value !== '' ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : 'Sem grupo macro';
    }
}
