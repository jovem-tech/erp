@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Comercial</p>
            <h2 class="surface-title fs-3 mb-2">{{ $featureTitle ?? 'Pessoas' }}</h2>
            <p class="surface-subtitle mb-0">{{ $featureSubtitle ?? 'Entrada operacional em preparação.' }}</p>
        </div>
    </div>

    <section class="desktop-form-card">
        <div class="desktop-form-intro">
            <div class="desktop-form-intro-copy">
                <h2 class="surface-title mb-1">{{ $featureTitle ?? 'Pessoas' }}</h2>
                <p class="surface-subtitle mb-0">{{ $featureMessage ?? 'A área já está posicionada no menu lateral e será evoluída em etapas.' }}</p>
            </div>
        </div>

        <div class="empty-state-shell p-4 mt-4">
            <div class="empty-state-icon">
                <i class="bi bi-people-fill"></i>
            </div>
            <h3 class="empty-state-title">Estrutura em andamento</h3>
            <p class="empty-state-text">
                O submenu já foi organizado no padrão do legado. Esta tela serve como ponto de entrada até a próxima etapa da migração.
            </p>

            <div class="d-flex flex-wrap gap-2 justify-content-center">
                @if (!empty($primaryUrl))
                    <a href="{{ $primaryUrl }}" class="btn btn-primary">
                        {{ $primaryLabel ?? 'Voltar' }}
                    </a>
                @endif

                @if (!empty($secondaryUrl))
                    <a href="{{ $secondaryUrl }}" class="btn btn-outline-light">
                        {{ $secondaryLabel ?? 'Abrir outra área' }}
                    </a>
                @endif
            </div>
        </div>
    </section>
@endsection
