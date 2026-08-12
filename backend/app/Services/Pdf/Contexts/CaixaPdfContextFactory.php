<?php

namespace App\Services\Pdf\Contexts;

use App\Models\CaixaMovimento;
use App\Models\CaixaSessao;
use App\Services\Caixa\CaixaSessionService;

/**
 * Contexto do relatório de fechamento de caixa — specs/028-caixa-sessoes.
 *
 * Não estende OrderPdfContextFactory: um turno de caixa não tem OS, cliente nem
 * equipamento. `empresa.*` e `documento.*` vêm do PdfGenerationService.
 */
class CaixaPdfContextFactory implements PdfContextFactoryInterface
{
    public function __construct(private readonly CaixaSessionService $caixaSessionService) {}

    /**
     * @param  array<string, mixed>  $subject
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $subject, array $options = []): array
    {
        $session = $this->resolveSession($subject);

        if (! $session instanceof CaixaSessao) {
            return [];
        }

        $session->loadMissing(['operator', 'closedBy', 'account', 'movements.responsible']);

        // Turno ainda aberto imprime a prévia com os números correntes; fechado
        // imprime a fotografia congelada no fechamento.
        $aberto = $session->isOpen();
        $totals = $aberto
            ? $this->caixaSessionService->sessionTotals($session)
            : [
                'vendas_dinheiro' => (float) $session->total_vendas_dinheiro,
                'suprimentos' => (float) $session->total_suprimentos,
                'sangrias' => (float) $session->total_sangrias,
                'quantidade_vendas' => (int) $session->quantidade_vendas,
            ];

        $esperado = $aberto
            ? $this->caixaSessionService->expectedAmount($session)
            : (float) $session->valor_esperado;

        return [
            'caixa' => [
                'numero' => '#'.(int) $session->id,
                'status' => CaixaSessao::statusLabel($session->status),
                'conta' => (string) ($session->account?->nome ?? ''),
                'operador' => (string) ($session->operator?->nome ?? ''),
                'fechado_por' => (string) ($session->closedBy?->nome ?? ''),
                'aberto_em' => $session->aberto_em,
                'fechado_em' => $session->fechado_em,
                'valor_abertura' => round((float) $session->valor_abertura, 2),
                'total_vendas' => round($totals['vendas_dinheiro'], 2),
                'quantidade_vendas' => (int) $totals['quantidade_vendas'],
                'total_suprimentos' => round($totals['suprimentos'], 2),
                'total_sangrias' => round($totals['sangrias'], 2),
                'valor_esperado' => round($esperado, 2),
                'valor_informado' => $session->valor_informado !== null
                    ? round((float) $session->valor_informado, 2)
                    : 0.0,
                'diferenca' => $session->diferenca !== null ? round((float) $session->diferenca, 2) : 0.0,
                'observacoes' => (string) ($session->observacoes_fechamento ?? ''),
            ],
            'movimentos' => $session->movements->map(static fn (CaixaMovimento $m): array => [
                'tipo' => CaixaMovimento::typeLabel($m->tipo),
                'motivo' => (string) $m->motivo,
                'responsavel' => (string) ($m->responsible?->nome ?? ''),
                'valor' => round((float) $m->valor, 2),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $subject
     */
    private function resolveSession(array $subject): ?CaixaSessao
    {
        $session = $subject['session'] ?? $subject['sessao'] ?? $subject['caixa'] ?? null;

        if ($session instanceof CaixaSessao) {
            return $session;
        }

        $id = (int) ($subject['session_id'] ?? $subject['caixa_sessao_id'] ?? 0);

        return $id > 0 ? CaixaSessao::query()->find($id) : null;
    }
}
