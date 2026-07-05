@extends('layouts.app')

@php
    $budget = is_array($budget ?? null) ? $budget : [];
    $form = is_array($form ?? null) ? $form : [];
    $clients = $form['clients'] ?? [];
    $orders = $form['orders'] ?? [];
    $selectedClientId = (int) old('cliente_id', $form['selected_client_id'] ?? ($budget['cliente']['id'] ?? $budget['cliente_id'] ?? 0));
    $selectedOrderId = (int) old('os_id', $form['selected_order_id'] ?? ($budget['os']['id'] ?? $budget['os_id'] ?? 0));
    $lockedOrderContext = [
        'locked' => $selectedOrderId > 0 && $selectedClientId > 0,
        'order_id' => $selectedOrderId,
        'client_id' => $selectedClientId,
        'order_number' => '',
        'client_name' => '',
    ];

    if ($lockedOrderContext['locked']) {
        foreach ($orders as $orderOption) {
            if ((int) ($orderOption['id'] ?? 0) === $selectedOrderId) {
                $lockedOrderContext['order_number'] = trim((string) ($orderOption['numero_os'] ?? ''));
                $lockedOrderContext['client_name'] = trim((string) ($orderOption['cliente_nome'] ?? ''));
                break;
            }
        }

        if ($lockedOrderContext['client_name'] === '') {
            foreach ($clients as $clientOption) {
                if ((int) ($clientOption['id'] ?? 0) === $selectedClientId) {
                    $lockedOrderContext['client_name'] = trim((string) ($clientOption['nome_razao'] ?? ''));
                    break;
                }
            }
        }
    }

    $lockedOrderLabel = $lockedOrderContext['order_number'] !== ''
        ? $lockedOrderContext['order_number']
        : ($lockedOrderContext['order_id'] > 0 ? 'OS #' . $lockedOrderContext['order_id'] : '');

    $headerContextLine = trim(implode(' · ', array_filter([
        $lockedOrderLabel,
        $lockedOrderContext['client_name'],
    ])));
@endphp

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Comercial</p>
            <h2 class="surface-title fs-3 mb-2">Novo orçamento</h2>
            @if ($headerContextLine !== '')
                <p class="surface-subtitle mb-0">{{ $headerContextLine }}</p>
            @endif
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('orcamentos.help') }}" class="btn btn-outline-info">
                <i class="bi bi-question-circle me-2"></i>
                Ajuda
            </a>
            @if ($lockedOrderContext['locked'])
                <a href="{{ route('orcamentos.create') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-plus me-2"></i>
                    Novo orçamento
                </a>
            @endif
            <a href="{{ route('orcamentos.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
        </div>
    </div>

    @include('orcamentos.form', [
        'budget' => $budget ?? [],
        'form' => $form ?? [],
        'quickCatalogs' => $quickCatalogs ?? [],
        'formAction' => route('orcamentos.store'),
        'formMethod' => 'POST',
        'formTitle' => '',
        'submitLabel' => 'Salvar orçamento',
        'cancelUrl' => route('orcamentos.index'),
        'isEditMode' => false,
        'lockedOrderContext' => $lockedOrderContext,
    ])
@endsection

@section('scripts')
    <script>
        window.__DESKTOP_ORCAMENTO_FORM = {!! json_encode([
            'draftKey' => 'orcamentos:create',
            'isEditMode' => false,
            'budgetId' => 0,
            'quickCatalogs' => $quickCatalogs ?? [],
            'catalogs' => [
                'services' => collect($form['services'] ?? [])->map(static function (array $service): array {
                    return [
                        'id' => (int) ($service['id'] ?? 0),
                        'label' => trim((string) ($service['nome'] ?? 'Serviço')),
                        'description' => trim((string) ($service['descricao'] ?? '')),
                        'price' => (float) ($service['valor'] ?? 0),
                    ];
                })->values(),
                'parts' => collect($form['parts'] ?? [])->map(static function (array $part): array {
                    return [
                        'id' => (int) ($part['id'] ?? 0),
                        'label' => trim((string) (($part['codigo'] ?? '') !== '' ? $part['codigo'] . ' - ' . ($part['nome'] ?? 'Peça') : ($part['nome'] ?? 'Peça'))),
                        'description' => trim((string) ($part['nome'] ?? '')),
                        'price' => (float) ($part['preco_venda'] ?? 0),
                    ];
                })->values(),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
    </script>
    <script src="{{ asset('assets/js/orcamentos-form.js') }}?v={{ filemtime(public_path('assets/js/orcamentos-form.js')) }}"></script>
@endsection
