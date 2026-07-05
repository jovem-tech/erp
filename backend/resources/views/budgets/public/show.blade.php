@php
    $budget = is_array($budget ?? null) ? $budget : [];
    $flashSuccess = session('success');
    $flashWarning = session('warning');
    $formatMoney = static fn (float $value): string => 'R$ ' . number_format($value, 2, ',', '.');
    $statusClass = match ((string) ($budget['status'] ?? '')) {
        'aprovado', 'pendente_abertura_os' => 'status-approved',
        'rejeitado' => 'status-rejected',
        default => 'status-pending',
    };
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orçamento {{ $budget['numero'] ?? '' }}</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #eef4ff;
            --card: rgba(255,255,255,.96);
            --border: rgba(56, 104, 176, 0.14);
            --text: #12233f;
            --muted: #5c6f8d;
            --primary: #3868b0;
            --primary-soft: rgba(56, 104, 176, 0.12);
            --success: #15803d;
            --success-soft: rgba(21, 128, 61, 0.12);
            --danger: #dc2626;
            --danger-soft: rgba(220, 38, 38, 0.12);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
            background:
                radial-gradient(circle at top right, rgba(111, 90, 252, 0.12), transparent 34%),
                linear-gradient(180deg, #f7fbff 0%, var(--bg) 100%);
            color: var(--text);
        }
        .shell {
            width: min(1080px, calc(100% - 32px));
            margin: 24px auto 40px;
        }
        .hero,
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: 0 22px 44px rgba(15, 23, 42, 0.08);
        }
        .hero {
            padding: 24px;
            margin-bottom: 18px;
        }
        .eyebrow {
            margin: 0 0 8px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
        }
        .hero-top {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
        }
        .title {
            margin: 0;
            font-size: clamp(28px, 4vw, 40px);
            line-height: 1.05;
        }
        .subtitle {
            margin: 10px 0 0;
            color: var(--muted);
            line-height: 1.6;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            min-height: 40px;
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .status-pending { background: var(--primary-soft); color: var(--primary); }
        .status-approved { background: var(--success-soft); color: var(--success); }
        .status-rejected { background: var(--danger-soft); color: var(--danger); }
        .flash {
            padding: 14px 16px;
            border-radius: 16px;
            margin-top: 16px;
            font-weight: 600;
        }
        .flash.success { background: var(--success-soft); color: var(--success); }
        .flash.warning { background: rgba(245, 158, 11, 0.14); color: #9a6700; }
        .grid {
            display: grid;
            gap: 18px;
            grid-template-columns: 1.2fr .8fr;
        }
        .card {
            padding: 22px;
        }
        .meta-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            margin-top: 18px;
        }
        .meta-item {
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(248, 250, 252, 0.9);
            border: 1px solid rgba(148, 163, 184, 0.16);
        }
        .meta-label {
            display: block;
            color: var(--muted);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .meta-value {
            font-size: 16px;
            font-weight: 700;
            line-height: 1.4;
        }
        .section-title {
            margin: 0 0 12px;
            font-size: 20px;
        }
        .items {
            display: grid;
            gap: 12px;
        }
        .item {
            border: 1px solid rgba(148, 163, 184, 0.16);
            border-radius: 18px;
            padding: 14px 16px;
            background: rgba(255,255,255,.9);
        }
        .item-top,
        .money-row,
        .action-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 12px;
        }
        .item-top strong { font-size: 16px; }
        .item-top span,
        .item-notes,
        .helper {
            color: var(--muted);
            line-height: 1.5;
        }
        .money-row {
            margin-top: 10px;
            font-weight: 700;
        }
        .totals {
            display: grid;
            gap: 12px;
        }
        .total-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.16);
            background: rgba(248, 250, 252, 0.92);
        }
        .total-box strong { font-size: 16px; }
        .total-box.grand {
            background: linear-gradient(135deg, rgba(111, 90, 252, 0.14), rgba(56, 104, 176, 0.12));
            border-color: rgba(111, 90, 252, 0.22);
        }
        .total-box.grand strong:last-child { font-size: 22px; }
        .decision-box {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(148, 163, 184, 0.18);
        }
        textarea {
            width: 100%;
            min-height: 110px;
            padding: 14px 16px;
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.24);
            resize: vertical;
            font: inherit;
            color: var(--text);
            background: #fff;
        }
        .buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 14px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 999px;
            border: 1px solid transparent;
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
            font: inherit;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-danger { background: #fff; color: var(--danger); border-color: rgba(220, 38, 38, 0.28); }
        .btn-secondary { background: #fff; color: var(--primary); border-color: rgba(56, 104, 176, 0.22); }
        .empty {
            padding: 18px;
            border-radius: 16px;
            background: rgba(248, 250, 252, 0.92);
            color: var(--muted);
        }
        @media (max-width: 960px) {
            .grid { grid-template-columns: 1fr; }
            .meta-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="hero">
            <div class="hero-top">
                <div>
                    <p class="eyebrow">{{ $budget['company_name'] ?? 'Sistema ERP' }}</p>
                    <h1 class="title">Orçamento {{ $budget['numero'] ?? '' }}</h1>
                    <p class="subtitle">
                        @if (($budget['titulo'] ?? '') !== '')
                            {{ $budget['titulo'] }} ·
                        @endif
                        Versão {{ $budget['versao'] ?? 1 }}
                        @if (($budget['validade_data'] ?? '') !== '')
                            · válido até {{ $budget['validade_data'] }}
                        @endif
                    </p>
                </div>
                <span class="status-badge {{ $statusClass }}">{{ $budget['status_label'] ?? 'Sem status' }}</span>
            </div>

            @if (is_string($flashSuccess) && $flashSuccess !== '')
                <div class="flash success">{{ $flashSuccess }}</div>
            @endif
            @if (is_string($flashWarning) && $flashWarning !== '')
                <div class="flash warning">{{ $flashWarning }}</div>
            @endif

            <div class="meta-grid">
                <div class="meta-item">
                    <span class="meta-label">Cliente</span>
                    <div class="meta-value">{{ $budget['client_name'] !== '' ? $budget['client_name'] : 'Não informado' }}</div>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Contato</span>
                    <div class="meta-value">{{ $budget['phone'] !== '' ? $budget['phone'] : 'Não informado' }}</div>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Equipamento</span>
                    <div class="meta-value">{{ $budget['equipment_name'] !== '' ? $budget['equipment_name'] : 'Não informado' }}</div>
                </div>
                <div class="meta-item">
                    <span class="meta-label">OS vinculada</span>
                    <div class="meta-value">{{ $budget['order_number'] !== '' ? $budget['order_number'] : 'Sem vínculo' }}</div>
                </div>
            </div>
        </section>

        <section class="grid">
            <article class="card">
                <h2 class="section-title">Itens da proposta</h2>

                <div class="items">
                    @forelse ($budget['items'] ?? [] as $item)
                        <div class="item">
                            <div class="item-top">
                                <strong>{{ $item['descricao'] !== '' ? $item['descricao'] : 'Item sem descrição' }}</strong>
                                <span>{{ ucfirst($item['tipo_item'] ?? 'item') }}</span>
                            </div>
                            <div class="money-row">
                                <span>Qtd: {{ number_format((float) ($item['quantidade'] ?? 0), 2, ',', '.') }}</span>
                                <span>Valor unit.: {{ $formatMoney((float) ($item['valor_unitario'] ?? 0)) }}</span>
                                <span>Total: {{ $formatMoney((float) ($item['total'] ?? 0)) }}</span>
                            </div>
                            @if (($item['observacoes'] ?? '') !== '')
                                <div class="item-notes">Observações: {{ $item['observacoes'] }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="empty">Nenhum item disponível nesta proposta.</div>
                    @endforelse
                </div>
            </article>

            <aside class="card">
                <h2 class="section-title">Resultado final</h2>

                <div class="totals">
                    <div class="total-box">
                        <strong>Subtotal</strong>
                        <strong>{{ $formatMoney((float) ($budget['subtotal'] ?? 0)) }}</strong>
                    </div>
                    <div class="total-box">
                        <strong>Desconto</strong>
                        <strong>{{ $formatMoney((float) ($budget['desconto'] ?? 0)) }}</strong>
                    </div>
                    <div class="total-box">
                        <strong>Acréscimo</strong>
                        <strong>{{ $formatMoney((float) ($budget['acrescimo'] ?? 0)) }}</strong>
                    </div>
                    <div class="total-box grand">
                        <strong>Total final</strong>
                        <strong>{{ $formatMoney((float) ($budget['total'] ?? 0)) }}</strong>
                    </div>
                </div>

                <div class="decision-box">
                    <p class="helper">
                        @if (!empty($budget['expired']))
                            Este link expirou em {{ $budget['token_expira_em'] ?? 'data não informada' }}. Solicite um novo envio ao estabelecimento.
                        @elseif (!empty($budget['can_respond']))
                            Revise a proposta e escolha abaixo se deseja aprovar ou rejeitar este orçamento.
                        @elseif (($budget['status'] ?? '') === 'rejeitado' && ($budget['motivo_rejeicao'] ?? '') !== '')
                            Rejeição registrada: {{ $budget['motivo_rejeicao'] }}
                        @else
                            Esta proposta já possui uma decisão registrada e permanece disponível apenas para consulta.
                        @endif
                    </p>

                    <div class="buttons">
                        <a href="{{ route('budgets.public.pdf', ['token' => request()->route('token')]) }}" class="btn btn-secondary">Baixar PDF</a>
                    </div>

                    @if (!empty($budget['can_respond']))
                        <form method="post" action="{{ route('budgets.public.approve', ['token' => request()->route('token')]) }}" class="action-row" style="margin-top: 16px;">
                            @csrf
                            <input type="hidden" name="resposta_cliente" value="Aprovado pelo cliente.">
                            <button type="submit" class="btn btn-primary">Aprovar proposta</button>
                        </form>

                        <form method="post" action="{{ route('budgets.public.reject', ['token' => request()->route('token')]) }}" style="margin-top: 16px;">
                            @csrf
                            <label class="meta-label" for="motivoRejeicao">Se desejar, informe o motivo da rejeição</label>
                            <textarea id="motivoRejeicao" name="motivo_rejeicao" placeholder="Ex.: vou avaliar outra alternativa, preciso rever o valor, não autorizo neste momento..."></textarea>
                            @error('motivo_rejeicao')
                                <p class="helper" style="color: var(--danger); margin-bottom: 0;">{{ $message }}</p>
                            @enderror
                            <div class="buttons">
                                <button type="submit" class="btn btn-danger">Rejeitar proposta</button>
                            </div>
                        </form>
                    @endif
                </div>
            </aside>
        </section>
    </main>
</body>
</html>
