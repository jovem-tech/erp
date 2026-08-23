<?php

namespace App\Services\Agenda\Sources;

use App\Models\AgendaCompromisso;
use App\Models\Financeiro;
use Carbon\CarbonImmutable;

/**
 * Vencimentos de titulos financeiros (contas a pagar e a receber).
 *
 * Abstrata porque pagar e receber sao duas fontes com chaves distintas - o
 * usuario filtra "so o que eu devo" sem arrastar junto o que tem a receber -
 * mas com exatamente a mesma consulta.
 */
abstract class ContasVencimentoSource implements AgendaSource
{
    /** @return Financeiro::TIPO_* */
    abstract protected function tipo(): string;

    public function collect(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $titulos = Financeiro::query()
            ->where('tipo', $this->tipo())
            // Cancelado fica de fora da coleta: o titulo deixou de ser uma
            // obrigacao, e o reconciliador cancela o compromisso justamente
            // por nao ve-lo mais aqui.
            ->whereIn('status', [
                Financeiro::STATUS_PENDENTE,
                Financeiro::STATUS_PARCIAL,
                Financeiro::STATUS_PAGO,
            ])
            // Titulo de valor zero nao e obrigacao nenhuma - nao ha o que pagar
            // nem o que cobrar. A baixa da OS grava um lancamento de cobranca
            // mesmo quando nao sobra saldo (garantia, sem custo, devolvido sem
            // reparo), e eles inundavam a agenda com "Receber: Cobranca da OS"
            // que ninguem jamais precisaria fazer. Ficando de fora da coleta,
            // o reconciliador ainda cancela os que ja tinham sido criados.
            ->where('valor', '>', 0)
            ->whereNotNull('data_vencimento')
            ->whereBetween('data_vencimento', [$from->toDateString(), $to->toDateString()])
            // Soma dos movimentos em uma consulta so: sem isto seriam centenas
            // de SELECTs a cada rodada do reconciliador.
            ->withSum('movimentos as total_movimentado', 'valor_movimento')
            ->get();

        foreach ($titulos as $titulo) {
            $valor = round((float) $titulo->valor, 2);
            $movimentado = round((float) ($titulo->total_movimentado ?? 0), 2);
            $aberto = round($valor - $movimentado, 2);
            $descricao = trim((string) $titulo->descricao);

            yield new AgendaSourceItem(
                originId: (int) $titulo->id,
                titulo: sprintf(
                    '%s: %s',
                    $this->prefixo(),
                    $descricao !== '' ? mb_substr($descricao, 0, 120) : 'sem descrição'
                ),
                inicioEm: CarbonImmutable::parse($titulo->data_vencimento)->setTime(9, 0),
                descricao: $this->descricao($valor, $movimentado, $aberto),
                diaInteiro: true,
                clienteId: (int) $titulo->cliente_id > 0 ? (int) $titulo->cliente_id : null,
                osId: (int) $titulo->os_id > 0 ? (int) $titulo->os_id : null,
                // A prioridade acompanha o que FALTA, nao o que o titulo valia:
                // um titulo de R$ 5.000 com R$ 50 em aberto nao merece destaque.
                prioridade: $this->prioridade($aberto),
                // Resolvido = nao ha mais saldo. Olhar o saldo, e nao so o
                // `status`, cobre o titulo integralmente liquidado que ficou com
                // status desatualizado - ele parava de exigir acao e continuava
                // na agenda.
                resolvido: $aberto <= 0 || (string) $titulo->status === Financeiro::STATUS_PAGO,
            );
        }
    }

    /**
     * Mostra o que falta, nao o valor de face. "R$ 586,00" num titulo com
     * R$ 386,00 ja recebidos faz o usuario cobrar o valor errado.
     */
    private function descricao(float $valor, float $movimentado, float $aberto): string
    {
        if ($movimentado <= 0) {
            return sprintf('Valor: R$ %s', number_format($valor, 2, ',', '.'));
        }

        return sprintf(
            "Em aberto: R$ %s\nTítulo: R$ %s — já %s R$ %s",
            number_format(max(0, $aberto), 2, ',', '.'),
            number_format($valor, 2, ',', '.'),
            $this->verboLiquidado(),
            number_format($movimentado, 2, ',', '.')
        );
    }

    abstract protected function prefixo(): string;

    /** "pago" para contas a pagar, "recebido" para contas a receber. */
    abstract protected function verboLiquidado(): string;

    /** Valor alto merece destaque visual; o corte e arbitrario e ajustavel. */
    private function prioridade(float $aberto): string
    {
        return $aberto >= 1000.0
            ? AgendaCompromisso::PRIORIDADE_ALTA
            : AgendaCompromisso::PRIORIDADE_NORMAL;
    }

}
