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
            ->whereNotNull('data_vencimento')
            ->whereBetween('data_vencimento', [$from->toDateString(), $to->toDateString()])
            ->get();

        foreach ($titulos as $titulo) {
            $valor = (float) $titulo->valor;
            $descricao = trim((string) $titulo->descricao);

            yield new AgendaSourceItem(
                originId: (int) $titulo->id,
                titulo: sprintf(
                    '%s: %s',
                    $this->prefixo(),
                    $descricao !== '' ? mb_substr($descricao, 0, 120) : 'sem descrição'
                ),
                inicioEm: CarbonImmutable::parse($titulo->data_vencimento)->setTime(9, 0),
                descricao: sprintf(
                    "Valor: R$ %s\nSituação: %s",
                    number_format($valor, 2, ',', '.'),
                    $this->statusLabel((string) $titulo->status)
                ),
                diaInteiro: true,
                clienteId: (int) $titulo->cliente_id > 0 ? (int) $titulo->cliente_id : null,
                osId: (int) $titulo->os_id > 0 ? (int) $titulo->os_id : null,
                prioridade: $this->prioridade($valor),
                // Resolvido = quitado. O reconciliador conclui o compromisso em
                // vez de apaga-lo, para o dia continuar contando a historia.
                resolvido: (string) $titulo->status === Financeiro::STATUS_PAGO,
            );
        }
    }

    abstract protected function prefixo(): string;

    /** Titulo alto merece destaque visual; o corte e arbitrario e ajustavel. */
    private function prioridade(float $valor): string
    {
        return $valor >= 1000.0
            ? AgendaCompromisso::PRIORIDADE_ALTA
            : AgendaCompromisso::PRIORIDADE_NORMAL;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            Financeiro::STATUS_PARCIAL => 'Parcialmente quitado',
            Financeiro::STATUS_PAGO => 'Quitado',
            default => 'Em aberto',
        };
    }
}
