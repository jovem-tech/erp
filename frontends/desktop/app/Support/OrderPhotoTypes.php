<?php

namespace App\Support;

/**
 * Categorias das fotos da OS (`os_fotos.tipo`, enum no banco do legado) e a
 * sugestão de categoria para quem anexa foto direto da visualização da OS
 * (specs/048). Um só lugar para o rótulo — o parcial da galeria, o seletor do
 * uploader e a mensagem de sucesso do controller liam cada um a sua cópia.
 */
final class OrderPhotoTypes
{
    public const LABELS = [
        'recepcao' => 'Recepção',
        'diagnostico' => 'Diagnóstico',
        'entrega' => 'Entrega',
    ];

    public static function label(string $tipo): string
    {
        return self::LABELS[$tipo] ?? 'Foto';
    }

    /**
     * Categoria mais provável para a fase atual da OS (`status_grupo_macro`):
     * foto tirada na triagem é de recepção; depois que o reparo acabou (ou a OS
     * saiu do fluxo) é de entrega; no meio do atendimento — diagnóstico,
     * orçamento, espera, execução, testes — é registro técnico, que o enum só
     * permite chamar de diagnóstico. É só a sugestão: o técnico troca na fila.
     */
    public static function suggestedFor(string $grupoMacro): string
    {
        return match ($grupoMacro) {
            'recepcao' => 'recepcao',
            'concluido', 'finalizado_sem_reparo', 'encerrado', 'cancelado' => 'entrega',
            default => 'diagnostico',
        };
    }
}
