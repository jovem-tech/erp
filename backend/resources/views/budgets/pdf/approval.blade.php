@php
    $clientName = trim((string) ($budget->client?->nome_razao ?? ($budget->cliente_nome_avulso ?? 'Cliente não informado')));
    $equipmentName = trim((string) ($budget->equipment?->resumo_tecnico ?? 'Equipamento não informado'));
    $orderNumber = trim((string) ($budget->order?->numero_os ?? ''));
    $items = $budget->items->sortBy('ordem')->values();
    $formatMoney = static fn (float $value): string => 'R$ ' . number_format($value, 2, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Orçamento {{ $budget->numero ?? '' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; line-height: 1.5; }
        .page { padding: 8px 12px; }
        .header { margin-bottom: 18px; }
        .eyebrow { color: #64748b; font-size: 10px; text-transform: uppercase; letter-spacing: .12em; font-weight: 700; margin-bottom: 4px; }
        .title { font-size: 24px; font-weight: 700; margin: 0 0 6px; }
        .subtitle { color: #475569; margin: 0; }
        .meta-grid { width: 100%; border-collapse: collapse; margin: 16px 0 20px; }
        .meta-grid td { width: 50%; padding: 10px 12px; vertical-align: top; border: 1px solid #dbe4f0; }
        .meta-label { display: block; color: #64748b; font-size: 10px; text-transform: uppercase; letter-spacing: .08em; font-weight: 700; margin-bottom: 6px; }
        .meta-value { font-size: 13px; font-weight: 700; }
        .section-title { font-size: 15px; font-weight: 700; margin: 0 0 10px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        table.items th, table.items td { border: 1px solid #dbe4f0; padding: 8px 10px; }
        table.items th { background: #eff6ff; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; }
        table.items td.numeric { text-align: right; white-space: nowrap; }
        .totals { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .totals td { padding: 8px 10px; border: 1px solid #dbe4f0; }
        .totals .label { width: 70%; background: #f8fafc; font-weight: 700; }
        .totals .value { width: 30%; text-align: right; font-weight: 700; }
        .totals .grand .label,
        .totals .grand .value { background: #e9edff; font-size: 14px; }
        .approval-box { margin-top: 20px; padding: 14px 16px; border: 1px solid #cbd5e1; border-radius: 12px; background: #f8fafc; }
        .approval-link { color: #1d4ed8; font-size: 11px; word-break: break-all; }
        .footer { margin-top: 18px; color: #64748b; font-size: 10px; }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <div class="eyebrow">{{ $companyName }}</div>
            <h1 class="title">Orçamento {{ $budget->numero ?? '' }}</h1>
            <p class="subtitle">
                Versão {{ max(1, (int) ($budget->versao ?? 1)) }}
                @if ($budget->titulo)
                    · {{ $budget->titulo }}
                @endif
            </p>
        </div>

        <table class="meta-grid">
            <tr>
                <td>
                    <span class="meta-label">Cliente</span>
                    <span class="meta-value">{{ $clientName }}</span>
                </td>
                <td>
                    <span class="meta-label">Contato</span>
                    <span class="meta-value">{{ trim((string) ($budget->telefone_contato ?? ($budget->client?->telefone1 ?? 'Não informado'))) }}</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="meta-label">Equipamento</span>
                    <span class="meta-value">{{ $equipmentName }}</span>
                </td>
                <td>
                    <span class="meta-label">OS vinculada</span>
                    <span class="meta-value">{{ $orderNumber !== '' ? $orderNumber : 'Sem vínculo' }}</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="meta-label">Validade</span>
                    <span class="meta-value">{{ $budget->validade_data ? $budget->validade_data->format('d/m/Y') : 'Não informada' }}</span>
                </td>
                <td>
                    <span class="meta-label">Gerado em</span>
                    <span class="meta-value">{{ $generatedAt->format('d/m/Y H:i') }}</span>
                </td>
            </tr>
        </table>

        <h2 class="section-title">Itens do orçamento</h2>
        <table class="items">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qtd</th>
                    <th>Valor unit.</th>
                    <th>Desconto</th>
                    <th>Acréscimo</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td>
                            <strong>{{ $item->descricao }}</strong>
                            @if ($item->observacoes)
                                <div style="margin-top: 4px; color: #64748b; font-size: 10px;">{{ $item->observacoes }}</div>
                            @endif
                        </td>
                        <td class="numeric">{{ number_format((float) ($item->quantidade ?? 0), 2, ',', '.') }}</td>
                        <td class="numeric">{{ $formatMoney((float) ($item->valor_unitario ?? 0)) }}</td>
                        <td class="numeric">{{ $formatMoney((float) ($item->desconto ?? 0)) }}</td>
                        <td class="numeric">{{ $formatMoney((float) ($item->acrescimo ?? 0)) }}</td>
                        <td class="numeric">{{ $formatMoney((float) ($item->total ?? 0)) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">Nenhum item registrado.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <table class="totals">
            <tr>
                <td class="label">Subtotal</td>
                <td class="value">{{ $formatMoney((float) ($budget->subtotal ?? 0)) }}</td>
            </tr>
            <tr>
                <td class="label">Desconto</td>
                <td class="value">{{ $formatMoney((float) ($budget->desconto ?? 0)) }}</td>
            </tr>
            <tr>
                <td class="label">Acréscimo</td>
                <td class="value">{{ $formatMoney((float) ($budget->acrescimo ?? 0)) }}</td>
            </tr>
            <tr class="grand">
                <td class="label">Total final</td>
                <td class="value">{{ $formatMoney((float) ($budget->total ?? 0)) }}</td>
            </tr>
        </table>

        <div class="approval-box">
            <div class="eyebrow">Aprovação do cliente</div>
            <strong>Use este link para aprovar ou rejeitar a proposta:</strong>
            <div class="approval-link">{{ $approvalLink }}</div>
        </div>

        <div class="footer">
            Documento emitido automaticamente pelo Sistema ERP.
        </div>
    </div>
</body>
</html>
