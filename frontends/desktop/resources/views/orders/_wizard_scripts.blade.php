@php
    $isEditing = (int) data_get($order ?? [], 'id', 0) > 0;
    $existingPhotosCount = $isEditing ? count((array) data_get($order, 'fotos', [])) : 0;
    $canCreateClient = \App\Support\DesktopSession::can('clientes', 'criar');
@endphp
<script src="{{ asset('assets/libs/cropperjs/cropper.min.js') }}"></script>
<script>
    window.__DESKTOP_ORDER_CREATE = {!! json_encode([
        'quickClientStoreUrl' => route('clients.quick.store'),
        'clientSelectId' => 'clienteId',
        'clientSearchUrl' => route('orders.clients.search'),
        'equipmentSelectId' => 'equipamentoId',
        'equipmentSearchUrl' => route('orders.equipments.search'),
        'reportedDefectsSearchUrl' => route('orders.reported-defects.search'),
        'entryChecklistModelUrlTemplate' => route('orders.entry-checklist.model', ['tipoEquipamento' => '__TIPO_EQUIPAMENTO__']),
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
            'checklist' => '[data-order-create-summary-checklist]',
        ],
        'maxPhotos' => 4,
        'maxPhotoUploadBytes' => 2 * 1024 * 1024,
        'maxPhotoSourceBytes' => 20 * 1024 * 1024,
        'maxPhotoSourcePixels' => 32000000,
        'lockStatus' => $isEditing,
        'existingPhotosCount' => $existingPhotosCount,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
</script>
<script src="{{ asset('assets/js/orders-create.js') }}?v={{ filemtime(public_path('assets/js/orders-create.js')) }}"></script>
@if ($canCreateClient)
    <script src="{{ asset('assets/js/clients-form.js') }}?v={{ filemtime(public_path('assets/js/clients-form.js')) }}"></script>
@endif

@if ($isEditing)
    {{-- Dropdown "Mais ações" + botão inline "Alterar status" (acima) reaproveitam
         os mesmos modais/JS de orders/show.blade.php --}}
    <script>
        window.__DESKTOP_STATUS_MODAL = {
            statusContextUrlTemplate: '{{ route('orders.status.context', ['order' => '__ORDER__']) }}',
            statusUpdateUrlTemplate: '{{ route('orders.status.update', ['order' => '__ORDER__']) }}',
            proceduresUrlTemplate: '{{ route('orders.procedures.store', ['order' => '__ORDER__']) }}',
            mapDataUrlTemplate: '{{ route('orders.map.data', ['order' => '__ORDER__']) }}',
            closureUrlTemplate: '{{ route('orders.closure.show', ['order' => '__ORDER__']) }}',
            csrfToken: '{{ csrf_token() }}',
        };
        window.__DESKTOP_CANCEL_CLOSURE_MODAL = {
            cancelUrlTemplate: '{{ route('orders.closure.cancel', ['order' => '__ORDER__']) }}',
            csrfToken: '{{ csrf_token() }}',
        };
    </script>
    {{-- orders-map.js registra window.DesktopOsMap.create(), usado pela aba
         "Mapa de status" do modal de alteração de status (_status_modal). Sem
         ele o SVG do mapa fica estático: sem decoração, sem zoom/pan/clique. --}}
    <script src="{{ asset('assets/js/orders-map.js') }}?v={{ filemtime(public_path('assets/js/orders-map.js')) }}"></script>
    <script src="{{ asset('assets/js/orders-status-modal.js') }}?v={{ filemtime(public_path('assets/js/orders-status-modal.js')) }}"></script>
    <script src="{{ asset('assets/js/orders-cancel-closure-modal.js') }}"></script>
@endif
