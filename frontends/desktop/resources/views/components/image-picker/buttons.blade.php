{{-- Botões de origem do padrão de inserção de imagem (specs/049): Câmera,
     Computador/galeria e Colar, mais os dois inputs ocultos que eles usam.
     Fica dentro de um elemento ligado pelo ErpImagePicker (data-image-picker
     ou ErpImagePicker.attach). Os inputs NÃO têm `name`: quem vai para o form
     é o <input data-image-picker-input> do campo. --}}
@props([
    'accept' => 'photo',
    'multiple' => true,
    'size' => 'sm',
    'pickLabel' => 'Computador / galeria',
    'camera' => true,
    'paste' => true,
])
@php
    $btnSize = $size !== '' ? 'btn-' . $size : '';
@endphp
<div {{ $attributes->class(['image-picker-actions']) }} role="group" aria-label="Adicionar imagem">
    @if ($camera)
        <button type="button" class="btn btn-primary {{ $btnSize }}" data-image-picker-camera>
            <i class="bi bi-camera me-1"></i>Câmera
        </button>
    @endif
    <button type="button" class="btn btn-soft {{ $btnSize }}" data-image-picker-pick>
        <i class="bi bi-folder2-open me-1"></i>{{ $pickLabel }}
    </button>
    @if ($paste)
        <button type="button" class="btn btn-soft {{ $btnSize }}" data-image-picker-paste>
            <i class="bi bi-clipboard-plus me-1"></i>Colar
        </button>
    @endif
</div>
<input type="file" class="d-none" tabindex="-1" aria-hidden="true"
    accept="{{ \App\Support\ImagePicker::accept($accept) }}"
    @if ($multiple) multiple @endif
    data-image-picker-file>
<input type="file" class="d-none" tabindex="-1" aria-hidden="true"
    accept="image/*" capture="environment"
    data-image-picker-capture>
