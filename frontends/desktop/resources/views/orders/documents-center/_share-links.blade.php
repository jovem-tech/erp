@if ($shareLinks !== [])
    <div class="d-flex flex-column gap-3">
        @foreach ($shareLinks as $link)
            @php
                $isRevoked = (bool) ($link['revoked_at'] ?? null);
                $linkId = (int) ($link['id'] ?? 0);
            @endphp
            <div class="summary-card">
                <div class="d-flex justify-content-between gap-3">
                    <div>
                        <span class="summary-card-eyebrow">Link #{{ $linkId }}</span>
                        <div class="summary-card-value fs-6">{{ ($link['format'] ?? 'a4') === '80mm' ? '80mm' : 'A4' }}</div>
                    </div>
                    <span class="desktop-chip {{ $isRevoked ? '' : 'desktop-chip-success' }}">
                        {{ $isRevoked ? 'Revogado' : 'Ativo' }}
                    </span>
                </div>
                <div class="summary-card-meta mt-2">
                    Expira em:
                    {{ isset($link['expires_at']) ? \Illuminate\Support\Carbon::parse($link['expires_at'])->format('d/m/Y H:i') : '—' }}
                    · Acessos: {{ $link['access_count'] ?? 0 }}
                </div>
                @unless ($isRevoked)
                    <button type="button"
                            class="btn btn-sm btn-outline-light mt-3"
                            data-doc-revoke-link="{{ $linkId }}">
                        Revogar
                    </button>
                @endunless
            </div>
        @endforeach
    </div>
@else
    <p class="text-secondary mb-0">Nenhum link público gerado até agora.</p>
@endif
