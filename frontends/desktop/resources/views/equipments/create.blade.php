@extends('layouts.app')

@php
    $types = $formData['types'] ?? [];
    $brands = $formData['brands'] ?? [];
    $models = $formData['models'] ?? [];
    $clients = $formData['clients'] ?? [];
    $catalogRelations = $formData['catalog_relations'] ?? [];
    $desktopDefaults = $formData['desktop_defaults'] ?? [];
    $maxPhotos = (int) ($formData['max_photos'] ?? 4);
    $isEditMode = (bool) ($isEdit ?? false);
    $resolvedFormAction = $formAction ?? route('equipments.store');
    $resolvedSubmitLabel = $submitLabel ?? 'Criar equipamento';
    $resolvedCancelUrl = $cancelUrl ?? route('equipments.index');
    $canQuickClient = \App\Support\DesktopSession::can('clientes', 'criar');
    $canQuickCatalog = \App\Support\DesktopSession::can('equipamentos', 'criar');
    $embedded = (bool) ($embedded ?? false);

    $fieldValue = static function (string $name, mixed $default = null) use ($equipment) {
        return old($name, $equipment[$name] ?? $default);
    };

    $selectedClientId = (string) $fieldValue('cliente_id');
    $selectedClientLabel = trim((string) $fieldValue('cliente_busca_label'));
    $selectedTypeId = (string) $fieldValue('tipo_id');
    $selectedBrandId = (string) $fieldValue('marca_id');

    $selectedType = collect($types)->first(static function (array $type) use ($fieldValue): bool {
        return (string) ($type['id'] ?? '') === (string) $fieldValue('tipo_id');
    });
    $selectedTypeFamily = (string) ($selectedType['family'] ?? 'other');
    $collectorVisible = in_array($selectedTypeFamily, ['desktop', 'notebook'], true);
    $isMountedDesktopFamily = $selectedTypeFamily === 'desktop';

    $allowedBrandIds = $selectedTypeId !== ''
        ? collect($catalogRelations)
            ->filter(static fn (array $relation): bool => (string) ($relation['tipo_id'] ?? '') === $selectedTypeId)
            ->pluck('marca_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all()
        : [];

    if ($isMountedDesktopFamily && ($desktopDefaults['marca_id'] ?? null) !== null) {
        $allowedBrandIds[] = (string) $desktopDefaults['marca_id'];
    }

    $allowedBrandIds = array_values(array_unique(array_filter(
        $allowedBrandIds,
        static fn (string $id): bool => $id !== ''
    )));

    $filteredBrands = $selectedTypeId !== ''
        ? array_values(array_filter(
            $brands,
            static fn (array $brand): bool => in_array((string) ($brand['id'] ?? ''), $allowedBrandIds, true)
        ))
        : [];

    $allowedModelIds = ($selectedTypeId !== '' && $selectedBrandId !== '')
        ? collect($catalogRelations)
            ->filter(static fn (array $relation): bool => (string) ($relation['tipo_id'] ?? '') === $selectedTypeId
                && (string) ($relation['marca_id'] ?? '') === $selectedBrandId)
            ->pluck('modelo_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all()
        : [];

    if (
        $isMountedDesktopFamily
        && $selectedBrandId !== ''
        && $selectedBrandId === (string) ($desktopDefaults['marca_id'] ?? '')
        && ($desktopDefaults['modelo_id'] ?? null) !== null
    ) {
        $allowedModelIds[] = (string) $desktopDefaults['modelo_id'];
    }

    $allowedModelIds = array_values(array_unique(array_filter(
        $allowedModelIds,
        static fn (string $id): bool => $id !== ''
    )));

    $filteredModels = ($selectedTypeId !== '' && $selectedBrandId !== '')
        ? array_values(array_filter(
            $models,
            static fn (array $model): bool => (string) ($model['marca_id'] ?? '') === $selectedBrandId
                && in_array((string) ($model['id'] ?? ''), $allowedModelIds, true)
        ))
        : [];

    $brandDisabled = $selectedTypeId === '';
    $modelDisabled = $selectedTypeId === '' || $selectedBrandId === '';
    $quickBrandDisabled = $selectedTypeId === '';
    $quickModelDisabled = $selectedTypeId === '';
    $brandPlaceholder = $brandDisabled ? 'Selecione o tipo primeiro...' : 'Selecione a marca...';
    $modelPlaceholder = $selectedTypeId === ''
        ? 'Selecione o tipo primeiro...'
        : ($selectedBrandId === '' ? 'Selecione a marca primeiro...' : 'Selecione o modelo...');
    $quickModelBrandPlaceholder = $quickModelDisabled ? 'Selecione o tipo primeiro...' : 'Selecione...';

    $desktopModeDisabled = $selectedTypeFamily === 'notebook';
    $desktopModeValue = $selectedTypeFamily === 'notebook'
        ? 'oem'
        : $fieldValue('desktop_modalidade', $isMountedDesktopFamily ? 'montado' : '');

    $allExistingPhotos = $isEditMode && is_array($equipment['photos'] ?? null)
        ? array_values(array_filter($equipment['photos'], static fn (mixed $photo): bool => is_array($photo)))
        : [];
    $defaultExistingPhotoIds = array_values(array_map(
        static fn (array $photo): int => (int) ($photo['id'] ?? 0),
        array_filter($allExistingPhotos, static fn (array $photo): bool => (int) ($photo['id'] ?? 0) > 0)
    ));
    $retainedExistingPhotoIds = $isEditMode
        ? array_values(array_filter(array_map(
            'intval',
            (array) old('existing_photo_ids', $defaultExistingPhotoIds)
        ), static fn (int $id): bool => $id > 0))
        : [];
    $existingPhotos = $isEditMode
        ? array_values(array_filter(
            $allExistingPhotos,
            static fn (array $photo): bool => in_array((int) ($photo['id'] ?? 0), $retainedExistingPhotoIds, true)
        ))
        : [];
    $existingPrimaryPhotoId = $isEditMode
        ? (int) old('foto_principal_existente_id', (int) ($equipment['primary_photo_id'] ?? 0))
        : 0;
    $primaryNewPhotoIndexValue = old('foto_principal_index', $isEditMode ? '' : $fieldValue('foto_principal_index', 0));

    if ($isEditMode && $existingPrimaryPhotoId <= 0 && $existingPhotos !== []) {
        $primaryExistingPhoto = collect($existingPhotos)->first(static fn (array $photo): bool => (bool) ($photo['is_principal'] ?? false));
        $existingPrimaryPhotoId = (int) (($primaryExistingPhoto['id'] ?? 0) ?: ($existingPhotos[0]['id'] ?? 0));
    }
@endphp

@section('styles')
    <link href="{{ asset('assets/libs/cropperjs/cropper.min.css') }}" rel="stylesheet">
@endsection

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Equipamentos</p>
            <h2 class="surface-title fs-3 mb-2">{{ $isEditMode ? 'Editar equipamento' : 'Novo equipamento' }}</h2>
            <p class="surface-subtitle mb-0">
                {{ $isEditMode
                    ? 'Atualização operacional com o mesmo formulário de cadastro, preservando fotos, catálogo e histórico do ativo.'
                    : 'Cadastro operacional com abas, coleta técnica assistida, cor, fotos e vínculo imediato ao cliente.' }}
            </p>
        </div>

        @unless ($embedded)
            <div class="d-flex flex-wrap gap-2 align-self-start">
                <a href="{{ route('equipments.help') }}" class="btn btn-outline-info">
                    <i class="bi bi-question-circle me-2"></i>
                    Ajuda
                </a>
                <a href="{{ $resolvedCancelUrl }}" class="btn btn-outline-light">
                    <i class="bi bi-arrow-left me-2"></i>
                    Voltar
                </a>
            </div>
        @endunless
    </div>

    <form
        method="post"
        action="{{ $resolvedFormAction }}"
        enctype="multipart/form-data"
        class="equipment-create-form"
        id="equipmentCreateForm"
        data-equipment-create
    >
        @csrf
        @if ($isEditMode)
            @method('PATCH')
        @endif

        <section class="desktop-form-card mb-4">
            <div class="surface-card-header align-items-start">
                <div>
                    <h3 class="surface-title mb-1">Cadastro operacional do equipamento</h3>
                    <p class="surface-subtitle mb-0">Use cliente, catálogo, senha, cor, mídia e apoio técnico remoto sem sair do fluxo.</p>
                </div>

                <span class="desktop-chip">
                    <i class="bi bi-camera"></i>
                    Até {{ $maxPhotos }} fotos
                </span>
            </div>

            <div class="equipment-tabs" role="tablist" aria-label="Etapas do cadastro">
                <button type="button" class="equipment-tab is-active" data-equipment-tab="informacoes" aria-pressed="true">
                    <i class="bi bi-info-circle"></i>
                    Informações
                </button>
                <button type="button" class="equipment-tab" data-equipment-tab="cor" aria-pressed="false">
                    <i class="bi bi-palette"></i>
                    Cor
                </button>
                <button type="button" class="equipment-tab" data-equipment-tab="fotos" aria-pressed="false">
                    <i class="bi bi-camera"></i>
                    Fotos *
                </button>
            </div>

            <div class="equipment-tab-panel is-active" data-equipment-panel="informacoes">
                <div class="desktop-filter-grid equipment-create-grid equipment-create-main-grid">
                    <div class="field-span-4 position-relative">
                        <label for="equipmentClientSelect">Cliente *</label>
                        <div class="equipment-inline-field">
                            <select
                                name="cliente_id"
                                id="equipmentClientSelect"
                                class="form-select"
                                data-select2-placeholder="Selecione ou busque um cliente..."
                                data-select2-allow-clear="true"
                            >
                                <option value=""></option>
                                @foreach ($clients as $client)
                                    @php
                                        $clientId = (string) ($client['id'] ?? '');
                                        $clientName = trim((string) ($client['nome_razao'] ?? ''));
                                        $clientDocument = trim((string) ($client['cpf_cnpj'] ?? ''));
                                        $clientPhone = trim((string) ($client['telefone1'] ?? ''));
                                        $clientAltPhone = trim((string) ($client['telefone2'] ?? ''));
                                        $clientContact = trim((string) ($client['nome_contato'] ?? ''));
                                        $clientContactPhone = trim((string) ($client['telefone_contato'] ?? ''));
                                        $clientEmail = trim((string) ($client['email'] ?? ''));
                                        $clientLabel = implode(' - ', array_values(array_filter([
                                            $clientName,
                                            $clientDocument,
                                            $clientPhone,
                                            $clientAltPhone,
                                            $clientContact !== '' ? 'Contato: ' . $clientContact : '',
                                            $clientContactPhone,
                                            $clientEmail,
                                        ])));
                                    @endphp
                                    <option value="{{ $clientId }}" @selected($selectedClientId === $clientId)>{{ $clientLabel }}</option>
                                @endforeach
                                @if ($selectedClientId !== '' && $selectedClientLabel !== '' && !collect($clients)->contains(fn (array $client) => (string) ($client['id'] ?? '') === $selectedClientId))
                                    <option value="{{ $selectedClientId }}" selected>{{ $selectedClientLabel }}</option>
                                @endif
                            </select>
                            <input type="hidden" name="cliente_busca_label" id="equipmentClientLabel" value="{{ $selectedClientLabel }}">
                            <button type="button" class="btn btn-soft equipment-inline-action" data-bs-toggle="modal" data-bs-target="#quickClientModal" title="Cadastrar novo cliente" aria-label="Cadastrar novo cliente" @disabled(!$canQuickClient)>
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                        @error('cliente_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label for="equipmentType">Tipo *</label>
                        <select name="tipo_id" id="equipmentType" class="form-select">
                            <option value="">Selecione o tipo...</option>
                            @foreach ($types as $type)
                                <option value="{{ $type['id'] }}" data-family="{{ $type['family'] ?? 'other' }}" @selected((string) $fieldValue('tipo_id') === (string) $type['id'])>
                                    {{ $type['nome'] }}
                                </option>
                            @endforeach
                        </select>
                        @error('tipo_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label for="equipmentBrand">Marca</label>
                        <div class="equipment-inline-field">
                            <select name="marca_id" id="equipmentBrand" class="form-select" @disabled($brandDisabled)>
                                <option value="">{{ $brandPlaceholder }}</option>
                                @foreach ($filteredBrands as $brand)
                                    <option value="{{ $brand['id'] }}" @selected((string) $fieldValue('marca_id') === (string) $brand['id'])>{{ $brand['nome'] }}</option>
                                @endforeach
                            </select>
                            <button type="button" id="quickBrandTrigger" class="btn btn-soft equipment-inline-action" data-bs-toggle="modal" data-bs-target="#quickBrandModal" title="Cadastrar nova marca" aria-label="Cadastrar nova marca" @disabled($quickBrandDisabled || !$canQuickCatalog)>
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                        @error('marca_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label for="equipmentModel">Modelo</label>
                        <div class="equipment-inline-field">
                            <select name="modelo_id" id="equipmentModel" class="form-select" @disabled($modelDisabled)>
                                <option value="">{{ $modelPlaceholder }}</option>
                                @foreach ($filteredModels as $model)
                                    <option value="{{ $model['id'] }}" data-brand-id="{{ $model['marca_id'] }}" @selected((string) $fieldValue('modelo_id') === (string) $model['id'])>{{ $model['nome'] }}</option>
                                @endforeach
                            </select>
                            <button type="button" id="quickModelTrigger" class="btn btn-soft equipment-inline-action" data-bs-toggle="modal" data-bs-target="#quickModelModal" title="Cadastrar novo modelo" aria-label="Cadastrar novo modelo" @disabled($quickModelDisabled || !$canQuickCatalog)>
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                        @error('modelo_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label for="equipmentSerial">Nº Série ou IMEI</label>
                        <input type="text" name="numero_serie_visual" id="equipmentSerial" class="form-control" value="{{ old('numero_serie_visual', $equipment['numero_serie'] ?? '') }}" placeholder="IMEI ou série (#*06#)">
                        @error('numero_serie_visual')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div class="field-span-4 equipment-password-row">
                        <label>Senha de acesso</label>
                        <input type="hidden" name="senha_tipo" id="equipmentPasswordMode" value="{{ $fieldValue('senha_tipo', 'desenho') }}">
                        <div class="equipment-password-toolbar">
                            <div class="equipment-password-mode">
                                <button type="button" class="equipment-password-toggle {{ $fieldValue('senha_tipo', 'desenho') === 'desenho' ? 'is-active' : '' }}" data-password-mode="desenho">Desenho</button>
                                <button type="button" class="equipment-password-toggle {{ $fieldValue('senha_tipo', 'desenho') === 'texto' ? 'is-active' : '' }}" data-password-mode="texto">Texto</button>
                            </div>
                            <span class="equipment-password-copy">Defina a senha visual ou textual sem sair do mesmo bloco operacional.</span>
                        </div>

                        <div class="equipment-password-panels">
                            <div class="equipment-password-panel {{ $fieldValue('senha_tipo', 'desenho') === 'desenho' ? 'is-active' : '' }}" data-password-panel="desenho">
                                <input type="hidden" name="senha_desenho" id="equipmentPasswordPattern" value="{{ $fieldValue('senha_desenho') }}">
                                <div class="equipment-password-actions">
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="equipmentPasswordPatternToggle">
                                        Mostrar desenho
                                    </button>
                                    <span class="text-muted small">Use o botão para exibir ou ocultar a grade de desenho.</span>
                                </div>
                                <div id="equipmentPasswordPatternWrapper" class="d-none">
                                    <div class="equipment-password-dots" id="equipmentPasswordDots">
                                        @for ($index = 1; $index <= 9; $index++)
                                            <button type="button" class="equipment-password-dot" data-pattern-node="{{ $index }}" aria-label="Ponto {{ $index }}"></button>
                                        @endfor
                                    </div>
                                    <div class="equipment-password-meta">
                                        <span id="equipmentPasswordPatternLabel">Nenhum desenho definido.</span>
                                        <button type="button" class="btn btn-outline-light btn-sm" id="equipmentPasswordPatternClear">Limpar desenho</button>
                                    </div>
                                </div>
                            </div>

                            <div class="equipment-password-panel {{ $fieldValue('senha_tipo', 'desenho') === 'texto' ? 'is-active' : '' }}" data-password-panel="texto">
                                <input type="text" name="senha_acesso" id="equipmentPasswordText" class="form-control equipment-password-text-input" value="{{ $fieldValue('senha_acesso') }}" placeholder="Informe a senha por texto">
                            </div>
                        </div>
                    </div>
                </div>

                @php
                    $collectorLinuxDownloadUrl = trim((string) ($formData['collector']['download_url_linux'] ?? ''));
                    $collectorWindowsDownloadUrl = trim((string) ($formData['collector']['download_url_windows'] ?? ''));
                @endphp
                <section class="equipment-collector-card mt-4" id="equipmentCollectorCard" aria-hidden="{{ $collectorVisible ? 'false' : 'true' }}" @unless($collectorVisible) hidden @endunless>
                    <div>
                        <p class="desktop-eyebrow mb-2">Coletor de hardware</p>
                        <h4 class="surface-title fs-5 mb-1">Importação técnica assistida</h4>
                        <p class="surface-subtitle mb-0">Gere um código, rode o coletor no computador do cliente e importe os dados de hardware direto no formulário.</p>
                    </div>

                    <div class="equipment-collector-group">
                        <p class="equipment-collector-group-title">Pareamento remoto (máquina do cliente)</p>
                        <p class="surface-subtitle mb-2">Gere um código, rode o coletor no computador do cliente (mesma rede local) e importe o snapshot recebido.</p>
                        <div class="equipment-collector-actions">
                            <div class="equipment-collector-code" id="collectorPairingDisplay">Nenhum código gerado</div>
                            <span class="equipment-collector-status" id="collectorPairingStatus" data-status="idle">Aguardando</span>
                            <button type="button" class="btn btn-outline-primary" id="collectorPairingCreate">
                                <i class="bi bi-link-45deg me-2"></i>
                                Gerar código
                            </button>
                            <button type="button" class="btn btn-outline-light" id="collectorPairingImport" disabled>
                                <i class="bi bi-download me-2"></i>
                                Importar snapshot
                            </button>
                        </div>
                        <div class="equipment-collector-command d-none" id="collectorPairingCommandWrapperWindows">
                            <label class="equipment-collector-command-label" for="collectorPairingCommandWindows">Comando para rodar na máquina do cliente (Windows)</label>
                            <div class="equipment-collector-command-row">
                                <code id="collectorPairingCommandWindows"></code>
                                <button type="button" class="btn btn-outline-light btn-sm" id="collectorPairingCommandCopyWindows" title="Copiar comando" aria-label="Copiar comando">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                            @if ($collectorWindowsDownloadUrl !== '')
                                <p class="surface-subtitle mb-0">Baixe o script em <a href="{{ $collectorWindowsDownloadUrl }}" target="_blank" rel="noopener">{{ $collectorWindowsDownloadUrl }}</a> e copie para o computador do cliente antes de rodar.</p>
                            @endif
                        </div>
                        <div class="equipment-collector-command d-none" id="collectorPairingCommandWrapperLinux">
                            <label class="equipment-collector-command-label" for="collectorPairingCommandLinux">Comando para rodar na máquina do cliente (Linux)</label>
                            <div class="equipment-collector-command-row">
                                <code id="collectorPairingCommandLinux"></code>
                                <button type="button" class="btn btn-outline-light btn-sm" id="collectorPairingCommandCopyLinux" title="Copiar comando" aria-label="Copiar comando">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                            @if ($collectorLinuxDownloadUrl !== '')
                                <p class="surface-subtitle mb-0">Baixe o script em <a href="{{ $collectorLinuxDownloadUrl }}" target="_blank" rel="noopener">{{ $collectorLinuxDownloadUrl }}</a> e copie para o computador do cliente antes de rodar.</p>
                            @endif
                        </div>
                        <input type="hidden" name="collector_pairing_code" id="equipmentCollectorPairingCode" value="">
                    </div>
                </section>

                <section class="equipment-technical-panel mt-4" id="equipmentTechnicalPanel">
                    <div class="surface-card-header align-items-start">
                        <div>
                            <h4 class="surface-title fs-5 mb-1">Painel técnico</h4>
                            <p class="surface-subtitle mb-0">Campos condicionais para desktop e notebook, mantendo resumo técnico coerente com o legado.</p>
                        </div>
                    </div>

                    <div class="desktop-filter-grid equipment-create-grid">
                        <div>
                            <label for="equipmentDesktopMode">Modalidade</label>
                            <select name="desktop_modalidade" id="equipmentDesktopMode" class="form-select" @disabled($desktopModeDisabled)>
                                <option value="">Selecione...</option>
                                <option value="montado" @selected($desktopModeValue === 'montado') @disabled(!$isMountedDesktopFamily)>Desktop montado</option>
                                <option value="oem" @selected($desktopModeValue === 'oem')>OEM / fabricante</option>
                            </select>
                            @if ($desktopModeDisabled)
                                <small class="text-muted">Notebook é sempre cadastrado como OEM / fabricante.</small>
                            @endif
                        </div>

                        <div>
                            <label for="equipmentCaseType">Tipo do gabinete</label>
                            <input type="text" name="gabinete_tipo" id="equipmentCaseType" class="form-control" value="{{ $fieldValue('gabinete_tipo') }}" placeholder="Ex.: Mid Tower">
                        </div>

                        <div>
                            <label for="equipmentCaseStatus">Status da identificação</label>
                            <select name="gabinete_identificacao_status" id="equipmentCaseStatus" class="form-select">
                                <option value="a_confirmar" @selected($fieldValue('gabinete_identificacao_status', 'a_confirmar') === 'a_confirmar')>A confirmar</option>
                                <option value="manual" @selected($fieldValue('gabinete_identificacao_status') === 'manual')>Manual</option>
                                <option value="detectado" @selected($fieldValue('gabinete_identificacao_status') === 'detectado')>Detectado</option>
                            </select>
                        </div>

                        <div class="field-span-2">
                            <label for="equipmentCaseNotes">Observação do gabinete</label>
                            <textarea name="gabinete_observacao" id="equipmentCaseNotes" class="form-control" rows="2" placeholder="Informações úteis sobre gabinete, carcaça ou montagem">{{ $fieldValue('gabinete_observacao') }}</textarea>
                        </div>

                        <div><label for="equipmentMotherboard">Placa mãe</label><input type="text" name="placa_mae" id="equipmentMotherboard" class="form-control" value="{{ $fieldValue('placa_mae') }}"></div>
                        <div><label for="equipmentChipset">Chipset</label><input type="text" name="chipset" id="equipmentChipset" class="form-control" value="{{ $fieldValue('chipset') }}"></div>
                        <div><label for="equipmentCpu">Processador</label><input type="text" name="processador" id="equipmentCpu" class="form-control" value="{{ $fieldValue('processador') }}"></div>
                        <div><label for="equipmentRam">Memória RAM</label><input type="text" name="memoria_ram" id="equipmentRam" class="form-control" value="{{ $fieldValue('memoria_ram') }}"></div>
                        <div><label for="equipmentStorage">Armazenamento</label><input type="text" name="armazenamento" id="equipmentStorage" class="form-control" value="{{ $fieldValue('armazenamento') }}"></div>
                        <div><label for="equipmentGpu">Placa de vídeo</label><input type="text" name="placa_video" id="equipmentGpu" class="form-control" value="{{ $fieldValue('placa_video') }}"></div>
                        <div class="field-span-2"><label for="equipmentPowerSupply">Fonte de alimentação</label><input type="text" name="fonte_alimentacao" id="equipmentPowerSupply" class="form-control" value="{{ $fieldValue('fonte_alimentacao') }}"></div>
                    </div>
                </section>

                <div class="desktop-filter-grid equipment-create-grid mt-4">
                    <div class="field-span-2">
                        <label for="equipmentAccessories">Acessórios</label>
                        <textarea name="acessorios" id="equipmentAccessories" class="form-control" rows="2" placeholder="Ex.: carregador, mouse, cabo HDMI">{{ $fieldValue('acessorios') }}</textarea>
                        <div class="equipment-chip-preset mt-2">
                            @foreach (['Carregador', 'Fonte', 'Mouse', 'Teclado', 'Cabo HDMI', 'Bolsa'] as $preset)
                                <button type="button" class="equipment-chip-button" data-fill-target="equipmentAccessories" data-fill-value="{{ $preset }}">{{ $preset }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="field-span-2">
                        <label for="equipmentPhysicalState">Estado físico</label>
                        <textarea name="estado_fisico" id="equipmentPhysicalState" class="form-control" rows="3" placeholder="Descreva avarias, faltas de peças, riscos ou estado geral">{{ $fieldValue('estado_fisico') }}</textarea>
                    </div>

                    <div class="field-span-4">
                        <label for="equipmentNotes">Observações</label>
                        <textarea name="observacoes" id="equipmentNotes" class="form-control" rows="4" placeholder="Informações livres para recepção, bancada e histórico operacional">{{ $fieldValue('observacoes') }}</textarea>
                    </div>
                </div>
            </div>

            <div class="equipment-tab-panel" data-equipment-panel="cor">
                <div class="equipment-color-grid">
                    <div class="equipment-color-preview-card">
                        <div class="equipment-color-preview" id="equipmentColorPreview" style="background: {{ $fieldValue('cor_hex', '#64748b') }};"></div>
                        <strong id="equipmentColorNameLabel">{{ $fieldValue('cor', 'Sem cor definida') }}</strong>
                        <small id="equipmentColorRgbLabel">{{ $fieldValue('cor_rgb', '100, 116, 139') }}</small>
                    </div>

                    <div class="desktop-filter-grid equipment-create-grid">
                        <div>
                            <label for="equipmentColorName">Nome da cor</label>
                            <input type="text" name="cor" id="equipmentColorName" class="form-control" value="{{ $fieldValue('cor') }}" placeholder="Ex.: Preto fosco">
                        </div>
                        <div>
                            <label for="equipmentColorHex">Cor principal</label>
                            <input type="color" name="cor_hex" id="equipmentColorHex" class="form-control form-control-color equipment-color-input" value="{{ $fieldValue('cor_hex', '#64748b') }}">
                        </div>
                        <input type="hidden" name="cor_rgb" id="equipmentColorRgb" value="{{ $fieldValue('cor_rgb', '100, 116, 139') }}">

                        <div class="field-span-2">
                            <label>Sugestões rápidas</label>
                            <div class="equipment-color-swatches">
                                @foreach ([
                                    ['Preto', '#111827'],
                                    ['Prata', '#cbd5e1'],
                                    ['Branco', '#f8fafc'],
                                    ['Azul', '#2563eb'],
                                    ['Vermelho', '#ef4444'],
                                    ['Dourado', '#f59e0b'],
                                ] as [$name, $hex])
                                    <button type="button" class="equipment-color-swatch" data-color-name="{{ $name }}" data-color-hex="{{ $hex }}">
                                        <span style="background: {{ $hex }}"></span>
                                        {{ $name }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="equipment-tab-panel" data-equipment-panel="fotos">
                <input type="hidden" name="foto_principal_index" id="equipmentPrimaryPhotoIndex" value="{{ $primaryNewPhotoIndexValue }}">
                @if ($isEditMode)
                    <input type="hidden" name="existing_photo_sync" id="equipmentExistingPhotoSync" value="1">
                    <input type="hidden" name="foto_principal_existente_id" id="equipmentPrimaryExistingPhotoId" value="{{ $existingPrimaryPhotoId > 0 ? $existingPrimaryPhotoId : '' }}">
                    <div id="equipmentExistingPhotoIdsContainer" class="d-none">
                        @foreach ($existingPhotos as $photo)
                            <input type="hidden" name="existing_photo_ids[]" value="{{ (int) ($photo['id'] ?? 0) }}">
                        @endforeach
                    </div>
                @endif
                <input type="file" name="fotos[]" id="equipmentPhotosInput" class="d-none" accept="image/png,image/jpeg,image/webp" multiple>

                <div class="equipment-photo-toolbar">
                    <button type="button" class="btn btn-primary" id="equipmentPhotoGalleryButton">
                        <i class="bi bi-images me-2"></i>
                        Adicionar da galeria
                    </button>
                    <button type="button" class="btn btn-outline-primary" id="equipmentPhotoCameraButton">
                        <i class="bi bi-camera me-2"></i>
                        Capturar com câmera
                    </button>
                    <span class="surface-subtitle mb-0">Limite de {{ $maxPhotos }} fotos com definição da principal no preview.</span>
                </div>

                <div class="equipment-photo-required-note">
                    {{ $isEditMode
                        ? 'A foto principal acompanha o equipamento ate o fim do ciclo de vida. Voce pode substituir, remover ou redefinir a principal sem sair desta tela.'
                        : 'A foto principal e obrigatoria no cadastro inicial e permanece vinculada ao equipamento durante todo o ciclo de vida.' }}
                </div>

                @error('fotos')<div class="invalid-feedback d-block mt-2">{{ $message }}</div>@enderror
                @error('fotos.*')<div class="invalid-feedback d-block mt-2">{{ $message }}</div>@enderror

                <div class="equipment-photo-grid" id="equipmentPhotoGrid">
                    <div class="equipment-photo-empty">
                        <i class="bi bi-camera"></i>
                        <strong>Nenhuma foto adicionada</strong>
                        <span>Use galeria ou câmera. O preview local define a foto principal antes do envio.</span>
                    </div>
                </div>
            </div>

            <div class="surface-card-actions mt-4">
                @if ($embedded)
                    <button
                        type="button"
                        class="btn btn-outline-light"
                        data-equipment-embedded-cancel
                    >
                        Cancelar
                    </button>
                @else
                    <a href="{{ $resolvedCancelUrl }}" class="btn btn-outline-light">Cancelar</a>
                @endif
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-2"></i>
                    {{ $resolvedSubmitLabel }}
                </button>
            </div>
        </section>
    </form>
@endsection

@section('scripts')
    <script src="{{ asset('assets/libs/cropperjs/cropper.min.js') }}"></script>
    <script>
        window.__EQUIPMENT_CREATE = {!! \Illuminate\Support\Js::from([
            'isEdit' => $isEditMode,
            'maxPhotos' => $maxPhotos,
            'existingPhotos' => $existingPhotos,
            'formData' => [
                'clients' => $clients,
                'types' => $types,
                'brands' => $brands,
                'models' => $models,
                'catalog_relations' => $catalogRelations,
                'desktop_defaults' => $desktopDefaults,
                'collector' => $formData['collector'] ?? [],
            ],
            'routes' => [
                'quickClient' => route('clients.quick.store'),
                'quickBrand' => route('equipments.brands.quick.store'),
                'quickModel' => route('equipments.models.quick.store'),
                'suggestModels' => route('equipments.models.suggestions'),
                'createPairing' => route('equipments.collector-pairings.store'),
                'getPairing' => route('equipments.collector-pairings.show', ['code' => '__CODE__']),
            ],
        ]) !!};
    </script>
    <script src="{{ asset('assets/js/equipments-create.js') }}?v={{ filemtime(public_path('assets/js/equipments-create.js')) }}"></script>
    <script src="{{ asset('assets/js/clients-form.js') }}?v={{ filemtime(public_path('assets/js/clients-form.js')) }}"></script>
@endsection

@push('modals')
    @include('layouts.partials.photo-viewer-modal')

    @include('clients.quick-modal', [
        'fullCreateUrl' => route('clients.create'),
    ])

    <div class="modal fade" id="quickBrandModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-shell">
                <div class="modal-header">
                    <h5 class="modal-title">Nova marca</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <label for="quickBrandName">Nome da marca *</label>
                    <input type="text" id="quickBrandName" class="form-control" placeholder="Ex.: Lenovo">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="quickBrandSubmit">Salvar marca</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="quickModelModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content modal-shell">
                <div class="modal-header">
                    <h5 class="modal-title">Novo modelo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="desktop-filter-grid equipment-create-grid">
                        <div>
                            <label for="quickModelBrand">Marca *</label>
                            <select id="quickModelBrand" class="form-select" @disabled($quickModelDisabled)>
                                <option value="">{{ $quickModelBrandPlaceholder }}</option>
                                @foreach ($filteredBrands as $brand)
                                    <option value="{{ $brand['id'] }}">{{ $brand['nome'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="quickModelName">Nome do modelo *</label>
                            <input type="text" id="quickModelName" class="form-control" placeholder="Ex.: Inspiron 15">
                        </div>
                    </div>

                    <div class="equipment-suggestion-panel mt-4">
                        <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center">
                            <div>
                                <strong>Sugestões externas</strong>
                                <p class="surface-subtitle mb-0">Use a busca externa para encontrar nomes comuns de mercado sem travar o cadastro local.</p>
                            </div>
                            <button type="button" class="btn btn-outline-primary" id="quickModelSuggest">
                                <i class="bi bi-stars me-2"></i>
                                Buscar sugestões
                            </button>
                        </div>
                        <div id="quickModelSuggestions" class="equipment-suggestion-results mt-3"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="quickModelSubmit">Salvar modelo</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="equipmentCameraModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content modal-shell">
                <div class="modal-header">
                    <h5 class="modal-title">Capturar foto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <video id="equipmentCameraVideo" class="equipment-camera-video" autoplay playsinline muted></video>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="equipmentCameraCapture">Capturar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="equipmentCropModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content modal-shell">
                <div class="modal-header">
                    <h5 class="modal-title">Ajustar foto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <img src="" alt="Preview para recorte" id="equipmentCropImage" class="equipment-crop-image">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="equipmentCropConfirm">Usar foto</button>
                </div>
            </div>
        </div>
    </div>
@endpush
