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

        <div class="dropdown os-actions-dropdown align-self-start">
            <button type="button"
                class="btn btn-outline-light dropdown-toggle os-actions-toggle"
                data-bs-toggle="dropdown"
                aria-expanded="false">
                Mais ações
            </button>

            <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                <a href="{{ route('orcamentos.help') }}" class="dropdown-item">
                    <i class="bi bi-question-circle me-2"></i>Ajuda
                </a>

                @if ($lockedOrderContext['locked'])
                    <a href="{{ route('orcamentos.create') }}" class="dropdown-item">
                        <i class="bi bi-file-earmark-plus me-2"></i>Novo orçamento
                    </a>

                    <a href="{{ route('orders.show', $lockedOrderContext['order_id']) }}" class="dropdown-item">
                        <i class="bi bi-eye me-2"></i>Ver OS
                    </a>

                    <a href="{{ route('orders.documents.center', $lockedOrderContext['order_id']) }}" class="dropdown-item">
                        <i class="bi bi-folder-symlink me-2"></i>Documentos da OS
                    </a>
                @endif

                <a href="{{ route('orcamentos.index') }}" class="dropdown-item">
                    <i class="bi bi-arrow-left me-2"></i>Voltar
                </a>
            </div>
        </div>
    </div>

    @include('orcamentos.form', [
        'budget' => $budget ?? [],
        'form' => $form ?? [],
        'quickCatalogs' => $quickCatalogs ?? [],
        'formAction' => route('orcamentos.store'),
        'formMethod' => 'POST',
        'formTitle' => '',
        'submitLabel' => 'Criar orçamento',
        'cancelUrl' => route('orcamentos.index'),
        'isEditMode' => false,
        'lockedOrderContext' => $lockedOrderContext,
    ])
@endsection

@section('scripts')
    <script>
        window.__DESKTOP_ORCAMENTO_FORM = {!! \Illuminate\Support\Js::from([
            'draftKey' => 'orcamentos:create',
            'isEditMode' => false,
            'budgetId' => 0,
            'clientSearchUrl' => route('orcamentos.clients.search'),
            'clientContextUrl' => route('orcamentos.client_context'),
            'quickClientStoreUrl' => ($canQuickClient ?? false) ? route('clients.quick.store') : '',
            'quickCatalogs' => $quickCatalogs ?? [],
            // Sugestão de preço de venda no cadastro rápido de peça — mesmo
            // motor/endpoint da tela de Estoque (specs/037).
            'suggestPartPriceUrl' => route('estoque.suggest-price'),
            'suggestPartCodeUrl' => route('estoque.suggest-code'),
            // Catálogo tipo/marca/modelo (EquipmentType/Brand/Model), o mesmo
            // usado no cadastro de equipamento — para o Select2 de marca/modelo
            // do "equipamento eventual" e para cadastrar marca/modelo novos
            // direto no catálogo quando o desejado não existir.
            'equipmentTypes' => collect($equipmentCatalog['types'] ?? [])->map(static function (array $type): array {
                return ['id' => (int) ($type['id'] ?? 0), 'nome' => trim((string) ($type['nome'] ?? ''))];
            })->values(),
            'equipmentBrands' => collect($equipmentCatalog['brands'] ?? [])->map(static function (array $brand): array {
                return ['id' => (int) ($brand['id'] ?? 0), 'nome' => trim((string) ($brand['nome'] ?? ''))];
            })->values(),
            'equipmentModels' => collect($equipmentCatalog['models'] ?? [])->map(static function (array $model): array {
                return [
                    'id' => (int) ($model['id'] ?? 0),
                    'nome' => trim((string) ($model['nome'] ?? '')),
                    'marca_id' => (int) ($model['marca_id'] ?? 0),
                ];
            })->values(),
            'equipmentCatalogRelations' => collect($equipmentCatalog['catalog_relations'] ?? [])->map(static function (array $relation): array {
                return [
                    'tipo_id' => (int) ($relation['tipo_id'] ?? 0),
                    'marca_id' => (int) ($relation['marca_id'] ?? 0),
                    'modelo_id' => (int) ($relation['modelo_id'] ?? 0),
                ];
            })->values(),
            'equipmentBrandQuickStoreUrl' => ($canCreateEquipment ?? false) ? route('equipments.brands.quick.store') : '',
            'equipmentModelQuickStoreUrl' => ($canCreateEquipment ?? false) ? route('equipments.models.quick.store') : '',
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
        ]) !!};
    </script>
    {{-- Cache-buster combina mtime + tamanho: mtime sozinho tem granularidade de
         1s e pode colidir quando o arquivo é reescrito duas vezes no mesmo
         segundo, fazendo o navegador reusar o JS antigo em cache. --}}
    <script src="{{ asset('assets/js/orcamentos-form.js') }}?v={{ filemtime(public_path('assets/js/orcamentos-form.js')) }}-{{ filesize(public_path('assets/js/orcamentos-form.js')) }}"></script>
    {{-- Máscaras (telefone, CPF/CNPJ) e autopreenchimento de CEP do modal de
         cadastro rápido de cliente. Os seletores são guardados por id: sem o
         modal na página, o arquivo não faz nada. --}}
    @if ($canQuickClient ?? false)
        <script src="{{ asset('assets/js/clients-form.js') }}?v={{ filemtime(public_path('assets/js/clients-form.js')) }}"></script>
    @endif
@endsection
