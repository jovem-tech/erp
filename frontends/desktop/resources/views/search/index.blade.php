@extends('layouts.app')

@section('content')
    @php
        $query = trim((string) ($query ?? ''));
        $results = is_array($results ?? null) ? $results : [];
        $sections = $results['sections'] ?? [];
        $total = (int) ($results['total'] ?? 0);
    @endphp

    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Busca completa</h2>
                <p class="surface-subtitle">Pesquise por OS, clientes, equipamentos, orçamentos e demais registros liberados no seu perfil.</p>
            </div>

            <span class="desktop-chip">{{ number_format($total, 0, ',', '.') }} resultados</span>
        </div>

        <form method="get" action="{{ route('search.index') }}" class="desktop-filter-grid">
            <div>
                <label for="searchScope">Escopo</label>
                <select id="searchScope" name="scope" class="form-select">
                    @foreach (($scopes ?? []) as $scopeItem)
                        <option value="{{ $scopeItem['value'] }}" @selected(($scopeItem['value'] ?? 'tudo') === ($scope ?? 'tudo'))>
                            {{ $scopeItem['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="desktop-grid-span-2">
                <label for="searchQuery">Termo</label>
                <input type="search" id="searchQuery" name="q" class="form-control" value="{{ $query }}" placeholder="Digite algo para localizar rapidamente">
            </div>

            <div class="field-actions">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-search me-2"></i>
                    Buscar
                </button>
                <a href="{{ route('dashboard') }}" class="btn btn-outline-light">Voltar</a>
            </div>
        </form>
    </section>

    @if ($query === '')
        @include('layouts.partials.empty-state', [
            'icon' => 'bi-search',
            'title' => 'Digite algo para começar a busca',
            'message' => 'A busca completa já varre os principais campos operacionais liberados pelo backend central.',
        ])
    @elseif ($sections === [])
        @include('layouts.partials.empty-state', [
            'icon' => 'bi-search',
            'title' => 'Nenhum resultado encontrado',
            'message' => 'Tente um termo diferente ou troque o escopo da busca.',
        ])
    @else
        <section class="desktop-grid desktop-grid-two">
            @foreach ($sections as $section)
                <article class="surface-table">
                    <div class="surface-table-header">
                        <div>
                            <h2 class="surface-title">
                                <i class="bi {{ $section['icon'] ?? 'bi-grid' }} me-2"></i>
                                {{ $section['label'] ?? 'Resultados' }}
                            </h2>
                            <p class="surface-subtitle">{{ count($section['items'] ?? []) }} registros encontrados</p>
                        </div>
                    </div>

                    <div class="desktop-result-stack">
                        @foreach (($section['items'] ?? []) as $item)
                            @php
                                $imageUrl = trim((string) ($item['image_url'] ?? ''));
                                $facts = is_array($item['facts'] ?? null) ? array_slice($item['facts'], 0, 4) : [];
                            @endphp
                            <a href="{{ $item['url'] ?? '#' }}" class="desktop-result-card {{ $facts !== [] ? 'has-equipment-details' : '' }}">
                                <div class="desktop-result-card-media {{ $imageUrl !== '' ? 'has-image' : '' }}">
                                    @if ($imageUrl !== '')
                                        <img src="{{ $imageUrl }}" alt="" loading="lazy">
                                    @elseif ($facts !== [])
                                        <i class="bi bi-camera"></i>
                                        <small>Sem foto</small>
                                    @else
                                        <i class="bi {{ $item['icon'] ?? 'bi-grid' }}"></i>
                                    @endif
                                </div>

                                <div class="desktop-result-card-copy">
                                    <strong>{{ $item['label'] ?? 'Resultado' }}</strong>
                                    @if ($facts !== [])
                                        <span class="desktop-result-equipment-facts">
                                            @foreach ($facts as $fact)
                                                <span><b>{{ $fact['label'] ?? 'Dado' }}:</b> {{ $fact['value'] ?? 'Não informado' }}</span>
                                            @endforeach
                                        </span>
                                    @endif
                                    <span>{{ $item['subtitle'] ?? '' }}</span>
                                    @if (trim((string) ($item['meta'] ?? '')) !== '')
                                        <small>{{ $item['meta'] }}</small>
                                    @endif
                                </div>

                                <i class="bi bi-arrow-right-short desktop-result-card-arrow"></i>
                            </a>
                        @endforeach
                    </div>
                </article>
            @endforeach
        </section>
    @endif
@endsection
