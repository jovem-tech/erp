@extends('layouts.app')

@section('content')
    @php
        $isCorrespondence = ($notificationBox ?? 'all') === 'correspondence';
    @endphp
    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">{{ $isCorrespondence ? 'Mensagens e documentos' : 'Notificações' }}</h2>
                <p class="surface-subtitle">
                    {{ $isCorrespondence
                        ? 'Caixa de correspondências, mensagens e documentos aguardando sua ciência.'
                        : 'Central de avisos operacionais sincronizada com o backend central.' }}
                </p>
            </div>

            <div class="d-flex align-items-center gap-2">
                <span class="desktop-chip">{{ number_format((int) ($unreadCount ?? 0), 0, ',', '.') }} não lidas</span>
                <form method="post" action="{{ route('notifications.mark-all') }}">
                    @csrf
                    <input type="hidden" name="box" value="{{ $notificationBox ?? 'all' }}">
                    <button type="submit" class="btn btn-soft btn-sm">
                        Marcar todas como lidas
                    </button>
                </form>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-3">
            <a href="{{ route('notifications.index', ['box' => 'operational']) }}" class="btn btn-sm {{ !$isCorrespondence ? 'btn-primary' : 'btn-soft' }}">
                <i class="bi bi-bell me-1"></i>Avisos operacionais
            </a>
            <a href="{{ route('notifications.index', ['box' => 'correspondence']) }}" class="btn btn-sm {{ $isCorrespondence ? 'btn-primary' : 'btn-soft' }}">
                <i class="bi bi-envelope me-1"></i>Mensagens e documentos
            </a>
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
                'icon' => $isCorrespondence ? 'bi-envelope' : 'bi-bell',
                'title' => $isCorrespondence ? 'Sem mensagens ou documentos pendentes' : 'Sem notificações',
                'message' => $isCorrespondence
                    ? 'Novas mensagens e solicitações de assinatura aparecerão aqui.'
                    : 'Quando o backend central enviar novos eventos, eles aparecerão aqui.',
            ])
        @endif
    </section>
@endsection
