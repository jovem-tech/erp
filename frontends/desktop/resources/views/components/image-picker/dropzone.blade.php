{{-- Área de arrastar/colar/clicar do padrão de inserção de imagem (specs/049).
     `empty`: versão grande, para quando ainda não há imagem nenhuma. --}}
@props([
    'empty' => false,
    'emptyTitle' => 'Nenhuma imagem ainda',
    'title' => 'Adicionar imagem',
    'moreTitle' => null,
    'pickLabel' => 'Computador / galeria',
    'compact' => false,
])
@php
    $hintId = 'imagePickerHint' . \Illuminate\Support\Str::random(8);
@endphp
<div {{ $attributes->class(['image-picker-dropzone', 'is-empty' => $empty, 'is-compact' => $compact]) }}
    role="button"
    tabindex="0"
    aria-describedby="{{ $hintId }}"
    data-image-picker-dropzone>
    <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
    <strong data-image-picker-dropzone-title @if ($moreTitle) data-image-picker-more-title="{{ $moreTitle }}" @endif>{{ $empty ? $emptyTitle : $title }}</strong>
    <span id="{{ $hintId }}">
        <span class="image-picker-hint-fine">Arraste para cá ou cole com <kbd data-image-picker-paste-key>Ctrl+V</kbd> — ou clique para escolher.</span>
        <span class="image-picker-hint-coarse">Toque em Câmera ou em {{ $pickLabel }}.</span>
    </span>
</div>
