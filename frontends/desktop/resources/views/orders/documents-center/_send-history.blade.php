@php
    $sendStatusMap = [
        'na_fila' => ['label' => 'Na fila', 'class' => 'desktop-chip-warning', 'spinner' => true],
        'enviado' => ['label' => 'Enviado', 'class' => 'desktop-chip-success', 'spinner' => false],
        'erro' => ['label' => 'Erro', 'class' => 'desktop-chip-danger', 'spinner' => false],
    ];
@endphp

@if ($sendHistory !== [])
    <div class="d-flex flex-column gap-3">
        @foreach ($sendHistory as $send)
            @php
                $statusCode = (string) ($send['status'] ?? 'na_fila');
                $statusInfo = $sendStatusMap[$statusCode] ?? ['label' => $statusCode, 'class' => '', 'spinner' => false];
            @endphp
            <div class="summary-card">
                <div class="d-flex justify-content-between gap-3">
                    <div>
                        <span class="summary-card-eyebrow">{{ strtoupper($send['channel'] ?? 'canal') }}</span>
                        <div class="summary-card-value fs-6">{{ $send['destination_masked'] ?? 'Destino mascarado' }}</div>
                    </div>
                    <span class="desktop-chip {{ $statusInfo['class'] }}">
                        @if ($statusInfo['spinner'])
                            <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        @endif
                        {{ $statusInfo['label'] }}
                    </span>
                </div>
                <div class="summary-card-meta mt-2">
                    Template: {{ $send['template_code'] ?? '—' }} ·
                    Responsável: {{ $send['sender']['name'] ?? 'Sistema' }}
                    @if (isset($send['created_at']))
                        · {{ \Illuminate\Support\Carbon::parse($send['created_at'])->format('d/m/Y H:i') }}
                    @endif
                </div>
                @if (($send['error'] ?? '') !== '')
                    <div class="text-danger small mt-2">{{ $send['error'] }}</div>
                @endif
            </div>
        @endforeach
    </div>
@else
    <p class="text-secondary mb-0">Nenhum envio registrado até agora.</p>
@endif
