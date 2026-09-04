<?php

namespace App\Services\Fiscal;

use App\Models\Budget;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * Único lugar que monta o texto de "Discriminação dos serviços" da NFS-e.
 *
 * Usado tanto pela baixa da OS (rascunho exibido antes de fechar, garantia
 * ainda não confirmada) quanto pela tela fiscal/nota (rascunho do documento já
 * fechado, garantia já gravada em `os.garantia_dias`) — extraído pra dar 042 e
 * fase pra deixarem de divergir sobre o que a nota diz.
 */
class DiscriminacaoNfseBuilder
{
    /**
     * Texto completo, incluindo garantia. Usado pelo documento fiscal já
     * fechado — a garantia vem de `order.garantia_dias`, já decidida na baixa.
     */
    public function montar(Order $order): string
    {
        return $this->cabecalho($order)."\n".$this->corpo($order, (int) ($order->garantia_dias ?? 0));
    }

    /**
     * Texto sem a garantia — a baixa ainda não fechou, então `garantia_dias`
     * do pedido ainda não existe. A tela usa isso como base e o JS completa
     * com o prazo que o operador for escolhendo no formulário (ver
     * orders-closure.js), sem precisar de outra chamada ao backend a cada troca.
     */
    public function base(Order $order): string
    {
        return $this->cabecalho($order)."\n".$this->corpo($order, null);
    }

    private function cabecalho(Order $order): string
    {
        return sprintf('Ordem de servico %s', (string) ($order->numero_os ?? $order->id));
    }

    private function corpo(Order $order, ?int $garantiaDias): string
    {
        $equipamento = $this->descritoresEquipamento($order);
        $servicos = $this->descricoesServico($order);
        $materiais = $this->nomesMateriais($order);

        $frase = 'Prestação de serviço de assistência técnica'
            .($equipamento !== [] ? ' em aparelho '.implode(', ', $equipamento) : '');

        if ($servicos !== []) {
            $frase .= ': '.implode('; ', $servicos);
        }

        $frase .= $materiais !== []
            ? ', com a substituição e instalação de '.implode(', ', $materiais).', incluindo o fornecimento dos materiais aplicados.'
            : '.';

        if ($garantiaDias !== null) {
            $rotulo = Budget::warrantyLabel($garantiaDias);

            if ($rotulo !== '') {
                $frase .= ' Período de garantia do serviço e insumos: '.$rotulo.'.';
            }
        }

        return $frase;
    }

    /**
     * @return array<int, string>
     */
    private function descritoresEquipamento(Order $order): array
    {
        $equipamento = $order->equipment;

        return array_values(array_filter([
            trim((string) ($equipamento?->type?->nome ?? '')),
            trim((string) ($equipamento?->brand?->nome ?? '')),
            trim((string) ($equipamento?->model?->nome ?? '')),
            trim((string) ($equipamento?->cor ?? '')) !== '' ? 'cor '.trim((string) $equipamento->cor) : '',
            trim((string) ($equipamento?->numero_serie ?? '')) !== '' ? 'N° de série '.trim((string) $equipamento->numero_serie) : '',
            trim((string) ($equipamento?->imei ?? '')) !== '' ? 'IMEI '.trim((string) $equipamento->imei) : '',
        ], static fn (string $parte): bool => $parte !== ''));
    }

    /**
     * O que foi executado, em ordem de fonte — mesma cadeia de
     * NotaFiscalEnvioService::servicoExecutado(): item da OS primeiro (o que a
     * oficina mais preenche), orçamento aprovado depois (cobre a OS que nasceu
     * de orçamento formal e nunca ganhou item próprio — comum: de 32
     * orçamentos aprovados com OS vinculada, só 1 também tinha item na OS).
     *
     * @return array<int, string>
     */
    private function descricoesServico(Order $order): array
    {
        $descricoes = $this->descricoesDosItens($order, 'servico');

        if ($descricoes !== []) {
            return $descricoes;
        }

        $orcamento = Budget::query()
            ->where('os_id', $order->id)
            ->where('status', Budget::STATUS_APPROVED)
            ->orderByDesc('id')
            ->first();

        if ($orcamento === null) {
            return [];
        }

        return $orcamento->items()
            ->where('tipo_item', 'servico')
            ->orderBy('ordem')
            ->get()
            ->map(static fn ($item): string => trim((string) ($item->descricao ?? '')))
            ->filter(static fn (string $descricao): bool => $descricao !== '')
            ->values()
            ->all();
    }

    /**
     * Nome da(s) peça(s) trocada(s), sem valor — o VALOR continua fora da
     * NFS-e e da soma tributada (peça é mercadoria, sai por NF-e/NFC-e e entra
     * no rateio do Anexo do Simples como tal; ver
     * DocumentoFiscalService::valoresLiquidos()). Mesma cadeia de fonte do
     * serviço: item da OS primeiro, orçamento aprovado como reforço.
     *
     * @return array<int, string>
     */
    private function nomesMateriais(Order $order): array
    {
        $nomes = $this->descricoesDosItens($order, 'peca');

        $orcamento = Budget::query()
            ->where('os_id', $order->id)
            ->where('status', Budget::STATUS_APPROVED)
            ->orderByDesc('id')
            ->first();

        if ($orcamento !== null) {
            $nomes = array_merge($nomes, $orcamento->items()
                ->where('tipo_item', 'peca')
                ->orderBy('ordem')
                ->get()
                ->map(static fn ($item): string => trim((string) ($item->descricao ?? '')))
                ->filter(static fn (string $descricao): bool => $descricao !== '')
                ->values()
                ->all());
        }

        return array_values(array_unique($nomes));
    }

    /**
     * @return array<int, string>
     */
    private function descricoesDosItens(Order $order, string $tipo): array
    {
        return OrderItem::query()
            ->where('os_id', $order->id)
            ->where('tipo', $tipo)
            ->orderBy('id')
            ->get()
            ->map(static fn (OrderItem $item): string => trim((string) ($item->descricao ?? '')))
            ->filter(static fn (string $descricao): bool => $descricao !== '')
            ->unique()
            ->values()
            ->all();
    }
}
