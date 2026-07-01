@php
    $order = is_array($order ?? null) ? $order : [];
    $client = is_array($order['cliente'] ?? null) ? $order['cliente'] : [];
    $equipment = is_array($order['equipamento'] ?? null) ? $order['equipamento'] : [];
    $technician = is_array($order['tecnico'] ?? null) ? $order['tecnico'] : [];
    $statusName = (string) ($order['status_nome'] ?? 'Sem status');
    $statusColor = (string) ($order['status_cor'] ?? '#64748b');
    $fullUrl = route('orders.show', (int) ($order['id'] ?? 0));
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pré-visualização da OS | Sistema ERP</title>
    <link href="{{ asset('assets/fonts/plus-jakarta-sans/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/bootstrap-icons/bootstrap-icons.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/desktop.css') }}" rel="stylesheet">
</head>
<body class="desktop-body dashboard-preview-body">
    <main class="dashboard-preview-shell">
        <section class="dashboard-preview-hero">
            <div>
                <span class="desktop-eyebrow">Ordem de serviço</span>
                <h1>{{ $order['numero_os'] !== '' ? $order['numero_os'] : '#' . (int) ($order['id'] ?? 0) }}</h1>
                <p>{{ $order['cliente_nome'] !== '' ? $order['cliente_nome'] : 'Cliente não informado' }}</p>
            </div>

            <div class="d-flex flex-wrap gap-2 justify-content-end">
                <a href="{{ $fullUrl }}" target="_blank" rel="noreferrer" class="btn btn-primary">
                    <i class="bi bi-box-arrow-up-right me-1"></i>
                    Abrir página cheia
                </a>
            </div>
        </section>

        <section class="dashboard-preview-grid">
            <article class="dashboard-preview-card">
                <span class="summary-card-eyebrow">Status</span>
                <div class="mt-2">
                    @include('layouts.partials.status-pill', [
                        'label' => $statusName !== '' ? $statusName : 'Sem status',
                        'color' => $statusColor,
                    ])
                </div>
                <div class="summary-card-meta mt-3">
                    {{ $order['estado_fluxo'] !== '' ? ucfirst(str_replace('_', ' ', $order['estado_fluxo'])) : 'Fluxo não definido' }}
                </div>
            </article>

            <article class="dashboard-preview-card">
                <span class="summary-card-eyebrow">Equipamento</span>
                <div class="summary-card-value">{{ $order['equipamento_resumo_tecnico'] !== '' ? $order['equipamento_resumo_tecnico'] : 'Sem resumo técnico' }}</div>
                <div class="summary-card-meta">{{ $equipment['numero_serie'] ?? 'Série não informada' }}</div>
            </article>

            <article class="dashboard-preview-card">
                <span class="summary-card-eyebrow">Técnico</span>
                <div class="summary-card-value">{{ $technician['nome'] ?? 'Não atribuído' }}</div>
                <div class="summary-card-meta">{{ $technician['email'] ?? ($technician['perfil'] ?? 'Sem perfil') }}</div>
            </article>
        </section>

        <section class="dashboard-preview-grid mt-3">
            <article class="dashboard-preview-card">
                <span class="summary-card-eyebrow">Relato do cliente</span>
                <p class="dashboard-preview-text">{{ $order['relato_cliente'] !== '' ? $order['relato_cliente'] : 'Não informado' }}</p>
            </article>

            <article class="dashboard-preview-card">
                <span class="summary-card-eyebrow">Diagnóstico técnico</span>
                <p class="dashboard-preview-text">{{ $order['diagnostico_tecnico'] !== '' ? $order['diagnostico_tecnico'] : 'Não informado' }}</p>
            </article>

            <article class="dashboard-preview-card">
                <span class="summary-card-eyebrow">Solução aplicada</span>
                <p class="dashboard-preview-text">{{ $order['solucao_aplicada'] !== '' ? $order['solucao_aplicada'] : 'Não informada' }}</p>
            </article>
        </section>

        <section class="dashboard-preview-grid mt-3">
            <article class="dashboard-preview-card">
                <span class="summary-card-eyebrow">Cliente</span>
                <div class="summary-card-value">{{ $client['nome_razao'] ?? ($order['cliente_nome'] ?? 'Não informado') }}</div>
                <div class="summary-card-meta">{{ $client['telefone1'] ?? $client['email'] ?? 'Contato não informado' }}</div>
            </article>

            <article class="dashboard-preview-card">
                <span class="summary-card-eyebrow">Datas</span>
                <div class="dashboard-preview-dates">
                    <span><strong>Abertura:</strong> {{ $order['data_abertura'] !== '' ? $order['data_abertura'] : 'Não informada' }}</span>
                    <span><strong>Previsão:</strong> {{ $order['data_previsao'] !== '' ? $order['data_previsao'] : 'Não informada' }}</span>
                    <span><strong>Entrega:</strong> {{ $order['data_entrega'] !== '' ? $order['data_entrega'] : 'Não informada' }}</span>
                </div>
            </article>

            <article class="dashboard-preview-card">
                <span class="summary-card-eyebrow">Valores</span>
                <div class="dashboard-preview-dates">
                    <span><strong>Valor final:</strong> {{ isset($order['valor_final']) ? 'R$ ' . number_format((float) $order['valor_final'], 2, ',', '.') : 'Não calculado' }}</span>
                    <span><strong>Garantia:</strong> {{ ($order['garantia_dias'] ?? 0) > 0 ? $order['garantia_dias'] . ' dias' : 'Não definida' }}</span>
                </div>
            </article>
        </section>
    </main>
</body>
</html>
