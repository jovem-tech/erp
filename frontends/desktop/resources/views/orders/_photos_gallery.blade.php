{{-- Galeria de fotos da OS agrupada pelo tipo real (os_fotos.tipo). Serve o
     detalhe da OS e a resposta do upload direto (specs/048), que troca só este
     trecho sem recarregar a página — um template só para as miniaturas.
     Espera: $orderId, $photos; opcional: $newPhotoIds (destaque das recém-enviadas). --}}
@php
    $galleryPhotos = array_values(array_filter((array) ($photos ?? []), 'is_array'));
    $galleryNewIds = array_map('intval', (array) ($newPhotoIds ?? []));
    $galleryViewerGroup = 'order-' . (int) $orderId . '-photos';
    $galleryGroups = [];
    foreach (\App\Support\OrderPhotoTypes::LABELS as $galleryTipo => $galleryLabel) {
        $galleryItems = array_values(array_filter(
            $galleryPhotos,
            static fn (array $photo): bool => ($photo['tipo'] ?? '') === $galleryTipo
        ));
        if ($galleryItems !== []) {
            $galleryGroups[$galleryLabel] = $galleryItems;
        }
    }
@endphp
@foreach ($galleryGroups as $groupLabel => $groupPhotos)
    <div class="os-panel-block">
        <h4 class="os-panel-title">{{ $groupLabel }} <span class="os-count">{{ count($groupPhotos) }}</span></h4>
        <div class="os-photo-grid">
            @foreach ($groupPhotos as $photo)
                @php
                    $photoId = (int) ($photo['id'] ?? 0);
                    $photoUrl = route('orders.photos.show', [(int) $orderId, $photoId]);
                    $photoLabel = $photo['tipo_label'] ?? 'Foto';
                @endphp
                <a href="{{ $photoUrl }}"
                    @class(['os-photo-thumb', 'is-new' => in_array($photoId, $galleryNewIds, true)])
                    target="_blank"
                    rel="noreferrer"
                    title="{{ $photoLabel }}"
                    data-photo-viewer-trigger
                    data-photo-viewer-group="{{ $galleryViewerGroup }}"
                    data-photo-viewer-title="{{ $photoLabel }}">
                    <img src="{{ $photoUrl }}" alt="{{ $photoLabel }}" loading="lazy">
                </a>
            @endforeach
        </div>
    </div>
@endforeach
