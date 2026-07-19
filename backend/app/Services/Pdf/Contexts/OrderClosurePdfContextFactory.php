<?php

namespace App\Services\Pdf\Contexts;

/**
 * Contexto do comprovante de encerramento da OS: contexto da OS + bloco
 * encerramento.* + coleção recebimentos, vindos do fluxo de baixa
 * (OrderClosureService monta esses dados e os repassa em $options).
 */
class OrderClosurePdfContextFactory extends OrderPdfContextFactory
{
    public function build(array $subject, array $options = []): array
    {
        $context = parent::build($subject, $options);
        if ($context === []) {
            return [];
        }

        $context['encerramento'] = [
            'status_final' => (string) ($options['status_final_nome'] ?? ''),
            'data_entrega' => (string) ($options['data_entrega'] ?? ''),
            'observacao' => (string) ($options['observacao_encerramento'] ?? ''),
            'valor_titulo' => (float) ($options['valor_titulo'] ?? 0),
            'saldo_restante' => (float) ($options['saldo_restante'] ?? 0),
        ];

        $recebimentos = is_array($options['recebimentos'] ?? null) ? $options['recebimentos'] : [];
        $context['recebimentos'] = array_values(array_map(
            static fn (array $recebimento): array => [
                'forma_pagamento' => (string) ($recebimento['forma_pagamento'] ?? ''),
                'valor' => (float) ($recebimento['valor'] ?? 0),
                'data' => (string) ($recebimento['data_pagamento'] ?? $recebimento['data'] ?? ''),
            ],
            array_filter($recebimentos, 'is_array')
        ));

        return $context;
    }
}
