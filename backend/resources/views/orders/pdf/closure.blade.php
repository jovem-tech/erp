<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>OS {{ $numeroOs ?? $order->numero_os }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2933; }
        h1 { font-size: 16px; margin: 0 0 2px 0; }
        h2 { font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #52606d; margin: 18px 0 6px 0; border-bottom: 1px solid #d9e2ec; padding-bottom: 3px; }
        p { margin: 0 0 4px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.items th, table.items td { border: 1px solid #d9e2ec; padding: 5px 6px; text-align: left; }
        table.items th { background: #f0f4f8; font-size: 10px; text-transform: uppercase; }
        table.items td.numeric, table.items th.numeric { text-align: right; }
        table.meta td { padding: 2px 0; vertical-align: top; }
        table.meta td.label { width: 150px; color: #52606d; }
        .header { border-bottom: 2px solid #1f2933; padding-bottom: 8px; margin-bottom: 8px; }
        .muted { color: #52606d; }
        .total-row td { font-weight: bold; border-top: 2px solid #1f2933; }
        .footer { margin-top: 24px; font-size: 9px; color: #7b8794; text-align: center; }
        .observacao-box { background: #f0f4f8; border: 1px solid #d9e2ec; padding: 8px; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Comprovante de Encerramento — OS {{ $numeroOs ?? $order->numero_os }}</h1>
        <p class="muted">Emitido em {{ $geradoEm->format('d/m/Y H:i') }}</p>
    </div>

    <h2>Cliente e equipamento</h2>
    <table class="meta">
        <tr>
            <td class="label">Cliente</td>
            <td>{{ $order->client?->nome_razao ?? '—' }}</td>
        </tr>
        @if (trim((string) ($order->client?->telefone1 ?? '')) !== '')
        <tr>
            <td class="label">Telefone</td>
            <td>{{ $order->client->telefone1 }}</td>
        </tr>
        @endif
        <tr>
            <td class="label">Equipamento</td>
            <td>{{ $order->equipment?->resumo_tecnico ?? '—' }}</td>
        </tr>
        @if (trim((string) ($order->equipment?->numero_serie ?? '')) !== '')
        <tr>
            <td class="label">Nº de série</td>
            <td>{{ $order->equipment->numero_serie }}</td>
        </tr>
        @endif
    </table>

    @if (trim((string) ($order->relato_cliente ?? '')) !== '')
        <h2>Relato do cliente</h2>
        <p>{{ $order->relato_cliente }}</p>
    @endif

    @if (trim((string) ($order->diagnostico_tecnico ?? '')) !== '' || trim((string) ($order->solucao_aplicada ?? '')) !== '')
        <h2>Diagnóstico e solução</h2>
        @if (trim((string) ($order->diagnostico_tecnico ?? '')) !== '')
            <p><strong>Diagnóstico:</strong> {{ $order->diagnostico_tecnico }}</p>
        @endif
        @if (trim((string) ($order->solucao_aplicada ?? '')) !== '')
            <p><strong>Solução aplicada:</strong> {{ $order->solucao_aplicada }}</p>
        @endif
    @endif

    @if ($itens->isNotEmpty())
        <h2>Itens da OS</h2>
        <table class="items">
            <thead>
                <tr>
                    <th>Descrição</th>
                    <th class="numeric">Qtd.</th>
                    <th class="numeric">Valor unit.</th>
                    <th class="numeric">Valor total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($itens as $item)
                    <tr>
                        <td>{{ $item->descricao }}</td>
                        <td class="numeric">{{ number_format((float) $item->quantidade, 0, ',', '.') }}</td>
                        <td class="numeric">R$ {{ number_format((float) $item->valor_unitario, 2, ',', '.') }}</td>
                        <td class="numeric">R$ {{ number_format((float) $item->valor_total, 2, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Encerramento</h2>
    <table class="meta">
        <tr>
            <td class="label">Status final</td>
            <td>{{ $statusFinalNome }}</td>
        </tr>
        <tr>
            <td class="label">Data da entrega</td>
            <td>{{ \Illuminate\Support\Carbon::parse($dataEntrega)->format('d/m/Y') }}</td>
        </tr>
    </table>
    @if (trim((string) ($observacaoEncerramento ?? '')) !== '')
        <div class="observacao-box">{{ $observacaoEncerramento }}</div>
    @endif

    <h2>Resumo financeiro</h2>
    @php
        $formasPagamento = [
            'dinheiro' => 'Dinheiro',
            'cartao_credito' => 'Cartão de crédito',
            'cartao_debito' => 'Cartão de débito',
            'pix' => 'Pix',
            'boleto' => 'Boleto',
            'transferencia' => 'Transferência',
        ];
        $totalRecebidoNestaBaixa = array_sum(array_map(static fn ($r) => (float) ($r['valor'] ?? 0), $recebimentos ?? []));
    @endphp
    <table class="meta">
        <tr>
            <td class="label">Valor final da OS</td>
            <td>R$ {{ number_format((float) $valorFinal, 2, ',', '.') }}</td>
        </tr>
    </table>

    @if (! empty($recebimentos))
        <table class="items" style="margin-top: 8px;">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Forma de pagamento</th>
                    <th class="numeric">Valor</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recebimentos as $recebimento)
                    <tr>
                        <td>{{ !empty($recebimento['data_pagamento']) ? \Illuminate\Support\Carbon::parse($recebimento['data_pagamento'])->format('d/m/Y') : '—' }}</td>
                        <td>{{ $formasPagamento[$recebimento['forma_pagamento'] ?? ''] ?? 'Não informado' }}</td>
                        <td class="numeric">R$ {{ number_format((float) ($recebimento['valor'] ?? 0), 2, ',', '.') }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="2">Total recebido nesta baixa</td>
                    <td class="numeric">R$ {{ number_format($totalRecebidoNestaBaixa, 2, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    <table class="meta" style="margin-top: 8px;">
        <tr>
            <td class="label">Saldo restante</td>
            <td>R$ {{ number_format((float) $saldoRestante, 2, ',', '.') }}</td>
        </tr>
    </table>

    @if (! empty($order->garantia_dias) && ! empty($order->garantia_validade))
        <h2>Garantia</h2>
        <p>{{ (int) $order->garantia_dias }} dias, válida até {{ \Illuminate\Support\Carbon::parse($order->garantia_validade)->format('d/m/Y') }}.</p>
    @endif

    <div class="footer">
        Documento gerado automaticamente na conclusão da OS — não substitui a nota fiscal, quando aplicável.
    </div>
</body>
</html>
