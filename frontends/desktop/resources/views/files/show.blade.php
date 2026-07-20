@extends('layouts.app')

@section('styles')
    <link href="{{ asset('assets/css/file-preview-modal.css') }}?v={{ filemtime(public_path('assets/css/file-preview-modal.css')) }}" rel="stylesheet">
@endsection

@section('content')
    @php
        $capabilities = (array) ($file['capabilities'] ?? []);
        $mime = (string) ($file['detected_mime_type'] ?? '');
        $previewKind = str_starts_with($mime, 'image/') ? 'image' : ($mime === 'application/pdf' ? 'pdf' : null);
        $statusBadge = static fn (string $status): string => match ($status) {
            'active', 'valid', 'clean', 'native', 'migrated' => 'success',
            'archived', 'cataloged', 'unknown', 'pending' => 'warning',
            'quarantined', 'missing', 'corrupted', 'rejected', 'failed' => 'danger',
            default => 'secondary',
        };
        $formatBytes = static fn (int $bytes): string => $bytes >= 1_048_576
            ? number_format($bytes / 1_048_576, 2, ',', '.').' MB'
            : number_format(max(0, $bytes) / 1024, 1, ',', '.').' KB';
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <a href="{{ route('files.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i> Voltar ao catálogo</a>
            <p class="desktop-eyebrow mt-3">Metadados controlados</p>
            <h1 class="surface-title fs-3 mb-2 text-break">{{ $file['safe_download_name'] ?? 'Arquivo' }}</h1>
            <code>{{ $file['uuid'] ?? '' }}</code>
        </div>
        @if ($capabilities['download'] ?? false)
            <div class="d-flex flex-wrap gap-2">
                @if ($previewKind !== null)
                    <button type="button" class="btn btn-soft" data-file-preview-trigger data-preview-kind="{{ $previewKind }}" data-preview-url="{{ route('files.preview', ['fileUuid' => $file['uuid']]) }}" data-download-url="{{ route('files.download', ['fileUuid' => $file['uuid']]) }}" data-file-name="{{ $file['safe_download_name'] ?? 'arquivo' }}" data-file-mime="{{ $mime }}"><i class="bi bi-eye me-1"></i> Visualizar</button>
                @endif
                <a href="{{ route('files.download', ['fileUuid' => $file['uuid']]) }}" class="btn btn-primary"><i class="bi bi-download me-1"></i> Baixar</a>
            </div>
        @endif
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12 col-xl-8">
            <section class="surface-card p-3 h-100">
                <h2 class="surface-title fs-5 mb-3">Identificação e estados</h2>
                <div class="row g-3">
                    @foreach ([
                        'Categoria' => $file['category'] ?? '—',
                        'Origem' => $file['origin'] ?? '—',
                        'MIME detectado' => $file['detected_mime_type'] ?? '—',
                        'Tamanho' => $formatBytes((int) ($file['size_bytes'] ?? 0)),
                        'Confidencialidade' => $file['confidentiality'] ?? '—',
                        'Criado em' => !empty($file['document_created_at'] ?? $file['created_at'] ?? null) ? \Illuminate\Support\Carbon::parse($file['document_created_at'] ?? $file['created_at'])->format('d/m/Y H:i:s') : '—',
                    ] as $label => $value)
                        <div class="col-12 col-md-6">
                            <div class="small text-secondary">{{ $label }}</div>
                            <div class="fw-semibold text-break">{{ $value }}</div>
                        </div>
                    @endforeach
                    <div class="col-12">
                        <div class="small text-secondary mb-1">SHA-256</div>
                        <code class="text-break">{{ $file['sha256'] ?? '—' }}</code>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        @foreach (['lifecycle_status', 'integrity_status', 'security_status', 'migration_status'] as $state)
                            @php($value = (string) ($file[$state] ?? 'unknown'))
                            <span class="badge text-bg-{{ $statusBadge($value) }} p-2">{{ $state }}: {{ $value }}</span>
                        @endforeach
                    </div>
                </div>
            </section>
        </div>
        <div class="col-12 col-xl-4">
            <section class="surface-card p-3 h-100">
                <h2 class="surface-title fs-5">Ações administrativas</h2>
                <p class="surface-subtitle">Exigem motivo, credenciais de administrador e geram evento auditável.</p>
                <div class="d-grid gap-2">
                    @if ($capabilities['archive'] ?? false)
                        <button type="button" class="btn btn-outline-warning js-file-action" data-bs-toggle="modal" data-bs-target="#fileActionModal" data-title="Arquivar arquivo" data-url="{{ route('files.archive', ['fileUuid' => $file['uuid']]) }}" data-validation="false"><i class="bi bi-archive me-1"></i> Arquivar</button>
                    @endif
                    @if ($capabilities['restore'] ?? false)
                        <button type="button" class="btn btn-outline-success js-file-action" data-bs-toggle="modal" data-bs-target="#fileActionModal" data-title="Restaurar arquivo" data-url="{{ route('files.restore', ['fileUuid' => $file['uuid']]) }}" data-validation="false"><i class="bi bi-arrow-counterclockwise me-1"></i> Restaurar</button>
                    @endif
                    @if ($capabilities['quarantine'] ?? false)
                        <button type="button" class="btn btn-outline-danger js-file-action" data-bs-toggle="modal" data-bs-target="#fileActionModal" data-title="Colocar em quarentena" data-url="{{ route('files.quarantine', ['fileUuid' => $file['uuid']]) }}" data-validation="false"><i class="bi bi-shield-x me-1"></i> Quarentenar</button>
                    @endif
                    @if ($capabilities['release'] ?? false)
                        <button type="button" class="btn btn-outline-primary js-file-action" data-bs-toggle="modal" data-bs-target="#fileActionModal" data-title="Liberar da quarentena" data-url="{{ route('files.release-quarantine', ['fileUuid' => $file['uuid']]) }}" data-validation="true"><i class="bi bi-shield-check me-1"></i> Liberar quarentena</button>
                    @endif
                    @if (!collect($capabilities)->except('download')->contains(true))
                        <div class="alert alert-secondary mb-0">Ações de estado indisponíveis para sua permissão, vínculo ou kill switch atual.</div>
                    @endif
                </div>
            </section>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12 col-xl-6">
            <section class="surface-table h-100">
                <div class="surface-table-header"><div><h2 class="surface-title">Vínculos ativos</h2><p class="surface-subtitle mb-0">Referências de domínio usadas para autorização.</p></div></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Domínio</th><th>ID</th><th>Relação</th><th>Atual</th></tr></thead>
                        <tbody>
                        @forelse ((array) ($file['links'] ?? []) as $link)
                            <tr><td>{{ $link['subject_type'] ?? '' }}</td><td>{{ $link['subject_id'] ?? '' }}</td><td>{{ $link['relation'] ?? '' }}</td><td>{{ ($link['is_current'] ?? false) ? 'Sim' : 'Não' }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="text-secondary">Nenhum vínculo ativo.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <div class="col-12 col-xl-6">
            <section class="surface-card p-3 h-100">
                <h2 class="surface-title fs-5">Findings vinculados</h2>
                <div class="d-grid gap-2">
                    @forelse ((array) ($file['findings'] ?? []) as $finding)
                        <div class="border rounded-3 p-2">
                            <div class="d-flex justify-content-between"><strong>{{ $finding['finding_type'] ?? '' }}</strong><span class="badge text-bg-warning">{{ $finding['severity'] ?? '' }}</span></div>
                            <div class="small text-secondary">{{ $finding['restricted_path_hint'] ?? 'Path mascarado' }} · {{ $finding['resolution_status'] ?? '' }}</div>
                        </div>
                    @empty
                        <p class="text-secondary mb-0">Nenhum finding vinculado.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>

    <section class="surface-table">
        <div class="surface-table-header"><div><h2 class="surface-title">Eventos auditáveis</h2><p class="surface-subtitle mb-0">Últimos 100 eventos; sem paths absolutos ou conteúdo do arquivo.</p></div></div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Data</th><th>Ação</th><th>Resultado</th><th>Ator</th><th>Contexto seguro</th></tr></thead>
                <tbody>
                @forelse ((array) ($file['events'] ?? []) as $event)
                    <tr>
                        <td class="text-nowrap">{{ !empty($event['created_at']) ? \Illuminate\Support\Carbon::parse($event['created_at'])->format('d/m/Y H:i:s') : '—' }}</td>
                        <td><code>{{ $event['action'] ?? '' }}</code></td>
                        <td>{{ $event['result'] ?? '' }}</td>
                        <td>{{ $event['actor_id'] ?? 'sistema' }}</td>
                        <td><code class="small text-break">{{ collect((array) ($event['context'] ?? []))->map(fn ($value, $key) => $key.'='.$value)->implode(' · ') ?: '—' }}</code></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-secondary">Nenhum evento registrado.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @include('files._preview_modal')

    <div class="modal fade" id="fileActionModal" tabindex="-1" aria-labelledby="fileActionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form id="fileActionForm" class="modal-content border-0 shadow" method="POST" action="">
                @csrf
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h2 class="modal-title fs-5" id="fileActionModalLabel">Confirmar ação</h2>
                        <p class="small text-secondary mb-0">A sessão atual será preservada; as credenciais abaixo apenas autorizam esta ação.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger d-none" id="fileActionError" role="alert"></div>
                    <div class="mb-3">
                        <label for="fileActionReason" class="form-label fw-semibold">Motivo</label>
                        <textarea id="fileActionReason" name="reason" class="form-control" rows="3" minlength="10" maxlength="500" required></textarea>
                    </div>
                    <div class="mb-3 d-none" id="fileActionValidationGroup">
                        <label for="fileActionValidation" class="form-label fw-semibold">Referência da validação</label>
                        <input id="fileActionValidation" name="validation_reference" class="form-control" maxlength="120" placeholder="Ex.: SCAN-2026-0001">
                    </div>
                    <div class="mb-3">
                        <label for="fileActionAdminEmail" class="form-label fw-semibold">E-mail do administrador</label>
                        <input id="fileActionAdminEmail" name="admin_email" type="email" class="form-control" maxlength="255" autocomplete="username" required>
                    </div>
                    <div>
                        <label for="fileActionAdminPassword" class="form-label fw-semibold">Senha do administrador</label>
                        <input id="fileActionAdminPassword" name="admin_password" type="password" class="form-control" maxlength="200" autocomplete="current-password" required>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="fileActionSubmit"><i class="bi bi-shield-lock me-1"></i> Autorizar e executar</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('assets/js/file-preview-modal.js') }}?v={{ filemtime(public_path('assets/js/file-preview-modal.js')) }}"></script>
    <script>
        (() => {
            const modal = document.getElementById('fileActionModal');
            const form = document.getElementById('fileActionForm');
            if (!modal || !form) return;

            const title = document.getElementById('fileActionModalLabel');
            const error = document.getElementById('fileActionError');
            const password = document.getElementById('fileActionAdminPassword');
            const validationGroup = document.getElementById('fileActionValidationGroup');
            const validation = document.getElementById('fileActionValidation');
            const submit = document.getElementById('fileActionSubmit');

            modal.addEventListener('show.bs.modal', (event) => {
                const button = event.relatedTarget;
                form.action = button?.dataset.url || '';
                title.textContent = button?.dataset.title || 'Confirmar ação';
                const needsValidation = button?.dataset.validation === 'true';
                validationGroup.classList.toggle('d-none', !needsValidation);
                validation.required = needsValidation;
                error.classList.add('d-none');
                error.textContent = '';
                form.reset();
            });

            modal.addEventListener('hidden.bs.modal', () => {
                password.value = '';
                validation.value = '';
                form.action = '';
            });

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (!form.reportValidity() || !form.action) return;

                error.classList.add('d-none');
                submit.disabled = true;
                const formData = new FormData(form);

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: formData,
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.success) {
                        const validationMessage = payload.errors
                            ? Object.values(payload.errors).flat().join(' ')
                            : '';
                        throw new Error(validationMessage || payload.message || 'Não foi possível executar a ação.');
                    }
                    window.location.reload();
                } catch (exception) {
                    password.value = '';
                    error.textContent = exception.message || 'Não foi possível executar a ação.';
                    error.classList.remove('d-none');
                    submit.disabled = false;
                }
            });
        })();
    </script>
@endsection
