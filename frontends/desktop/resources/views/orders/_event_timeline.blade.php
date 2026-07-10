{{-- Timeline unificada e categorizada de eventos da OS (tabela os_eventos).
     Backend: OrderWorkflowService::mapEventCollection() — cap de 200 eventos.
     Filtragem por categoria e client-side (chips no cabeçalho), sem reload. --}}
@php
    $eventCategories = [
        'status' => ['label' => 'Status', 'color' => '#6f5afc', 'icon' => 'bi-arrow-repeat'],
        'orcamento' => ['label' => 'Orçamento', 'color' => '#3b82f6', 'icon' => 'bi-file-earmark-text'],
        'financeiro' => ['label' => 'Financeiro', 'color' => '#22c55e', 'icon' => 'bi-cash-coin'],
        'documento' => ['label' => 'Documentos', 'color' => '#f59e0b', 'icon' => 'bi-file-earmark-pdf'],
        'mensagem' => ['label' => 'Mensagens', 'color' => '#14b8a6', 'icon' => 'bi-whatsapp'],
        'registro' => ['label' => 'Registros', 'color' => '#94a3b8', 'icon' => 'bi-journal-text'],
    ];
    $eventOrigemLabels = [
        'sistema' => 'Sistema',
        'cliente' => 'Cliente',
        'automacao' => 'Automação',
    ];
    $eventos = is_array($order['eventos'] ?? null) ? $order['eventos'] : [];
    $eventCounts = collect($eventos)->countBy('categoria');
@endphp

<section class="surface-card mt-4 os-history-section" data-event-timeline>
    <div class="surface-card-header">
        <div>
            <h2 class="surface-title fs-6"><i class="bi bi-clock-history me-1"></i>Histórico da OS</h2>
            <p class="surface-subtitle">Toda movimentação da OS, categorizada e auditável — status, orçamento, financeiro, documentos, mensagens e registros.</p>
        </div>
    </div>

    @if ($eventos !== [])
        <div class="event-filters mb-3" data-event-filters>
            <button type="button" class="event-chip is-active" data-event-filter="all">
                Todos <span class="event-chip-count">{{ count($eventos) }}</span>
            </button>
            @foreach ($eventCategories as $categoria => $meta)
                @php $count = (int) ($eventCounts[$categoria] ?? 0); @endphp
                <button type="button"
                    class="event-chip {{ $count === 0 ? 'is-empty' : '' }}"
                    data-event-filter="{{ $categoria }}"
                    style="--event-color: {{ $meta['color'] }}">
                    <span class="event-chip-dot"></span>{{ $meta['label'] }} <span class="event-chip-count">{{ $count }}</span>
                </button>
            @endforeach
        </div>

        <div class="timeline" data-event-list>
            @foreach ($eventos as $evento)
                @php
                    $categoria = (string) ($evento['categoria'] ?? 'registro');
                    $meta = $eventCategories[$categoria] ?? $eventCategories['registro'];
                    $usuarioNome = trim((string) ($evento['usuario']['nome'] ?? ''));
                    $origem = (string) ($evento['origem'] ?? 'sistema');
                    $responsavel = $usuarioNome !== '' ? $usuarioNome : ($eventOrigemLabels[$origem] ?? 'Sistema');
                    $dados = is_array($evento['dados'] ?? null) ? $evento['dados'] : [];
                    $dadosEscalares = array_filter($dados, static fn ($valor): bool => is_scalar($valor) && trim((string) $valor) !== '');
                @endphp
                <article class="timeline-item"
                    data-event-category="{{ $categoria }}"
                    style="--event-color: {{ $meta['color'] }}">
                    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center">
                        <span class="event-category-pill"><i class="bi {{ $meta['icon'] }}"></i>{{ $meta['label'] }}</span>
                        <small>{{ ($evento['created_at'] ?? '') !== '' ? $evento['created_at'] : 'Data não informada' }}</small>
                    </div>
                    <strong class="d-block mt-2">{{ $evento['titulo'] ?? 'Evento' }}</strong>
                    @if (trim((string) ($evento['descricao'] ?? '')) !== '')
                        <p class="mb-2 mt-1">{{ $evento['descricao'] }}</p>
                    @endif
                    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center mt-1">
                        <small>Responsável: {{ $responsavel }}</small>
                        @if ($dadosEscalares !== [])
                            <details class="event-data">
                                <summary>Detalhes</summary>
                                <dl>
                                    @foreach ($dadosEscalares as $chave => $valor)
                                        <dt>{{ str_replace('_', ' ', $chave) }}</dt>
                                        <dd>{{ is_bool($valor) ? ($valor ? 'sim' : 'não') : $valor }}</dd>
                                    @endforeach
                                </dl>
                            </details>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>

        <div class="d-none" data-event-empty>
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-funnel',
                'title' => 'Nenhum evento nesta categoria',
                'message' => 'Nenhuma movimentação registrada para a categoria selecionada.',
            ])
        </div>
    @else
        @include('layouts.partials.empty-state', [
            'icon' => 'bi-clock-history',
            'title' => 'Sem histórico registrado',
            'message' => 'Nenhuma movimentação foi registrada para esta ordem de serviço.',
        ])
    @endif
</section>
