@extends('layouts.app')

@section('styles')
    <link href="{{ asset('assets/css/file-preview-modal.css') }}?v={{ filemtime(public_path('assets/css/file-preview-modal.css')) }}" rel="stylesheet">
    <style>
        .file-category-card { min-height: 104px; transition: border-color .15s ease, transform .15s ease, box-shadow .15s ease; }
        .file-category-card:hover { border-color: var(--bs-primary); transform: translateY(-2px); box-shadow: 0 .4rem 1rem rgba(15, 55, 100, .08); }
        .file-category-card.is-active { border-color: var(--bs-primary); box-shadow: inset 0 0 0 1px var(--bs-primary); }
        .file-card { overflow: hidden; transition: border-color .15s ease, box-shadow .15s ease; }
        .file-card:has(.file-select:checked) { border-color: var(--bs-primary); box-shadow: 0 0 0 2px rgba(65, 116, 183, .16); }
        .file-preview { position: relative; display: grid; place-items: center; height: 170px; overflow: hidden; background: linear-gradient(145deg, #f5f8fc, #eaf1fa); }
        .file-preview img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .file-preview img.file-preview-document { padding: .75rem; object-fit: contain; background: #eaf1fa; opacity: 0; transition: opacity .15s ease; }
        .file-preview img.file-preview-document.is-loaded { opacity: 1; }
        .file-preview-icon { color: #6f8db5; font-size: 3.4rem; }
        .file-select-wrap { position: absolute; z-index: 2; top: .65rem; left: .65rem; display: grid; place-items: center; width: 2rem; height: 2rem; border-radius: .65rem; background: rgba(255, 255, 255, .94); box-shadow: 0 .2rem .6rem rgba(0, 0, 0, .12); }
        .file-select { width: 1.05rem; height: 1.05rem; }
        .file-name { min-width: 0; }
        .file-name > * { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file-card-meta { display: grid; gap: .4rem; padding: .65rem .75rem; border-radius: .7rem; background: rgba(65, 116, 183, .06); }
        .file-card-meta-row { display: flex; align-items: center; min-width: 0; gap: .45rem; color: var(--bs-secondary-color); font-size: .78rem; line-height: 1.25; }
        .file-card-meta-row i { flex: 0 0 auto; color: var(--bs-primary); }
        .file-card-meta-row span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file-selection-bar { position: sticky; top: 76px; z-index: 20; }
        .file-sync-spin { animation: file-sync-rotate .8s linear infinite; }
        @keyframes file-sync-rotate { to { transform: rotate(360deg); } }
        .file-list-checkbox { width: 2.75rem; }
        .file-list-photo-column { width: 5.25rem; }
        .file-list-preview { position: relative; display: grid; place-items: center; width: 4.25rem; height: 3.4rem; overflow: hidden; padding: 0; border: 1px solid var(--bs-border-color); border-radius: .6rem; color: #6f8db5; background: linear-gradient(145deg, #f5f8fc, #eaf1fa); }
        .file-list-preview[data-file-preview-trigger] { cursor: pointer; transition: border-color .15s ease, box-shadow .15s ease; }
        .file-list-preview[data-file-preview-trigger]:hover, .file-list-preview[data-file-preview-trigger]:focus-visible { border-color: var(--bs-primary); box-shadow: 0 0 0 2px rgba(65, 116, 183, .16); }
        .file-list-preview i { font-size: 1.4rem; }
        .file-list-preview img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .file-list-preview img.file-preview-document { padding: .18rem; object-fit: contain; background: #eaf1fa; }
        .file-list-client { min-width: 10rem; max-width: 15rem; }
        .file-list-client span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        @media (max-width: 767.98px) {
            .file-preview { height: 145px; }
            .file-selection-bar { top: 64px; }
        }
    </style>
@endsection

@section('content')
    @php
        $totals = (array) ($dashboard['totals'] ?? []);
        $operation = (array) ($dashboard['operation'] ?? []);
        $operationMode = (string) ($operation['mode'] ?? 'off');
        $automaticSyncEnabled = (bool) ($operation['automatic_sync_enabled'] ?? false);
        $automaticSyncInterval = max(1, (int) ($operation['automatic_sync_interval_minutes'] ?? 5));
        $trashRetention = (array) ($operation['trash_retention'] ?? []);
        $trashRetentionDays = (int) ($trashRetention['days'] ?? 30);
        $permanentDeletionEnabled = (bool) ($operation['permanent_deletion_enabled'] ?? false);
        $mutationsEnabled = (bool) ($dashboard['state_mutations_enabled'] ?? false);
        $permissions = (array) data_get(session('desktop_auth'), 'user.permissions.arquivos', []);
        $canDownload = in_array('baixar', $permissions, true);
        $canDelete = in_array('excluir', $permissions, true);
        $canRestore = in_array('restaurar', $permissions, true);
        $canAdminister = in_array('administrar', $permissions, true);
        $categoryLabels = [
            'company_login_background' => 'Fundos do login',
            'user_profile_photo' => 'Fotos de usuários',
            'company_logo' => 'Logos da empresa',
            'equipment_photo' => 'Fotos de equipamentos',
            'order_photo' => 'Fotos de OS',
            'user_signature' => 'Assinaturas',
            'budget_pdf' => 'Orçamentos em PDF',
            'order_pdf' => 'Documentos de OS',
            'chat_attachment' => 'Anexos do chat',
        ];
        $categoryIcons = [
            'company_login_background' => 'bi-image',
            'company_logo' => 'bi-badge-ad',
            'equipment_photo' => 'bi-camera',
            'order_photo' => 'bi-card-image',
            'order_pdf' => 'bi-file-earmark-pdf',
            'budget_pdf' => 'bi-receipt',
            'user_signature' => 'bi-pen',
            'user_profile_photo' => 'bi-person-square',
            'chat_attachment' => 'bi-paperclip',
        ];
        $statusLabels = [
            'active' => 'Ativo', 'archived' => 'Arquivado', 'trashed' => 'Na lixeira', 'purged' => 'Excluído definitivamente',
            'valid' => 'Íntegro', 'unknown' => 'Não verificado', 'missing' => 'Ausente', 'corrupted' => 'Corrompido',
            'clean' => 'Seguro', 'pending' => 'Pendente', 'quarantined' => 'Quarentena', 'rejected' => 'Rejeitado',
            'native' => 'Nativo', 'legacy' => 'Legado', 'cataloged' => 'Catalogado', 'migrating' => 'Migrando', 'migrated' => 'Migrado', 'failed' => 'Falhou',
        ];
        $statusBadge = static fn (string $status): string => match ($status) {
            'active', 'valid', 'clean', 'native', 'migrated' => 'success',
            'archived', 'cataloged', 'unknown', 'pending', 'legacy' => 'warning',
            'quarantined', 'missing', 'corrupted', 'rejected', 'failed', 'trashed', 'purged' => 'danger',
            default => 'secondary',
        };
        $formatBytes = static function (int $bytes): string {
            if ($bytes >= 1_073_741_824) return number_format($bytes / 1_073_741_824, 2, ',', '.').' GB';
            if ($bytes >= 1_048_576) return number_format($bytes / 1_048_576, 2, ',', '.').' MB';
            if ($bytes >= 1024) return number_format($bytes / 1024, 1, ',', '.').' KB';
            return number_format($bytes, 0, ',', '.').' B';
        };
        $isDeliverable = static fn (array $file): bool => ($file['lifecycle_status'] ?? '') === 'active'
            && ($file['integrity_status'] ?? '') === 'valid'
            && ($file['security_status'] ?? '') === 'clean';
        $isPreviewable = static fn (array $file): bool => in_array(($file['lifecycle_status'] ?? ''), ['active', 'trashed'], true)
            && ($file['integrity_status'] ?? '') === 'valid'
            && ($file['security_status'] ?? '') === 'clean';
        $categoryRows = collect((array) ($dashboard['by_category'] ?? []))->keyBy('category');
        $selectedCategory = (string) ($filters['category'] ?? '');
        $auditOnly = (bool) ($filters['audit_only'] ?? false);
        $selectedLifecycle = $auditOnly ? 'audit' : (string) ($filters['lifecycle_status'] ?? 'active');
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <p class="desktop-eyebrow">Biblioteca de documentos</p>
            <h1 class="surface-title fs-3 mb-2">Gerenciador de Arquivos</h1>
            <p class="surface-subtitle mb-0">Navegue por categoria, visualize, selecione e gerencie os arquivos do ERP.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if ($canAdminister && $automaticSyncEnabled)
                <form method="POST" action="{{ route('files.sync') }}" data-file-sync-form>
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary" data-file-sync-button title="Solicitar uma varredura imediata dos diretórios configurados">
                        <i class="bi bi-arrow-repeat me-1" data-file-sync-icon></i><span data-file-sync-label>Sincronizar agora</span>
                    </button>
                </form>
            @endif
            <span class="badge text-bg-light border p-2"><i class="bi bi-files me-1"></i>{{ number_format((int) ($totals['files'] ?? 0), 0, ',', '.') }} arquivos</span>
            <span class="badge text-bg-light border p-2"><i class="bi bi-device-ssd me-1"></i>{{ $formatBytes((int) ($totals['bytes'] ?? 0)) }}</span>
        </div>
    </div>

    @if ($operationMode === 'off')
        <div class="alert alert-warning d-flex align-items-start gap-2 py-2" role="status">
            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
            <div><strong>Catalogação automática desativada.</strong> Os arquivos já catalogados podem ser navegados; novos arquivos dependem da rotina de sincronização.</div>
        </div>
    @elseif ($automaticSyncEnabled)
        <div class="alert alert-success d-flex align-items-start gap-2 py-2" role="status">
            <i class="bi bi-arrow-repeat mt-1"></i>
            <div><strong>Sincronização automática ativa.</strong> Novos arquivos são descobertos e catalogados em segundo plano em até {{ $automaticSyncInterval }} minutos, sem mover ou renomear os arquivos de origem.</div>
        </div>
    @endif

    <section class="mb-4" aria-labelledby="file-categories-title">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <h2 class="surface-title fs-5 mb-1" id="file-categories-title">Pastas por categoria</h2>
                <p class="surface-subtitle mb-0">Escolha uma pasta para filtrar a biblioteca.</p>
            </div>
        </div>
        <div class="row g-2">
            <div class="col-6 col-md-4 col-xl-2">
                <a href="{{ route('files.index', array_merge(request()->except(['page', 'category', 'lifecycle_status', 'integrity_status']), ['view' => $viewMode])) }}" class="surface-card file-category-card p-3 text-decoration-none d-flex flex-column justify-content-between {{ $selectedCategory === '' && ! in_array($selectedLifecycle, ['trashed', 'audit'], true) ? 'is-active' : '' }}">
                    <i class="bi bi-folder2-open fs-3 text-primary"></i>
                    <div><strong class="d-block text-body">Todos os arquivos</strong><span class="small text-secondary">{{ number_format((int) ($totals['files'] ?? 0), 0, ',', '.') }} itens</span></div>
                </a>
            </div>
            @foreach ($categoryLabels as $category => $label)
                @php
                    $row = (array) ($categoryRows->get($category) ?? []);
                @endphp
                @continue((int) ($row['file_count'] ?? 0) === 0)
                <div class="col-6 col-md-4 col-xl-2">
                    <a href="{{ route('files.index', array_merge(request()->except(['page', 'category']), ['category' => $category, 'view' => $viewMode])) }}" class="surface-card file-category-card p-3 text-decoration-none d-flex flex-column justify-content-between {{ $selectedCategory === $category ? 'is-active' : '' }}">
                        <i class="bi {{ $categoryIcons[$category] ?? 'bi-folder' }} fs-3 text-primary"></i>
                        <div><strong class="d-block text-body">{{ $label }}</strong><span class="small text-secondary">{{ number_format((int) ($row['file_count'] ?? 0), 0, ',', '.') }} itens</span></div>
                    </a>
                </div>
            @endforeach
            <div class="col-6 col-md-4 col-xl-2">
                <a href="{{ route('files.index', array_merge(request()->except(['page', 'category']), ['lifecycle_status' => 'trashed', 'view' => $viewMode])) }}" class="surface-card file-category-card p-3 text-decoration-none d-flex flex-column justify-content-between {{ $selectedLifecycle === 'trashed' ? 'is-active' : '' }}">
                    <i class="bi bi-trash3 fs-3 text-danger"></i>
                    <div><strong class="d-block text-body">Lixeira</strong><span class="small text-secondary">{{ number_format((int) ($totals['trashed'] ?? 0), 0, ',', '.') }} itens</span></div>
                </a>
            </div>
            @if ((int) ($totals['audit_records'] ?? 0) > 0)
                <div class="col-6 col-md-4 col-xl-2">
                    <a href="{{ route('files.index', array_merge(request()->except(['page', 'category', 'integrity_status']), ['lifecycle_status' => 'audit', 'view' => $viewMode])) }}" class="surface-card file-category-card p-3 text-decoration-none d-flex flex-column justify-content-between {{ $auditOnly ? 'is-active' : '' }}">
                        <i class="bi bi-shield-exclamation fs-3 text-warning"></i>
                        <div><strong class="d-block text-body">Auditoria</strong><span class="small text-secondary">{{ number_format((int) ($totals['audit_records'] ?? 0), 0, ',', '.') }} registros</span></div>
                    </a>
                </div>
            @endif
        </div>
    </section>

    <section class="surface-card p-3 mb-3" aria-labelledby="file-toolbar-title">
        <form method="GET" action="{{ route('files.index') }}" class="row g-2 align-items-end">
            <input type="hidden" name="view" value="{{ $viewMode }}">
            <div class="col-12 col-lg-5">
                <label for="file-search" class="form-label" id="file-toolbar-title">Buscar arquivos</label>
                <div class="input-group">
                    <span class="input-group-text bg-transparent"><i class="bi bi-search"></i></span>
                    <input id="file-search" type="search" name="q" class="form-control" maxlength="200" value="{{ $filters['q'] ?? '' }}" placeholder="Nome do arquivo ou UUID">
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <label for="file-category" class="form-label">Categoria</label>
                <select id="file-category" name="category" class="form-select">
                    <option value="">Todas</option>
                    @foreach ($categoryLabels as $value => $label)
                        <option value="{{ $value }}" @selected($selectedCategory === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label for="file-lifecycle" class="form-label">Local</label>
                <select id="file-lifecycle" name="lifecycle_status" class="form-select">
                    <option value="active" @selected($selectedLifecycle === 'active')>Arquivos ativos</option>
                    <option value="archived" @selected($selectedLifecycle === 'archived')>Arquivados</option>
                    <option value="trashed" @selected($selectedLifecycle === 'trashed')>Lixeira</option>
                    <option value="audit" @selected($selectedLifecycle === 'audit')>Auditoria</option>
                    <option value="" @selected($selectedLifecycle === '')>Todos</option>
                </select>
            </div>
            <div class="col-7 col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Filtrar</button>
                <a href="{{ route('files.index', ['view' => $viewMode]) }}" class="btn btn-soft" aria-label="Limpar filtros"><i class="bi bi-x-lg"></i></a>
            </div>
            <div class="col-5 col-lg-1 text-end">
                <div class="btn-group" role="group" aria-label="Modo de visualização">
                    <a href="{{ route('files.index', array_merge(request()->except('page'), ['view' => 'grid'])) }}" class="btn btn-sm {{ $viewMode === 'grid' ? 'btn-primary' : 'btn-soft' }}" title="Grade"><i class="bi bi-grid-3x3-gap"></i></a>
                    <a href="{{ route('files.index', array_merge(request()->except('page'), ['view' => 'list'])) }}" class="btn btn-sm {{ $viewMode === 'list' ? 'btn-primary' : 'btn-soft' }}" title="Lista"><i class="bi bi-list-ul"></i></a>
                </div>
            </div>
        </form>
    </section>

    @if ($selectedLifecycle === 'trashed')
        <section class="surface-card p-3 mb-3 d-flex flex-wrap align-items-center justify-content-between gap-3" aria-labelledby="trash-retention-title">
            <div>
                <h2 class="surface-title fs-6 mb-1" id="trash-retention-title"><i class="bi bi-clock-history me-1"></i>Retenção da lixeira</h2>
                <p class="surface-subtitle mb-0">
                    @if ($trashRetentionDays === 0)
                        A exclusão automática está desativada; os arquivos permanecem até uma ação manual autorizada.
                    @else
                        Arquivos com mais de <strong>{{ $trashRetentionDays }} dias</strong> na lixeira são excluídos definitivamente pelo scheduler diário.
                    @endif
                </p>
                @if (! $permanentDeletionEnabled)
                    <div class="small text-warning-emphasis mt-1"><i class="bi bi-shield-lock me-1"></i>A exclusão definitiva está bloqueada pelo kill switch do servidor.</div>
                @endif
            </div>
            @if ($canAdminister)
                <button type="button" class="btn btn-soft btn-sm" data-bs-toggle="modal" data-bs-target="#trashRetentionModal">
                    <i class="bi bi-sliders me-1"></i>Configurar retenção
                </button>
            @endif
        </section>
    @elseif ($auditOnly)
        <section class="surface-card p-3 mb-3" aria-labelledby="file-audit-title">
            <h2 class="surface-title fs-6 mb-1" id="file-audit-title"><i class="bi bi-shield-exclamation me-1"></i>Auditoria de arquivos removidos</h2>
            <p class="surface-subtitle mb-0">Reúne exclusões definitivas e registros cujo conteúdo não está mais disponível. Metadados, vínculos históricos, hash e eventos permanecem preservados fora da contagem e da retenção da lixeira.</p>
        </section>
    @endif

    <div class="surface-card file-selection-bar p-2 px-3 mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex align-items-center gap-2">
            <input class="form-check-input m-0" type="checkbox" id="selectAllFiles" aria-label="Selecionar todos os arquivos desta página">
            <label for="selectAllFiles" class="small fw-semibold mb-0">Selecionar página</label>
            <span class="badge text-bg-primary" id="selectedFilesCount">0 selecionados</span>
            @if (! in_array($selectedLifecycle, ['trashed', 'audit'], true) && ! $canDelete)
                <span class="small text-secondary"><i class="bi bi-lock me-1"></i>A exclusão exige a permissão Arquivos: Excluir.</span>
            @elseif ($selectedLifecycle === 'trashed' && ! $canRestore && ! $canDelete)
                <span class="small text-secondary"><i class="bi bi-lock me-1"></i>Seu grupo não possui ações de restauração ou exclusão.</span>
            @elseif ($auditOnly && ! $canDelete)
                <span class="small text-secondary"><i class="bi bi-lock me-1"></i>A remoção de registros de auditoria exige a permissão Arquivos: Excluir.</span>
            @endif
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if ($auditOnly)
                <button type="button" class="btn btn-outline-danger btn-sm" id="purgeSelectedFiles" disabled @disabled(!$canDelete || !$permanentDeletionEnabled)>
                    <i class="bi bi-trash3-fill me-1"></i>Excluir registros selecionados
                </button>
            @elseif ($selectedLifecycle === 'trashed')
                <button type="button" class="btn btn-outline-success btn-sm" id="restoreSelectedFiles" disabled @disabled(!$canRestore || !$mutationsEnabled)>
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Restaurar selecionados
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" id="purgeSelectedFiles" disabled @disabled(!$canDelete || !$permanentDeletionEnabled)>
                    <i class="bi bi-trash3-fill me-1"></i>Excluir definitivamente
                </button>
            @else
                <button type="button" class="btn btn-soft btn-sm" id="downloadSelectedFiles" disabled @disabled(!$canDownload)>
                    <i class="bi bi-file-earmark-zip me-1"></i>Baixar selecionados
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" id="trashSelectedFiles" disabled @disabled(!$canDelete || !$mutationsEnabled) title="{{ !$canDelete ? 'Seu grupo não possui a permissão Arquivos: Excluir' : (!$mutationsEnabled ? 'Ações de estado bloqueadas pelo kill switch' : 'Mover selecionados para a lixeira') }}">
                    <i class="bi bi-trash3 me-1"></i>Excluir selecionados
                </button>
            @endif
        </div>
    </div>

    <form id="bulkDownloadForm" method="POST" action="{{ route('files.download-batch') }}" class="d-none">
        @csrf
        <div data-selected-inputs></div>
    </form>

    <section class="mb-3" aria-labelledby="files-title">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <h2 class="surface-title fs-5 mb-1" id="files-title">{{ $auditOnly ? 'Auditoria' : ($selectedCategory !== '' ? ($categoryLabels[$selectedCategory] ?? 'Arquivos') : ($selectedLifecycle === 'trashed' ? 'Lixeira' : 'Arquivos')) }}</h2>
                <p class="surface-subtitle mb-0">{{ number_format((int) ($pagination['total'] ?? count($files)), 0, ',', '.') }} itens encontrados.</p>
            </div>
        </div>

        @if ($viewMode === 'grid')
            <div class="row g-3">
                @forelse ($files as $file)
                    @php
                        $mime = (string) ($file['detected_mime_type'] ?? '');
                        $image = str_starts_with($mime, 'image/');
                        $pdf = $mime === 'application/pdf';
                        $deliverable = $isDeliverable($file);
                        $previewable = ($image || $pdf) && $isPreviewable($file);
                        $lifecycle = (string) ($file['lifecycle_status'] ?? 'unknown');
                        $inTrash = $lifecycle === 'trashed';
                        $purged = $lifecycle === 'purged';
                        $integrity = (string) ($file['integrity_status'] ?? 'unknown');
                        $restoreable = $inTrash && (bool) data_get(
                            $file,
                            'capabilities.restore',
                            $integrity === 'valid' && ($file['security_status'] ?? '') === 'clean'
                        );
                        $linkedClient = is_array($file['linked_client'] ?? null) ? $file['linked_client'] : null;
                        $linkedClientName = trim((string) ($linkedClient['name'] ?? ''));
                        $documentCreatedAt = $file['document_created_at'] ?? $file['created_at'] ?? null;
                        $createdAtLabel = !empty($documentCreatedAt)
                            ? \Illuminate\Support\Carbon::parse($documentCreatedAt)->format('d/m/Y H:i')
                            : 'Data não informada';
                    @endphp
                    <div class="col-12 col-sm-6 col-lg-4 col-xxl-3">
                        <article class="surface-card file-card h-100">
                            <div class="file-preview">
                                <label class="file-select-wrap" title="Selecionar arquivo">
                                    <input class="form-check-input file-select m-0" type="checkbox" value="{{ $file['uuid'] }}" data-downloadable="{{ $deliverable ? '1' : '0' }}" data-trashable="{{ ! $inTrash && ! $purged ? '1' : '0' }}" data-restoreable="{{ $restoreable ? '1' : '0' }}" data-purgeable="{{ $inTrash ? '1' : '0' }}" @disabled($purged) aria-label="Selecionar {{ $file['safe_download_name'] ?? 'arquivo' }}">
                                </label>
                                <i class="bi {{ $image ? 'bi-file-earmark-image' : ($pdf ? 'bi-file-earmark-pdf' : 'bi-file-earmark') }} file-preview-icon"></i>
                                @if ($image && $previewable && $canDownload)
                                    <img loading="lazy" src="{{ route('files.preview', ['fileUuid' => $file['uuid']]) }}" alt="Prévia de {{ $file['safe_download_name'] ?? 'arquivo' }}" onerror="this.remove()">
                                @elseif ($pdf && $previewable && $canDownload)
                                    <img class="file-preview-document" data-pdf-thumbnail-url="{{ route('files.thumbnail', ['fileUuid' => $file['uuid']]) }}" alt="Primeira página de {{ $file['safe_download_name'] ?? 'arquivo PDF' }}">
                                @endif
                            </div>
                            <div class="p-3">
                                <div class="file-name mb-2">
                                    <a href="{{ route('files.show', ['fileUuid' => $file['uuid']]) }}" class="fw-semibold text-body text-decoration-none" title="{{ $file['safe_download_name'] ?? 'arquivo' }}">{{ $file['safe_download_name'] ?? 'arquivo' }}</a>
                                    <div class="small text-secondary">{{ $categoryLabels[$file['category'] ?? ''] ?? ($file['category'] ?? 'Sem categoria') }}</div>
                                </div>
                                <div class="file-card-meta mb-3">
                                    <div class="file-card-meta-row" title="Criado em {{ $createdAtLabel }}">
                                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                                        <span>Criado em {{ $createdAtLabel }}</span>
                                    </div>
                                    <div class="file-card-meta-row" title="{{ $linkedClientName !== '' ? 'Cliente: '.$linkedClientName : 'Sem cliente vinculado' }}">
                                        <i class="bi bi-person" aria-hidden="true"></i>
                                        <span>{{ $linkedClientName !== '' ? 'Cliente: '.$linkedClientName : 'Sem cliente vinculado' }}</span>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                                    <span class="small text-secondary">{{ $formatBytes((int) ($file['size_bytes'] ?? 0)) }}</span>
                                    <span class="d-flex flex-wrap justify-content-end gap-1">
                                        @if ($integrity === 'missing')
                                            <span class="badge text-bg-danger" title="O conteúdo não existe mais no armazenamento">Conteúdo ausente</span>
                                        @endif
                                        <span class="badge text-bg-{{ $statusBadge($lifecycle) }}">{{ $statusLabels[$lifecycle] ?? $lifecycle }}</span>
                                    </span>
                                </div>
                                <div class="d-flex gap-1">
                                    @if ($previewable && $canDownload)
                                        <button type="button" class="btn btn-soft btn-sm" title="Visualizar" aria-label="Visualizar {{ $file['safe_download_name'] ?? 'arquivo' }}" data-file-preview-trigger data-preview-kind="{{ $image ? 'image' : 'pdf' }}" data-preview-url="{{ route('files.preview', ['fileUuid' => $file['uuid']]) }}" data-download-url="{{ $deliverable ? route('files.download', ['fileUuid' => $file['uuid']]) : '' }}" data-file-name="{{ $file['safe_download_name'] ?? 'arquivo' }}" data-file-mime="{{ $mime }}"><i class="bi bi-eye"></i></button>
                                    @endif
                                    <a href="{{ route('files.show', ['fileUuid' => $file['uuid']]) }}" class="btn btn-soft btn-sm" title="Detalhes"><i class="bi bi-info-circle"></i></a>
                                    @if ($deliverable && $canDownload)
                                        <a href="{{ route('files.download', ['fileUuid' => $file['uuid']]) }}" class="btn btn-soft btn-sm flex-grow-1"><i class="bi bi-download me-1"></i>Baixar</a>
                                    @endif
                                    @if ($inTrash)
                                        @if (! $auditOnly)
                                            <button type="button" class="btn btn-outline-success btn-sm file-restore-one" data-file-uuid="{{ $file['uuid'] }}" data-file-name="{{ $file['safe_download_name'] ?? 'arquivo' }}" @disabled(!$canRestore || !$mutationsEnabled || !$restoreable) title="{{ $restoreable ? 'Restaurar' : 'Não é possível restaurar: conteúdo ausente' }}"><i class="bi bi-arrow-counterclockwise"></i></button>
                                        @endif
                                        <button type="button" class="btn btn-outline-danger btn-sm file-purge-one" data-file-uuid="{{ $file['uuid'] }}" data-file-name="{{ $file['safe_download_name'] ?? 'arquivo' }}" @disabled(!$canDelete || !$permanentDeletionEnabled) title="Excluir definitivamente"><i class="bi bi-trash3-fill"></i></button>
                                    @elseif (! $purged)
                                        <button type="button" class="btn btn-outline-danger btn-sm file-trash-one" data-file-uuid="{{ $file['uuid'] }}" data-file-name="{{ $file['safe_download_name'] ?? 'arquivo' }}" @disabled(!$canDelete || !$mutationsEnabled) title="Mover para a lixeira"><i class="bi bi-trash3"></i></button>
                                    @endif
                                </div>
                            </div>
                        </article>
                    </div>
                @empty
                    <div class="col-12"><div class="surface-card text-center text-secondary py-5"><i class="bi bi-folder2-open d-block fs-1 mb-2"></i>Nenhum arquivo encontrado para os filtros informados.</div></div>
                @endforelse
            </div>
        @else
            <div class="surface-table">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th class="file-list-checkbox"></th><th class="file-list-photo-column">Foto</th><th>Arquivo</th><th>Categoria</th><th>Cliente</th><th>Tamanho</th><th>Estado</th><th>Criado em</th><th class="text-end">Ações</th></tr></thead>
                        <tbody>
                        @forelse ($files as $file)
                            @php
                                $mime = (string) ($file['detected_mime_type'] ?? '');
                                $image = str_starts_with($mime, 'image/');
                                $pdf = $mime === 'application/pdf';
                                $deliverable = $isDeliverable($file);
                                $previewable = ($image || $pdf) && $isPreviewable($file);
                                $lifecycle = (string) ($file['lifecycle_status'] ?? 'unknown');
                                $inTrash = $lifecycle === 'trashed';
                                $purged = $lifecycle === 'purged';
                                $integrity = (string) ($file['integrity_status'] ?? 'unknown');
                                $restoreable = $inTrash && (bool) data_get(
                                    $file,
                                    'capabilities.restore',
                                    $integrity === 'valid' && ($file['security_status'] ?? '') === 'clean'
                                );
                                $linkedClient = is_array($file['linked_client'] ?? null) ? $file['linked_client'] : null;
                                $linkedClientName = trim((string) ($linkedClient['name'] ?? ''));
                                $documentCreatedAt = $file['document_created_at'] ?? $file['created_at'] ?? null;
                            @endphp
                            <tr>
                                <td>
                                    <label class="d-inline-flex align-items-center justify-content-center p-2 mb-0" title="Selecionar arquivo">
                                        <input class="form-check-input file-select m-0" type="checkbox" value="{{ $file['uuid'] }}" data-downloadable="{{ $deliverable ? '1' : '0' }}" data-trashable="{{ ! $inTrash && ! $purged ? '1' : '0' }}" data-restoreable="{{ $restoreable ? '1' : '0' }}" data-purgeable="{{ $inTrash ? '1' : '0' }}" @disabled($purged) aria-label="Selecionar {{ $file['safe_download_name'] ?? 'arquivo' }}">
                                    </label>
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="file-list-preview"
                                        @if ($previewable && $canDownload)
                                            data-file-preview-trigger
                                            data-preview-kind="{{ $image ? 'image' : 'pdf' }}"
                                            data-preview-url="{{ route('files.preview', ['fileUuid' => $file['uuid']]) }}"
                                            data-download-url="{{ $deliverable ? route('files.download', ['fileUuid' => $file['uuid']]) : '' }}"
                                            data-file-name="{{ $file['safe_download_name'] ?? 'arquivo' }}"
                                            data-file-mime="{{ $mime }}"
                                        @else
                                            disabled
                                        @endif
                                        title="{{ $previewable ? 'Visualizar '.($file['safe_download_name'] ?? 'arquivo') : 'Miniatura indisponível' }}"
                                        aria-label="{{ $previewable ? 'Visualizar '.($file['safe_download_name'] ?? 'arquivo') : 'Miniatura indisponível' }}"
                                    >
                                        <i class="bi {{ $image ? 'bi-file-earmark-image' : ($pdf ? 'bi-file-earmark-pdf' : 'bi-file-earmark') }}" aria-hidden="true"></i>
                                        @if ($image && $previewable && $canDownload)
                                            <img loading="lazy" src="{{ route('files.preview', ['fileUuid' => $file['uuid']]) }}" alt="Miniatura de {{ $file['safe_download_name'] ?? 'arquivo' }}" onerror="this.remove()">
                                        @elseif ($pdf && $previewable && $canDownload)
                                            <img loading="lazy" class="file-preview-document" data-pdf-thumbnail-url="{{ route('files.thumbnail', ['fileUuid' => $file['uuid']]) }}" alt="Miniatura da primeira página de {{ $file['safe_download_name'] ?? 'arquivo PDF' }}">
                                        @endif
                                    </button>
                                </td>
                                <td><a href="{{ route('files.show', ['fileUuid' => $file['uuid']]) }}" class="fw-semibold text-body text-decoration-none text-break">{{ $file['safe_download_name'] ?? 'arquivo' }}</a><div class="small text-secondary">{{ $mime }}</div></td>
                                <td>{{ $categoryLabels[$file['category'] ?? ''] ?? ($file['category'] ?? '—') }}</td>
                                <td class="file-list-client" title="{{ $linkedClientName !== '' ? $linkedClientName : 'Sem cliente vinculado' }}"><span>{{ $linkedClientName !== '' ? $linkedClientName : 'Sem cliente vinculado' }}</span></td>
                                <td class="text-nowrap">{{ $formatBytes((int) ($file['size_bytes'] ?? 0)) }}</td>
                                <td>
                                    <span class="d-flex flex-wrap gap-1">
                                        @if ($integrity === 'missing')
                                            <span class="badge text-bg-danger" title="O conteúdo não existe mais no armazenamento">Conteúdo ausente</span>
                                        @endif
                                        <span class="badge text-bg-{{ $statusBadge($lifecycle) }}">{{ $statusLabels[$lifecycle] ?? $lifecycle }}</span>
                                    </span>
                                </td>
                                <td class="text-nowrap">{{ !empty($documentCreatedAt) ? \Illuminate\Support\Carbon::parse($documentCreatedAt)->format('d/m/Y H:i') : '—' }}</td>
                                <td class="text-end text-nowrap">
                                    @if ($previewable && $canDownload)<button type="button" class="btn btn-soft btn-sm" title="Visualizar" aria-label="Visualizar {{ $file['safe_download_name'] ?? 'arquivo' }}" data-file-preview-trigger data-preview-kind="{{ $image ? 'image' : 'pdf' }}" data-preview-url="{{ route('files.preview', ['fileUuid' => $file['uuid']]) }}" data-download-url="{{ $deliverable ? route('files.download', ['fileUuid' => $file['uuid']]) : '' }}" data-file-name="{{ $file['safe_download_name'] ?? 'arquivo' }}" data-file-mime="{{ $mime }}"><i class="bi bi-eye"></i></button>@endif
                                    <a href="{{ route('files.show', ['fileUuid' => $file['uuid']]) }}" class="btn btn-soft btn-sm" title="Detalhes"><i class="bi bi-info-circle"></i></a>
                                    @if ($deliverable && $canDownload)<a href="{{ route('files.download', ['fileUuid' => $file['uuid']]) }}" class="btn btn-soft btn-sm" title="Baixar"><i class="bi bi-download"></i></a>@endif
                                    @if ($inTrash)
                                        @if (! $auditOnly)
                                            <button type="button" class="btn btn-outline-success btn-sm file-restore-one" data-file-uuid="{{ $file['uuid'] }}" data-file-name="{{ $file['safe_download_name'] ?? 'arquivo' }}" @disabled(!$canRestore || !$mutationsEnabled || !$restoreable) title="{{ $restoreable ? 'Restaurar' : 'Não é possível restaurar: conteúdo ausente' }}"><i class="bi bi-arrow-counterclockwise"></i></button>
                                        @endif
                                        <button type="button" class="btn btn-outline-danger btn-sm file-purge-one" data-file-uuid="{{ $file['uuid'] }}" data-file-name="{{ $file['safe_download_name'] ?? 'arquivo' }}" @disabled(!$canDelete || !$permanentDeletionEnabled) title="Excluir definitivamente"><i class="bi bi-trash3-fill"></i></button>
                                    @elseif (! $purged)
                                        <button type="button" class="btn btn-outline-danger btn-sm file-trash-one" data-file-uuid="{{ $file['uuid'] }}" data-file-name="{{ $file['safe_download_name'] ?? 'arquivo' }}" @disabled(!$canDelete || !$mutationsEnabled) title="Mover para a lixeira"><i class="bi bi-trash3"></i></button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-secondary py-5">Nenhum arquivo encontrado.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </section>

    @if ((int) ($pagination['last_page'] ?? 1) > 1)
        @php
            $currentPage = (int) ($pagination['current_page'] ?? 1);
            $lastPage = (int) ($pagination['last_page'] ?? 1);
        @endphp
        <nav class="surface-card d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 mb-4" aria-label="Paginação dos arquivos">
            <span class="small text-secondary">Página {{ $currentPage }} de {{ $lastPage }}</span>
            <div class="btn-group">
                <a class="btn btn-soft btn-sm @if($currentPage <= 1) disabled @endif" href="{{ route('files.index', array_merge(request()->except('page'), ['page' => max(1, $currentPage - 1)])) }}">Anterior</a>
                <a class="btn btn-soft btn-sm @if($currentPage >= $lastPage) disabled @endif" href="{{ route('files.index', array_merge(request()->except('page'), ['page' => min($lastPage, $currentPage + 1)])) }}">Próxima</a>
            </div>
        </nav>
    @endif

    <details class="surface-card p-3 mb-4">
        <summary class="fw-semibold">Diagnóstico técnico do catálogo</summary>
        <div class="row g-3 mt-1">
            <div class="col-12 col-xl-7">
                <h3 class="surface-title fs-6">Volume por categoria</h3>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Categoria</th><th class="text-end">Arquivos</th><th class="text-end">Volume</th></tr></thead><tbody>
                    @foreach ((array) ($dashboard['by_category'] ?? []) as $row)
                        <tr><td>{{ $categoryLabels[$row['category'] ?? ''] ?? ($row['category'] ?? '—') }}</td><td class="text-end">{{ number_format((int) ($row['file_count'] ?? 0), 0, ',', '.') }}</td><td class="text-end">{{ $formatBytes((int) ($row['total_bytes'] ?? 0)) }}</td></tr>
                    @endforeach
                </tbody></table></div>
            </div>
            <div class="col-12 col-xl-5">
                <h3 class="surface-title fs-6">Findings abertos recentes</h3>
                <div class="d-grid gap-2">
                    @forelse ($findings as $finding)
                        <div class="border rounded-3 p-2"><div class="d-flex justify-content-between gap-2"><strong>{{ $finding['finding_type'] ?? 'finding' }}</strong><span class="badge text-bg-warning">{{ $finding['severity'] ?? 'unknown' }}</span></div><div class="small text-secondary">{{ $finding['restricted_path_hint'] ?? 'Sem referência de path' }}</div></div>
                    @empty
                        <p class="text-secondary mb-0">Nenhum finding aberto.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </details>

    @include('files._preview_modal')

    <div class="modal fade" id="trashFilesModal" tabindex="-1" aria-labelledby="trashFilesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form id="trashFilesForm" class="modal-content border-0 shadow" method="POST" action="{{ route('files.trash-batch') }}">
                @csrf
                <div data-selected-inputs></div>
                <div class="modal-header border-0 pb-0">
                    <div><h2 class="modal-title fs-5" id="trashFilesModalLabel">Mover arquivos para a lixeira</h2><p class="small text-secondary mb-0" id="trashFilesDescription">Os arquivos poderão ser restaurados posteriormente.</p></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger d-none" id="trashFilesError" role="alert"></div>
                    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>Esta ação remove o arquivo dos fluxos ativos, mas preserva o binário para recuperação e auditoria.</div>
                    <p class="small text-secondary">Confirme com as credenciais de um usuário ativo que possua a permissão <strong>Administrar</strong> no módulo Arquivos.</p>
                    <div class="mb-3"><label for="trashReason" class="form-label fw-semibold">Motivo</label><textarea id="trashReason" name="reason" class="form-control" rows="3" minlength="10" maxlength="500" required></textarea></div>
                    <div class="mb-3"><label for="trashAdminEmail" class="form-label fw-semibold">E-mail do responsável autorizado</label><input id="trashAdminEmail" name="admin_email" type="email" class="form-control" maxlength="255" autocomplete="username" value="{{ $canAdminister ? (string) data_get(session('desktop_auth'), 'user.email', '') : '' }}" required></div>
                    <div><label for="trashAdminPassword" class="form-label fw-semibold">Senha do responsável autorizado</label><input id="trashAdminPassword" name="admin_password" type="password" class="form-control" maxlength="200" autocomplete="current-password" required></div>
                </div>
                <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger" id="trashFilesSubmit"><i class="bi bi-trash3 me-1"></i>Mover para a lixeira</button></div>
            </form>
        </div>
    </div>

    @if (in_array($selectedLifecycle, ['trashed', 'audit'], true))
        <div class="modal fade" id="restoreFilesModal" tabindex="-1" aria-labelledby="restoreFilesModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form id="restoreFilesForm" class="modal-content border-0 shadow file-json-action-form" method="POST" action="{{ route('files.restore-batch') }}" data-error-id="restoreFilesError" data-password-id="restoreAdminPassword">
                    @csrf
                    <div data-selected-inputs></div>
                    <div class="modal-header border-0 pb-0">
                        <div><h2 class="modal-title fs-5" id="restoreFilesModalLabel">Restaurar arquivos</h2><p class="small text-secondary mb-0" id="restoreFilesDescription">Os arquivos voltarão aos fluxos ativos.</p></div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger d-none" id="restoreFilesError" role="alert"></div>
                        <div class="mb-3"><label for="restoreReason" class="form-label fw-semibold">Motivo</label><textarea id="restoreReason" name="reason" class="form-control" rows="3" minlength="10" maxlength="500" required></textarea></div>
                        <div class="mb-3"><label for="restoreAdminEmail" class="form-label fw-semibold">E-mail do responsável autorizado</label><input id="restoreAdminEmail" name="admin_email" type="email" class="form-control" maxlength="255" autocomplete="username" value="{{ $canAdminister ? (string) data_get(session('desktop_auth'), 'user.email', '') : '' }}" required></div>
                        <div><label for="restoreAdminPassword" class="form-label fw-semibold">Senha do responsável autorizado</label><input id="restoreAdminPassword" name="admin_password" type="password" class="form-control" maxlength="200" autocomplete="current-password" required></div>
                    </div>
                    <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success"><i class="bi bi-arrow-counterclockwise me-1"></i>Restaurar</button></div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="purgeFilesModal" tabindex="-1" aria-labelledby="purgeFilesModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form id="purgeFilesForm" class="modal-content border-0 shadow file-json-action-form" method="POST" action="{{ route('files.purge-batch') }}" data-error-id="purgeFilesError" data-password-id="purgeAdminPassword">
                    @csrf
                    <div data-selected-inputs></div>
                    <div class="modal-header border-0 pb-0">
                        <div><h2 class="modal-title fs-5" id="purgeFilesModalLabel">Excluir definitivamente</h2><p class="small text-secondary mb-0" id="purgeFilesDescription">O binário não poderá ser recuperado.</p></div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger d-none" id="purgeFilesError" role="alert"></div>
                        <div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-1"></i><strong>Ação irreversível.</strong> Metadados e eventos ficam preservados apenas para auditoria.</div>
                        <div class="mb-3"><label for="purgeReason" class="form-label fw-semibold">Motivo</label><textarea id="purgeReason" name="reason" class="form-control" rows="3" minlength="10" maxlength="500" required></textarea></div>
                        <div class="mb-3"><label for="purgeConfirmation" class="form-label fw-semibold">Digite EXCLUIR para confirmar</label><input id="purgeConfirmation" name="confirmation" type="text" class="form-control" pattern="EXCLUIR" autocomplete="off" required></div>
                        <div class="mb-3"><label for="purgeAdminEmail" class="form-label fw-semibold">E-mail do responsável autorizado</label><input id="purgeAdminEmail" name="admin_email" type="email" class="form-control" maxlength="255" autocomplete="username" value="{{ $canAdminister ? (string) data_get(session('desktop_auth'), 'user.email', '') : '' }}" required></div>
                        <div><label for="purgeAdminPassword" class="form-label fw-semibold">Senha do responsável autorizado</label><input id="purgeAdminPassword" name="admin_password" type="password" class="form-control" maxlength="200" autocomplete="current-password" required></div>
                    </div>
                    <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger"><i class="bi bi-trash3-fill me-1"></i>Excluir definitivamente</button></div>
                </form>
            </div>
        </div>

        @if ($canAdminister)
            <div class="modal fade" id="trashRetentionModal" tabindex="-1" aria-labelledby="trashRetentionModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <form id="trashRetentionForm" class="modal-content border-0 shadow file-json-action-form" method="POST" action="{{ route('files.trash-retention') }}" data-error-id="trashRetentionError" data-password-id="retentionAdminPassword">
                        @csrf
                        <div class="modal-header border-0 pb-0">
                            <div><h2 class="modal-title fs-5" id="trashRetentionModalLabel">Política de retenção</h2><p class="small text-secondary mb-0">Define quando o scheduler remove definitivamente os binários.</p></div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-danger d-none" id="trashRetentionError" role="alert"></div>
                            <div class="mb-3"><label for="trashRetentionDays" class="form-label fw-semibold">Prazo</label><select id="trashRetentionDays" name="days" class="form-select" required><option value="0" @selected($trashRetentionDays === 0)>Desativada</option><option value="7" @selected($trashRetentionDays === 7)>7 dias</option><option value="30" @selected($trashRetentionDays === 30)>30 dias</option><option value="90" @selected($trashRetentionDays === 90)>90 dias</option></select></div>
                            <div class="mb-3"><label for="retentionReason" class="form-label fw-semibold">Motivo da alteração</label><textarea id="retentionReason" name="reason" class="form-control" rows="2" minlength="10" maxlength="500" required></textarea></div>
                            <div class="mb-3"><label for="retentionAdminEmail" class="form-label fw-semibold">E-mail do responsável autorizado</label><input id="retentionAdminEmail" name="admin_email" type="email" class="form-control" maxlength="255" autocomplete="username" value="{{ (string) data_get(session('desktop_auth'), 'user.email', '') }}" required></div>
                            <div><label for="retentionAdminPassword" class="form-label fw-semibold">Senha do responsável autorizado</label><input id="retentionAdminPassword" name="admin_password" type="password" class="form-control" maxlength="200" autocomplete="current-password" required></div>
                        </div>
                        <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="bi bi-shield-check me-1"></i>Salvar política</button></div>
                    </form>
                </div>
            </div>
        @endif
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('assets/js/file-preview-modal.js') }}?v={{ filemtime(public_path('assets/js/file-preview-modal.js')) }}"></script>
    <script>
        (() => {
            const checkboxes = [...document.querySelectorAll('.file-select')];
            const selectAll = document.getElementById('selectAllFiles');
            const count = document.getElementById('selectedFilesCount');
            const downloadButton = document.getElementById('downloadSelectedFiles');
            const trashButton = document.getElementById('trashSelectedFiles');
            const restoreButton = document.getElementById('restoreSelectedFiles');
            const purgeButton = document.getElementById('purgeSelectedFiles');
            const downloadForm = document.getElementById('bulkDownloadForm');
            const trashForm = document.getElementById('trashFilesForm');
            const trashModalElement = document.getElementById('trashFilesModal');
            const trashModal = trashModalElement && window.bootstrap ? new bootstrap.Modal(trashModalElement) : null;
            const restoreForm = document.getElementById('restoreFilesForm');
            const purgeForm = document.getElementById('purgeFilesForm');
            const restoreModalElement = document.getElementById('restoreFilesModal');
            const purgeModalElement = document.getElementById('purgeFilesModal');
            const restoreModal = restoreModalElement && window.bootstrap ? new bootstrap.Modal(restoreModalElement) : null;
            const purgeModal = purgeModalElement && window.bootstrap ? new bootstrap.Modal(purgeModalElement) : null;
            const canDownload = @json($canDownload);
            const canTrash = @json($canDelete && $mutationsEnabled);
            const canRestore = @json($canRestore && $mutationsEnabled);
            const canPurge = @json($canDelete && $permanentDeletionEnabled);
            const syncForm = document.querySelector('[data-file-sync-form]');
            const modalReturnFocus = new WeakMap();
            document.querySelectorAll('.modal').forEach((modalElement) => {
                modalElement.addEventListener('show.bs.modal', (event) => {
                    const trigger = event.relatedTarget instanceof HTMLElement
                        ? event.relatedTarget
                        : document.activeElement;
                    if (trigger instanceof HTMLElement && !modalElement.contains(trigger)) {
                        modalReturnFocus.set(modalElement, trigger);
                    }
                });
                modalElement.addEventListener('hide.bs.modal', () => {
                    const focused = document.activeElement;
                    if (focused instanceof HTMLElement && modalElement.contains(focused)) {
                        focused.blur();
                    }
                });
                modalElement.addEventListener('hidden.bs.modal', () => {
                    const trigger = modalReturnFocus.get(modalElement);
                    if (trigger instanceof HTMLElement && trigger.isConnected) {
                        trigger.focus({ preventScroll: true });
                    }
                    modalReturnFocus.delete(modalElement);
                });
            });
            const pdfThumbnailQueue = [];
            const pdfThumbnails = [...document.querySelectorAll('[data-pdf-thumbnail-url]')];
            const maximumConcurrentThumbnails = 2;
            let activePdfThumbnails = 0;
            const loadNextPdfThumbnail = () => {
                while (activePdfThumbnails < maximumConcurrentThumbnails && pdfThumbnailQueue.length > 0) {
                    const image = pdfThumbnailQueue.shift();
                    const url = image?.dataset.pdfThumbnailUrl || '';
                    if (!image || url === '') continue;
                    activePdfThumbnails += 1;
                    const complete = (loaded) => {
                        if (loaded) image.classList.add('is-loaded');
                        else image.remove();
                        activePdfThumbnails -= 1;
                        loadNextPdfThumbnail();
                    };
                    image.addEventListener('load', () => complete(true), { once: true });
                    image.addEventListener('error', () => complete(false), { once: true });
                    image.src = url;
                }
            };
            const enqueuePdfThumbnail = (image) => {
                if (image.dataset.pdfThumbnailQueued === '1') return;
                image.dataset.pdfThumbnailQueued = '1';
                pdfThumbnailQueue.push(image);
                loadNextPdfThumbnail();
            };
            if ('IntersectionObserver' in window) {
                const pdfThumbnailObserver = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (!entry.isIntersecting) return;
                        pdfThumbnailObserver.unobserve(entry.target);
                        enqueuePdfThumbnail(entry.target);
                    });
                }, { rootMargin: '320px 0px' });
                pdfThumbnails.forEach((image) => pdfThumbnailObserver.observe(image));
            } else {
                pdfThumbnails.forEach(enqueuePdfThumbnail);
            }
            syncForm?.addEventListener('submit', () => {
                const button = syncForm.querySelector('[data-file-sync-button]');
                const icon = syncForm.querySelector('[data-file-sync-icon]');
                const label = syncForm.querySelector('[data-file-sync-label]');
                if (button) button.disabled = true;
                if (icon) icon.classList.add('file-sync-spin');
                if (label) label.textContent = 'Solicitando...';
            });

            const selectedItems = () => checkboxes.filter((item) => item.checked);
            const selected = () => selectedItems().map((item) => item.value);
            const setInputs = (form, uuids) => {
                const container = form?.querySelector('[data-selected-inputs]');
                if (!container) return;
                container.replaceChildren(...uuids.map((uuid) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'file_uuids[]';
                    input.value = uuid;
                    return input;
                }));
            };
            const updateSelection = () => {
                const items = selectedItems();
                const total = items.length;
                count.textContent = `${total} selecionado${total === 1 ? '' : 's'}`;
                if (downloadButton) downloadButton.disabled = !canDownload || total === 0 || items.some((item) => item.dataset.downloadable !== '1');
                if (trashButton) trashButton.disabled = !canTrash || total === 0 || items.some((item) => item.dataset.trashable !== '1');
                if (restoreButton) restoreButton.disabled = !canRestore || total === 0 || items.some((item) => item.dataset.restoreable !== '1');
                if (purgeButton) purgeButton.disabled = !canPurge || total === 0 || items.some((item) => item.dataset.purgeable !== '1');
                selectAll.checked = checkboxes.length > 0 && total === checkboxes.length;
                selectAll.indeterminate = total > 0 && total < checkboxes.length;
            };
            const openTrash = (uuids, label = '') => {
                if (!trashModal || !uuids.length) return;
                setInputs(trashForm, uuids);
                document.getElementById('trashFilesDescription').textContent = label
                    ? `O arquivo “${label}” poderá ser restaurado posteriormente.`
                    : `${uuids.length} arquivo${uuids.length === 1 ? '' : 's'} poderão ser restaurados posteriormente.`;
                document.getElementById('trashFilesError').classList.add('d-none');
                trashModal.show();
            };
            const openTrashAction = (modal, form, descriptionId, uuids, label, verb) => {
                if (!modal || !form || !uuids.length) return;
                setInputs(form, uuids);
                const description = document.getElementById(descriptionId);
                if (description) {
                    description.textContent = label
                        ? `${verb} o arquivo “${label}”.`
                        : `${verb} ${uuids.length} arquivo${uuids.length === 1 ? '' : 's'}.`;
                }
                const error = document.getElementById(form.dataset.errorId || '');
                error?.classList.add('d-none');
                modal.show();
            };

            checkboxes.forEach((checkbox) => checkbox.addEventListener('change', updateSelection));
            selectAll?.addEventListener('change', () => {
                checkboxes.forEach((checkbox) => { checkbox.checked = selectAll.checked; });
                updateSelection();
            });
            downloadButton?.addEventListener('click', () => {
                const uuids = selected();
                if (!uuids.length) return;
                setInputs(downloadForm, uuids);
                downloadForm.submit();
            });
            trashButton?.addEventListener('click', () => openTrash(selected()));
            restoreButton?.addEventListener('click', () => openTrashAction(restoreModal, restoreForm, 'restoreFilesDescription', selected(), '', 'Restaurar'));
            purgeButton?.addEventListener('click', () => openTrashAction(purgeModal, purgeForm, 'purgeFilesDescription', selected(), '', 'Excluir definitivamente'));
            document.querySelectorAll('.file-trash-one').forEach((button) => button.addEventListener('click', () => {
                openTrash([button.dataset.fileUuid], button.dataset.fileName || '');
            }));
            document.querySelectorAll('.file-restore-one').forEach((button) => button.addEventListener('click', () => {
                openTrashAction(restoreModal, restoreForm, 'restoreFilesDescription', [button.dataset.fileUuid], button.dataset.fileName || '', 'Restaurar');
            }));
            document.querySelectorAll('.file-purge-one').forEach((button) => button.addEventListener('click', () => {
                openTrashAction(purgeModal, purgeForm, 'purgeFilesDescription', [button.dataset.fileUuid], button.dataset.fileName || '', 'Excluir definitivamente');
            }));

            trashModalElement?.addEventListener('hidden.bs.modal', () => {
                trashForm.reset();
                setInputs(trashForm, []);
                document.getElementById('trashFilesError').classList.add('d-none');
            });
            trashForm?.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (!trashForm.reportValidity()) return;
                const submit = document.getElementById('trashFilesSubmit');
                const error = document.getElementById('trashFilesError');
                submit.disabled = true;
                error.classList.add('d-none');
                try {
                    const response = await fetch(trashForm.action, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                        body: new FormData(trashForm),
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.success) {
                        const validationMessage = payload.errors ? Object.values(payload.errors).flat().join(' ') : '';
                        throw new Error(validationMessage || payload.message || 'Não foi possível excluir os arquivos.');
                    }
                    window.location.reload();
                } catch (exception) {
                    document.getElementById('trashAdminPassword').value = '';
                    error.textContent = exception.message || 'Não foi possível excluir os arquivos.';
                    error.classList.remove('d-none');
                    submit.disabled = false;
                }
            });

            document.querySelectorAll('.file-json-action-form').forEach((form) => {
                const modalElement = form.closest('.modal');
                modalElement?.addEventListener('hidden.bs.modal', () => {
                    form.reset();
                    setInputs(form, []);
                    document.getElementById(form.dataset.errorId || '')?.classList.add('d-none');
                });
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    if (!form.reportValidity()) return;
                    const submit = form.querySelector('button[type="submit"]');
                    const error = document.getElementById(form.dataset.errorId || '');
                    if (submit) submit.disabled = true;
                    error?.classList.add('d-none');
                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                            body: new FormData(form),
                        });
                        const payload = await response.json();
                        if (!response.ok || !payload.success) {
                            const validationMessage = payload.errors ? Object.values(payload.errors).flat().join(' ') : '';
                            throw new Error(validationMessage || payload.message || 'Não foi possível concluir a ação.');
                        }
                        window.location.reload();
                    } catch (exception) {
                        const password = document.getElementById(form.dataset.passwordId || '');
                        if (password) password.value = '';
                        if (error) {
                            error.textContent = exception.message || 'Não foi possível concluir a ação.';
                            error.classList.remove('d-none');
                        }
                        if (submit) submit.disabled = false;
                    }
                });
            });

            updateSelection();
        })();
    </script>
@endsection
