@props([
    'ajuda' => null,
    'for' => null,
])

{{--
    Rotulo de campo com ajuda opcional.

    O texto de `ajuda` responde duas perguntas, nessa ordem: para que o campo
    serve e como preenche-lo bem. Aceita HTML simples (<strong>, <br>, e
    <span class="tip-exemplo"> para a linha de exemplo).

        <x-campo-label ajuda="Quanto custa <strong>uma hora</strong> da sua bancada.">
            Custo hora produtiva (R$)
        </x-campo-label>
--}}

<label @if($for) for="{{ $for }}" @endif {{ $attributes->class(['campo-label-ajuda' => $ajuda !== null]) }}>
    <span>{{ $slot }}</span>
    @if($ajuda)
        <button type="button"
                class="campo-ajuda"
                data-bs-toggle="tooltip"
                data-bs-html="true"
                data-bs-title="{{ $ajuda }}"
                aria-label="O que significa este campo">
            <i class="bi bi-question-circle-fill" aria-hidden="true"></i>
        </button>
    @endif
</label>
