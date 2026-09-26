{{-- Fila com "Enviar" do padrão de inserção de imagem (specs/049), para envio
     imediato (data-image-picker="uploader"). Nada é gravado antes do clique.
     Slot `extras`: campos que vão junto em cada lote — marque-os com
     data-image-picker-extra e `name` (ex.: categoria da foto). --}}
@props([
    'title' => 'Imagens prontas para enviar',
])
<div {{ $attributes->class(['image-picker-queue', 'd-none']) }} data-image-picker-queue>
    <div class="image-picker-queue-head">
        <strong data-image-picker-queue-title>{{ $title }}</strong>
        @isset($extras)
            {{ $extras }}
        @endisset
    </div>
    <div class="image-picker-list" data-image-picker-list></div>
    <div class="alert alert-danger image-picker-error d-none" role="alert" data-image-picker-error></div>
    <div class="image-picker-queue-footer">
        <div class="image-picker-progress d-none" data-image-picker-progress>
            <div class="progress" role="progressbar" aria-label="Envio" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-image-picker-progress-track>
                <div class="progress-bar" data-image-picker-progress-bar></div>
            </div>
            <small data-image-picker-progress-label>Enviando…</small>
        </div>
        <div class="image-picker-queue-actions">
            <button type="button" class="btn btn-outline-light btn-sm" data-image-picker-clear>Descartar</button>
            <button type="button" class="btn btn-primary btn-sm" data-image-picker-submit>
                <i class="bi bi-cloud-arrow-up me-1"></i><span data-image-picker-submit-label>Enviar</span>
            </button>
        </div>
    </div>
</div>
<span class="visually-hidden" aria-live="polite" data-image-picker-live></span>
