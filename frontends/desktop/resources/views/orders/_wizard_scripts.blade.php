@php
    $isEditing = (int) data_get($order ?? [], 'id', 0) > 0;
    $existingPhotosCount = $isEditing ? count((array) data_get($order, 'fotos', [])) : 0;
    $canCreateClient = \App\Support\DesktopSession::can('clientes', 'criar');
@endphp
<script>
    window.__DESKTOP_ORDER_CREATE = {!! json_encode([
        'quickClientStoreUrl' => route('clients.quick.store'),
        'clientSelectId' => 'clienteId',
        'clientSearchUrl' => route('orders.clients.search'),
        'equipmentSelectId' => 'equipamentoId',
        'equipmentSearchUrl' => route('orders.equipments.search'),
        'reportedDefectsSearchUrl' => route('orders.reported-defects.search'),
        'technicianSelectId' => 'tecnicoId',
        'photosInputId' => 'orderPhotos',
        'photosPickButtonSelector' => '[data-order-create-photos-pick]',
        'photosClearButtonSelector' => '[data-order-create-photos-clear]',
        'photosPreviewSelector' => '[data-order-create-photos-preview]',
        'mainPhotoSelector' => '[data-order-create-main-photo]',
        'mainPhotoPlaceholderSelector' => '[data-order-create-main-photo-placeholder]',
        'summarySelectors' => [
            'status' => '[data-order-create-summary-status]',
            'client' => '[data-order-create-summary-client]',
            'equipment' => '[data-order-create-summary-equipment]',
            'technician' => '[data-order-create-summary-technician]',
            'priority' => '[data-order-create-summary-priority]',
            'previsao' => '[data-order-create-summary-previsao]',
            'relato' => '[data-order-create-summary-relato]',
            'photos' => '[data-order-create-summary-photos]',
        ],
        'maxPhotos' => 4,
        'lockStatus' => $isEditing,
        'existingPhotosCount' => $existingPhotosCount,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
</script>
<script src="{{ asset('assets/js/orders-create.js') }}?v={{ filemtime(public_path('assets/js/orders-create.js')) }}"></script>
@if ($canCreateClient)
    <script src="{{ asset('assets/js/clients-form.js') }}?v={{ filemtime(public_path('assets/js/clients-form.js')) }}"></script>
@endif
