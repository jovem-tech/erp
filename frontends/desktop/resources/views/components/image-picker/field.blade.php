{{-- Campo de imagem para formulário — o padrão de inserção de imagem
     (specs/049): Câmera, Computador/galeria, Colar, arrastar e recorte
     opcional. As imagens escolhidas viram o conteúdo do <input type="file"
     name="{{ $name }}"> abaixo, então a rota que recebe o form não muda.

     Uso: <x-image-picker.field name="photo_file" accept="image" /> dentro do
     <form enctype="multipart/form-data">. `accept`: photo | image | document
     (App\Support\ImagePicker). `input-attributes`: atributos extras do input
     que vai no form (ex.: um data-* que outro script já escuta). --}}
@props([
    'name',
    'accept' => 'image',
    'multiple' => false,
    'max' => null,
    'maxBytes' => null,
    'keepPng' => false,
    'cropRatio' => null,
    'paste' => 'focus',
    'camera' => true,
    'pickLabel' => 'Computador / galeria',
    'title' => null,
    'help' => null,
    'inputAttributes' => [],
])
@php
    $pickerMaxBytes = \App\Support\ImagePicker::maxBytes($accept, $maxBytes !== null ? (int) $maxBytes : null);
    $pickerMax = $multiple ? max(1, (int) ($max ?? 4)) : 1;
    $pickerHelp = $help ?? (\App\Support\ImagePicker::formats($accept) . ', até ' . \App\Support\ImagePicker::humanSize($pickerMaxBytes) . ($multiple ? ' cada' : '') . '. Recortar é opcional.');
    $pickerTitle = $title ?? ($multiple ? 'Adicionar imagens' : 'Escolher imagem');
@endphp
<div {{ $attributes->class(['image-picker-field']) }}
    data-image-picker="field"
    data-image-picker-accept="{{ $accept }}"
    data-image-picker-max="{{ $pickerMax }}"
    data-image-picker-max-bytes="{{ $pickerMaxBytes }}"
    data-image-picker-paste="{{ $paste === 'page' ? 'page' : 'focus' }}"
    @unless ($multiple) data-image-picker-multiple="false" @endunless
    @if ($keepPng) data-image-picker-keep-png="true" @endif
    @if ($cropRatio) data-image-picker-crop-ratio="{{ $cropRatio }}" @endif>
    <x-image-picker.buttons :accept="$accept" :multiple="$multiple" :camera="$camera" :pick-label="$pickLabel" />
    <x-image-picker.dropzone compact :title="$pickerTitle" :pick-label="$pickLabel" />
    <div class="image-picker-list" data-image-picker-list></div>
    <input type="file" name="{{ $name }}" class="d-none" tabindex="-1" aria-hidden="true"
        accept="{{ \App\Support\ImagePicker::accept($accept) }}"
        @if ($multiple) multiple @endif
        {{ new \Illuminate\View\ComponentAttributeBag((array) $inputAttributes) }}
        data-image-picker-input>
    <small class="image-picker-help">{{ $pickerHelp }}</small>
    {{ $slot }}
</div>
