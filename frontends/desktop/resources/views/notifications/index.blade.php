@extends('layouts.app')

@section('content')
    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Notificações</h2>
                <p class="surface-subtitle">Central de avisos sincronizada com o backend central.</p>
            </div>

            <div class="d-flex align-items-center gap-2">
                <span class="desktop-chip">{{ number_format((int) ($unreadCount ?? 0), 0, ',', '.') }} não lidas</span>
                <form method="post" action="{{ route('notifications.mark-all') }}">
                    @csrf
                    <button type="submit" class="btn btn-soft btn-sm">
                        Marcar todas como lidas
                    </button>
                </form>
            </div>
        </div>
    </section>

    <section class="surface-table">
        @if (($notifications ?? []) !== [])
            <div class="desktop-notification-page-list">
                @foreach ($notifications as $notification)
                    <a href="{{ route('notifications.open', $notification['id']) }}" class="desktop-notification-page-item {{ empty($notification['lida_em']) ? 'is-unread' : 'is-read' }}">
                        <span class="desktop-notification-icon">
                            <i class="{{ $notification['icone'] ?? 'bi bi-bell' }}"></i>
                        </span>

                        <span class="desktop-notification-page-copy">
                            <strong>{{ $notification['titulo'] ?? 'Notificação' }}</strong>
                            <p>{{ $notification['corpo'] ?? '' }}</p>
                            <small>{{ $notification['criada_em_humano'] ?? 'Agora' }}</small>
                        </span>

                        <i class="bi bi-arrow-right-short desktop-result-card-arrow"></i>
                    </a>
                @endforeach
            </div>
        @else
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-bell',
                'title' => 'Sem notificações',
                'message' => 'Quando o backend central enviar novos eventos, eles aparecerão aqui.',
            ])
        @endif
    </section>
@endsection
