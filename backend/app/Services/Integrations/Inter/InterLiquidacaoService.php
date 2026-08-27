<?php

namespace App\Services\Integrations\Inter;

use App\Models\Financeiro;
use App\Models\FinanceiroConta;
use App\Models\Inter\InterCobranca;
use App\Models\Inter\InterEvento;
use App\Models\Inter\InterLiquidacao;
use App\Models\OrderEvent;
use App\Services\Financeiro\FinanceiroService;
use App\Services\Notifications\OperationalAlertService;
use App\Services\Orders\OrderEventService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Baixa de cobranca Pix no financeiro.
 *
 * ## A regra que organiza tudo
 *
 * O corpo do webhook (ou o resultado do polling) NUNCA da' baixa sozinho. A
 * unica fonte da verdade e' a reconsulta autenticada `GET /cob/{txid}`. Aqui
 * so' chega o que o banco confirmou.
 *
 * ## Idempotencia
 *
 * O primeiro passo de cada liquidacao e' um INSERT em `inter_liquidacoes` com
 * o `e2eid`, que tem UNIQUE. Duas entregas concorrentes do mesmo Pix fazem a
 * segunda bater em violacao de constraint — quem resolve a corrida e' o banco
 * de dados, nao a ordem de execucao do PHP.
 *
 * Insert PRIMEIRO, baixa depois. Na ordem inversa, duas execucoes simultaneas
 * poderiam ambas passar pelo `registerMovement` antes de qualquer uma gravar a
 * marca.
 */
class InterLiquidacaoService
{
    /** Diferenca abaixo disto e' arredondamento, nao divergencia. */
    private const TOLERANCIA = 0.01;

    public function __construct(
        private readonly InterClient $client,
        private readonly FinanceiroService $financeiroService,
        private readonly OrderEventService $orderEventService,
        private readonly OperationalAlertService $alertas,
    ) {
    }

    /**
     * Reconsulta a cobranca no banco e liquida o que estiver confirmado.
     *
     * @return array<string, int> resumo do que aconteceu
     */
    public function conciliar(InterCobranca $cobranca): array
    {
        $resumo = ['liquidadas' => 0, 'ja_processadas' => 0, 'divergentes' => 0];

        $resposta = $this->reconsultar($cobranca);

        if ($resposta === null) {
            return $resumo;
        }

        $this->atualizarStatus($cobranca, $resposta);

        foreach ($this->extrairPix($resposta) as $pix) {
            $desfecho = $this->liquidarPix($cobranca, $pix, $resposta);

            if (isset($resumo[$desfecho])) {
                $resumo[$desfecho]++;
            }
        }

        return $resumo;
    }

    /**
     * @param  array<string, mixed>  $pix
     * @param  array<string, mixed>  $reconsulta
     */
    private function liquidarPix(InterCobranca $cobranca, array $pix, array $reconsulta): string
    {
        $e2eid = trim((string) ($pix['endToEndId'] ?? $pix['e2eid'] ?? ''));
        $valor = round((float) ($pix['valor'] ?? 0), 2);

        if ($e2eid === '' || $valor <= 0) {
            InterEvento::registrar([
                'txid' => $cobranca->txid,
                'evento' => 'pix_ignorado',
                'nivel' => 'warning',
                'origem' => InterEvento::ORIGEM_POLLING,
                'decisao' => InterEvento::DECISAO_IGNORADO,
                'motivo' => 'Pix sem endToEndId ou sem valor.',
                'payload_reconsulta' => $pix,
            ]);

            return 'ignorado';
        }

        // PASSO 1 — a marca antes da baixa. Violacao de UNIQUE aqui significa
        // que outra execucao ja pegou este Pix.
        try {
            $liquidacao = InterLiquidacao::query()->create([
                'inter_cobranca_id' => $cobranca->id,
                'e2eid' => $e2eid,
                'valor' => $valor,
                'horario' => $this->horarioDoPix($pix),
                'payload' => $pix,
            ]);
        } catch (QueryException $e) {
            if (! $this->ehViolacaoDeUnicidade($e)) {
                throw $e;
            }

            InterEvento::registrar([
                'txid' => $cobranca->txid,
                'e2eid' => $e2eid,
                'evento' => 'liquidacao_duplicada',
                'nivel' => 'info',
                'origem' => InterEvento::ORIGEM_POLLING,
                'decisao' => InterEvento::DECISAO_JA_PROCESSADO,
                'motivo' => 'e2eid ja registrado; nada a fazer.',
            ]);

            return 'ja_processadas';
        }

        $titulo = $cobranca->financeiro;

        if (! $titulo instanceof Financeiro) {
            $this->marcarParaRevisao($cobranca, $liquidacao, 'Cobranca sem titulo financeiro vinculado.');

            return 'divergentes';
        }

        $saldoAberto = round((float) $this->financeiroService->movementSummary($titulo)['valor_aberto'], 2);

        // Pagamento MAIOR que o saldo em aberto nao vira baixa automatica: o
        // excedente exige decisao humana (devolver? abater outro titulo?), e
        // registerMovement recusaria de qualquer forma. Menor e' pagamento
        // parcial, que e' legitimo.
        if ($valor > $saldoAberto + self::TOLERANCIA) {
            $this->marcarParaRevisao(
                $cobranca,
                $liquidacao,
                sprintf(
                    'Valor recebido (R$ %s) e maior que o saldo em aberto (R$ %s).',
                    number_format($valor, 2, ',', '.'),
                    number_format($saldoAberto, 2, ',', '.')
                )
            );

            return 'divergentes';
        }

        $this->registrarBaixa($cobranca, $titulo, $liquidacao, $valor, $reconsulta);

        return 'liquidadas';
    }

    private function registrarBaixa(
        InterCobranca $cobranca,
        Financeiro $titulo,
        InterLiquidacao $liquidacao,
        float $valor,
        array $reconsulta
    ): void {
        // registerMovement e' o UNICO ponto de baixa do sistema: ele ja faz
        // transacao, lockForUpdate e recusa valor acima do saldo. Criar um
        // caminho paralelo aqui duplicaria essas regras.
        $resultado = $this->financeiroService->registerMovement($titulo, [
            'valor_movimento' => $valor,
            'forma_pagamento' => 'pix',
            'data_movimento' => ($liquidacao->horario ?? now())->toDateString(),
            'documento_ref' => $liquidacao->e2eid,
            'observacoes' => 'Baixa automatica via Banco Inter (txid '.$cobranca->txid.').',
            'conta_financeira_id' => $this->contaDoInter()?->id,
        ]);

        $liquidacao->update(['financeiro_movimento_id' => $resultado['movement_id'] ?? null]);

        InterEvento::registrar([
            'txid' => $cobranca->txid,
            'e2eid' => $liquidacao->e2eid,
            'evento' => 'liquidada',
            'nivel' => 'info',
            'origem' => InterEvento::ORIGEM_POLLING,
            'decisao' => InterEvento::DECISAO_LIQUIDADO,
            'motivo' => 'Baixa registrada no titulo '.$titulo->id.'.',
            'payload_reconsulta' => $reconsulta,
        ]);

        Log::channel('pagamentos')->info('[INTER] Baixa automatica registrada.', [
            'txid' => $cobranca->txid,
            'e2eid' => $liquidacao->e2eid,
            'financeiro_id' => $titulo->id,
            'valor' => $valor,
        ]);

        $osId = (int) ($titulo->os_id ?? 0);

        if ($osId > 0) {
            // origem=automacao e usuario_id=null: no historico da OS fica
            // claro que quem deu a baixa foi a maquina, nao uma pessoa.
            $this->orderEventService->record(
                $osId,
                OrderEvent::CATEGORIA_FINANCEIRO,
                'cobranca_pix_liquidada',
                'Pagamento Pix recebido',
                sprintf('R$ %s confirmados pelo Banco Inter.', number_format($valor, 2, ',', '.')),
                ['txid' => $cobranca->txid, 'e2eid' => $liquidacao->e2eid, 'valor' => $valor],
                null,
                OrderEvent::ORIGEM_AUTOMACAO
            );
        }
    }

    /**
     * Pix confirmado que NAO virou baixa. A liquidacao fica gravada com
     * `financeiro_movimento_id` nulo — dinheiro que entrou na conta e ainda nao
     * esta refletido no sistema precisa ficar visivel, nao sumir.
     */
    private function marcarParaRevisao(InterCobranca $cobranca, InterLiquidacao $liquidacao, string $motivo): void
    {
        InterEvento::registrar([
            'txid' => $cobranca->txid,
            'e2eid' => $liquidacao->e2eid,
            'evento' => 'liquidacao_pendente_de_revisao',
            'nivel' => 'warning',
            'origem' => InterEvento::ORIGEM_POLLING,
            'decisao' => InterEvento::DECISAO_VALOR_DIVERGENTE,
            'motivo' => mb_substr($motivo, 0, 500),
        ]);

        Log::channel('pagamentos')->warning('[INTER] Pix recebido sem baixa automatica.', [
            'txid' => $cobranca->txid,
            'e2eid' => $liquidacao->e2eid,
            'motivo' => $motivo,
        ]);

        $this->alertas->urgente(
            'Pix recebido sem baixa automatica',
            $motivo."\n\nO dinheiro entrou na conta. Confira e lance manualmente.",
            [
                'txid' => $cobranca->txid,
                'e2eid' => $liquidacao->e2eid,
                'valor' => (float) $liquidacao->valor,
            ],
            'inter:revisao:'.$liquidacao->e2eid
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reconsultar(InterCobranca $cobranca): ?array
    {
        try {
            return $this->client->get(
                rtrim((string) config('inter.cobranca.path', 'pix/v2/cob'), '/').'/'.$cobranca->txid,
                (array) config('inter.escopos.cobranca', ['cob.write', 'cob.read'])
            );
        } catch (InterException $e) {
            if ($e->statusHttp === 404) {
                // Emissao que nunca chegou ao banco. Encerra o rastro.
                $cobranca->update([
                    'status' => InterCobranca::STATUS_REMOVIDA_PELO_PSP,
                    'cancelada_em' => now(),
                ]);
            }

            InterEvento::registrar([
                'txid' => $cobranca->txid,
                'evento' => 'reconsulta_falhou',
                'nivel' => $e->ehFalhaTemporaria() ? 'warning' : 'error',
                'origem' => InterEvento::ORIGEM_POLLING,
                'http_status' => $e->statusHttp,
                'decisao' => InterEvento::DECISAO_ERRO,
                'motivo' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $resposta
     */
    private function atualizarStatus(InterCobranca $cobranca, array $resposta): void
    {
        $status = trim((string) ($resposta['status'] ?? ''));

        if ($status !== '' && $status !== $cobranca->status) {
            $cobranca->update(['status' => $status]);
        }
    }

    /**
     * @param  array<string, mixed>  $resposta
     * @return array<int, array<string, mixed>>
     */
    private function extrairPix(array $resposta): array
    {
        $pix = $resposta['pix'] ?? [];

        return is_array($pix) ? array_values(array_filter($pix, 'is_array')) : [];
    }

    /**
     * @param  array<string, mixed>  $pix
     */
    private function horarioDoPix(array $pix): Carbon
    {
        $horario = trim((string) ($pix['horario'] ?? ''));

        try {
            return $horario !== '' ? Carbon::parse($horario) : now();
        } catch (Throwable) {
            return now();
        }
    }

    /**
     * Conta interna vinculada ao Inter, para o movimento apontar para onde o
     * dinheiro realmente caiu.
     */
    private function contaDoInter(): ?FinanceiroConta
    {
        return FinanceiroConta::query()
            ->where('integracao_provider', 'inter')
            ->where('ativo', true)
            ->orderBy('id')
            ->first();
    }

    private function ehViolacaoDeUnicidade(QueryException $e): bool
    {
        // 23000 (SQLSTATE integrity constraint) cobre MySQL e SQLite.
        return (string) $e->getCode() === '23000';
    }
}
