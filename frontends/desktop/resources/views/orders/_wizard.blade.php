@php
    $order = is_array($order ?? null) ? $order : [];
    $orderId = (int) data_get($order, 'id', 0);
    $isEditing = $orderId > 0;
    $orderNumberLabel = trim((string) data_get($order, 'numero_os', ''));

    $selectedClientId = (int) old('cliente_id', $selectedClientId ?? 0);
    $selectedEquipmentId = (int) old('equipamento_id', $selectedEquipmentId ?? 0);
    $selectedTechnicianId = (int) old('tecnico_id', $selectedTechnicianId ?? 0);
    $selectedPriority = (string) old('prioridade', data_get($order, 'prioridade', 'normal'));
    $selectedClient = is_array($selectedClient ?? null) ? $selectedClient : [];
    $selectedEquipment = is_array($selectedEquipment ?? null) ? $selectedEquipment : [];
    $selectedClientName = trim((string) data_get($selectedClient, 'nome_razao', ''));
    $selectedClientLabel = $selectedClientName !== ''
        ? $selectedClientName
        : ($selectedClientId > 0 ? 'Cliente #' . $selectedClientId : '');
    $selectedClientPhone = trim((string) data_get($selectedClient, 'telefone1', data_get($selectedClient, 'telefone_contato', '')));
    $selectedClientEmail = trim((string) data_get($selectedClient, 'email', ''));
    $selectedClientCity = trim((string) data_get($selectedClient, 'cidade', ''));
    $selectedClientUf = trim((string) data_get($selectedClient, 'uf', ''));
    $selectedEquipmentLabel = trim((string) data_get($selectedEquipment, 'resumo_tecnico', ''));
    $selectedEquipmentBrandName = trim((string) data_get($selectedEquipment, 'marca_nome', data_get($selectedEquipment, 'brand.nome', '')));
    $selectedEquipmentModelName = trim((string) data_get($selectedEquipment, 'modelo_nome', data_get($selectedEquipment, 'model.nome', '')));
    $selectedEquipmentBrandModel = trim(implode(' / ', array_filter([
        $selectedEquipmentBrandName,
        $selectedEquipmentModelName,
    ], static fn (string $value): bool => $value !== '')));
    $selectedEquipmentLabelDisplay = $selectedEquipmentLabel !== ''
        ? $selectedEquipmentLabel
        : ($selectedEquipmentBrandModel !== ''
            ? $selectedEquipmentBrandModel
            : ($selectedEquipmentId > 0 ? 'Equipamento #' . $selectedEquipmentId : ''));
    $selectedEquipmentSerial = trim((string) data_get($selectedEquipment, 'numero_serie', ''));
    $selectedEquipmentClientId = (int) data_get($selectedEquipment, 'cliente_id', 0);
    $selectedEquipmentClientName = trim((string) data_get(
        $selectedEquipment,
        'cliente_nome',
        data_get($selectedEquipment, 'client.nome_razao', '')
    ));
    $selectedEquipmentPhotoUrl = trim((string) data_get(
        $selectedEquipment,
        'primary_photo_url',
        data_get($selectedEquipment, 'photos.0.url', '')
    ));
    $selectedEquipmentTipoId = (int) data_get($selectedEquipment, 'tipo_id', 0);
    $selectedEquipmentTipoNome = trim((string) data_get($selectedEquipment, 'tipo_nome', ''));
    $selectedTechnicianName = '';
    $selectedTechnicianEmail = '';
    $canEditClient = \App\Support\DesktopSession::can('clientes', 'editar');
    $canEditEquipment = \App\Support\DesktopSession::can('equipamentos', 'editar');
    $canCreateEquipment = \App\Support\DesktopSession::can('equipamentos', 'criar');
    $selectedClientEditUrl = $canEditClient && $selectedClientId > 0
        ? route('clients.edit', $selectedClientId)
        : '';
    $selectedEquipmentEditUrl = $canEditEquipment && $selectedEquipmentId > 0
        ? route('equipments.edit', $selectedEquipmentId)
        : '';

    foreach (($technicians ?? []) as $technician) {
        if ((int) ($technician['id'] ?? 0) === $selectedTechnicianId) {
            $selectedTechnicianName = trim((string) ($technician['nome'] ?? ''));
            $selectedTechnicianEmail = trim((string) ($technician['email'] ?? ''));
            break;
        }
    }

    $canCreateClient = \App\Support\DesktopSession::can('clientes', 'criar');
    $selectedRelato = trim((string) old('relato_cliente', data_get($order, 'relato_cliente', '')));
    $selectedOperacionaisNote = trim((string) old('observacoes_internas', data_get($order, 'observacoes_internas', '')));
    $selectedPrevisao = trim((string) old('data_previsao', data_get($order, 'data_previsao', '')));

    $orderTitle = $isEditing
        ? 'Editar OS ' . ($orderNumberLabel !== '' ? $orderNumberLabel : ('#' . $orderId))
        : 'Nova OS';
    $orderSubtitle = $isEditing
        ? ''
        : '';
    $submitLabel = $isEditing ? 'Salvar alteracoes' : 'Criar OS';
    $submitIcon = $isEditing ? 'bi-check2-circle' : 'bi-clipboard-plus';
    $cancelUrl = $isEditing ? route('orders.show', $orderId) : route('orders.index');
    $statusLabel = $isEditing
        ? (trim((string) data_get($order, 'status_nome', '')) !== '' ? trim((string) data_get($order, 'status_nome', '')) : 'Status atual')
        : 'Triagem ao salvar';
    $entradaLabel = $isEditing
        ? (trim((string) data_get($order, 'data_entrada', '')) !== '' ? trim((string) data_get($order, 'data_entrada', '')) : 'Nao informada')
        : now()->format('d/m/Y, H:i');
    $existingPhotos = $isEditing ? (array) data_get($order, 'fotos', []) : [];
    $existingPhotosCount = count($existingPhotos);
    $entryChecklist = is_array(data_get($order, 'checklist')) ? (array) data_get($order, 'checklist') : [];
    $entryChecklistModel = is_array($entryChecklistModel ?? null) ? $entryChecklistModel : [];
    $entryChecklistResponses = (array) data_get($entryChecklist, 'respostas', []);
    $entryChecklistDiscrepancies = (int) data_get($entryChecklist, 'total_discrepancias', 0);
    $entryChecklistItemsCount = count((array) data_get($entryChecklistModel, 'itens', []));
    $statusDisponiveis = $isEditing ? (array) data_get($order, 'status_disponiveis', []) : [];
    // Regra de projeto (skill sistema-erp-os-fluxo-fechamento): os status de
    // encerramento (grupo_macro = 'encerrado') so podem ser aplicados pela tela
    // de baixa da OS, nunca por este modal de "Atualizar status".
    $statusDisponiveis = array_values(array_filter(
        $statusDisponiveis,
        static fn ($statusOption): bool => trim((string) ($statusOption['grupo_macro'] ?? '')) !== 'encerrado'
    ));
    $currentStatusCode = trim((string) data_get($order, 'status', ''));
    // OS encerrada: mudança de status bloqueada por completo aqui (só
    // "Cancelar baixa", na tela de detalhe, pode tirá-la desse estado).
    $isEncerrada = (bool) data_get($order, 'is_encerrada', false);
    $canChangeStatus = $isEditing && \App\Support\DesktopSession::can('os', 'editar') && $statusDisponiveis !== [] && ! $isEncerrada;
@endphp

<section class="desktop-form-card order-create-shell">
    <div class="surface-card-header order-create-header">
        <div>
            <h2 class="surface-title">{{ $orderTitle }}</h2>
            @if ($orderSubtitle !== '')
                <p class="surface-subtitle">{{ $orderSubtitle }}</p>
            @endif
        </div>
    </div>

    <form
        method="post"
        action="{{ $isEditing ? route('orders.update', $orderId) : route('orders.store') }}"
        enctype="multipart/form-data"
        class="desktop-grid order-create-layout"
        data-order-create-form
    >
        @csrf
        @if ($isEditing)
            @method('PATCH')
        @endif

        <aside class="order-create-preview">
            <article class="summary-card order-create-summary-card">
                <span class="summary-card-eyebrow">Resumo da OS</span>

                <ul class="order-create-summary-list">
                    <li class="order-create-summary-row" title="{{ $selectedClientPhone !== '' || $selectedClientEmail !== '' ? trim($selectedClientPhone . ' ' . $selectedClientEmail) : '' }}">
                        <span class="order-create-summary-row-label">Cliente</span>
                        <span class="order-create-summary-row-value">
                            <span class="order-create-summary-row-text" data-order-create-summary-client>{{ $selectedClientName !== '' ? $selectedClientName : 'Nao selecionado' }}</span>
                            @if ($canEditClient)
                                <a
                                    href="{{ $selectedClientEditUrl !== '' ? $selectedClientEditUrl : '#' }}"
                                    class="order-create-summary-row-edit {{ $selectedClientEditUrl !== '' ? '' : 'd-none' }}"
                                    data-order-create-client-edit-link
                                    data-client-edit-url-template="{{ route('clients.edit', ['client' => '__CLIENT_ID__']) }}"
                                    aria-label="Editar cliente"
                                    title="Editar cliente"
                                >
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                            @endif
                            <i class="bi {{ $selectedClientName !== '' ? 'bi-check-circle-fill is-complete' : 'bi-x-circle-fill is-pending' }} order-create-summary-row-icon" data-order-create-summary-client-icon></i>
                        </span>
                    </li>

                    <li class="order-create-summary-row" title="{{ $selectedEquipmentSerial !== '' ? 'S/N ' . $selectedEquipmentSerial : '' }}">
                        <span class="order-create-summary-row-label">Equipamento</span>
                        <span class="order-create-summary-row-value">
                            <span class="order-create-summary-row-text" data-order-create-summary-equipment>{{ $selectedEquipmentLabelDisplay !== '' ? $selectedEquipmentLabelDisplay : 'Nao selecionado' }}</span>
                            @if ($canEditEquipment)
                                <a
                                    href="{{ $selectedEquipmentEditUrl !== '' ? $selectedEquipmentEditUrl : '#' }}"
                                    class="order-create-summary-row-edit {{ $selectedEquipmentEditUrl !== '' ? '' : 'd-none' }}"
                                    data-order-create-equipment-edit-link
                                    data-equipment-edit-url-template="{{ route('equipments.edit', ['equipment' => '__EQUIPMENT_ID__']) }}"
                                    aria-label="Editar equipamento"
                                    title="Editar equipamento"
                                >
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                            @endif
                            <i class="bi {{ $selectedEquipmentLabelDisplay !== '' ? 'bi-check-circle-fill is-complete' : 'bi-x-circle-fill is-pending' }} order-create-summary-row-icon" data-order-create-summary-equipment-icon></i>
                        </span>
                    </li>

                    <li class="order-create-summary-row" title="{{ $selectedTechnicianEmail }}">
                        <span class="order-create-summary-row-label">Tecnico</span>
                        <span class="order-create-summary-row-value">
                            <span class="order-create-summary-row-text" data-order-create-summary-technician>{{ $selectedTechnicianName !== '' ? $selectedTechnicianName : 'Nao atribuido' }}</span>
                            <i class="bi {{ $selectedTechnicianName !== '' ? 'bi-check-circle-fill is-complete' : 'bi-x-circle-fill is-pending' }} order-create-summary-row-icon" data-order-create-summary-technician-icon></i>
                        </span>
                    </li>

                    <li class="order-create-summary-row">
                        <span class="order-create-summary-row-label">Prioridade</span>
                        <span class="order-create-summary-row-value">
                            <span class="status-pill status-pill-sm" data-order-create-summary-priority>{{ ucfirst(str_replace('_', ' ', $selectedPriority !== '' ? $selectedPriority : 'normal')) }}</span>
                            <i class="bi bi-check-circle-fill order-create-summary-row-icon is-complete"></i>
                        </span>
                    </li>

                    <li class="order-create-summary-row">
                        <span class="order-create-summary-row-label">Status</span>
                        <span class="order-create-summary-row-value">
                            <span class="status-pill status-pill-sm" data-order-create-summary-status>{{ $statusLabel }}</span>
                            <i class="bi bi-check-circle-fill order-create-summary-row-icon is-complete"></i>
                        </span>
                    </li>

                    <li class="order-create-summary-row">
                        <span class="order-create-summary-row-label">Entrada</span>
                        <span class="order-create-summary-row-value">
                            <span class="order-create-summary-row-text">{{ $entradaLabel }}</span>
                            <i class="bi bi-check-circle-fill order-create-summary-row-icon is-complete"></i>
                        </span>
                    </li>

                    <li class="order-create-summary-row">
                        <span class="order-create-summary-row-label">Previsao</span>
                        <span class="order-create-summary-row-value">
                            <span class="order-create-summary-row-text" data-order-create-summary-previsao>{{ $selectedPrevisao !== '' ? $selectedPrevisao : '-' }}</span>
                            <i class="bi {{ $selectedPrevisao !== '' ? 'bi-check-circle-fill is-complete' : 'bi-x-circle-fill is-pending' }} order-create-summary-row-icon" data-order-create-summary-previsao-icon></i>
                        </span>
                    </li>

                    <li class="order-create-summary-row" title="{{ $selectedRelato !== '' ? $selectedRelato : '' }}">
                        <span class="order-create-summary-row-label">Relato</span>
                        <span class="order-create-summary-row-value">
                            <span class="order-create-summary-row-text" data-order-create-summary-relato>{{ $selectedRelato !== '' ? \Illuminate\Support\Str::limit($selectedRelato, 28) : 'Vazio' }}</span>
                            <i class="bi {{ $selectedRelato !== '' ? 'bi-check-circle-fill is-complete' : 'bi-x-circle-fill is-pending' }} order-create-summary-row-icon" data-order-create-summary-relato-icon></i>
                        </span>
                    </li>

                    <li class="order-create-summary-row">
                        <span class="order-create-summary-row-label">Fotos</span>
                        <span class="order-create-summary-row-value">
                            <span class="order-create-summary-row-text"><span data-order-create-summary-photos>{{ $existingPhotosCount }}</span> fotos</span>
                            <i class="bi {{ $existingPhotosCount > 0 ? 'bi-check-circle-fill is-complete' : 'bi-x-circle-fill is-pending' }} order-create-summary-row-icon" data-order-create-summary-photos-icon></i>
                        </span>
                    </li>

                    <li class="order-create-summary-row">
                        <span class="order-create-summary-row-label">Checklist</span>
                        <span class="order-create-summary-row-value">
                            <span class="order-create-summary-row-text" data-order-create-summary-checklist>
                                {{ $entryChecklistItemsCount > 0 ? ($entryChecklistItemsCount . ' itens') : 'Nao definido' }}
                            </span>
                            <i class="bi {{ $entryChecklistItemsCount > 0 ? 'bi-check-circle-fill is-complete' : 'bi-x-circle-fill is-pending' }} order-create-summary-row-icon" data-order-create-summary-checklist-icon></i>
                        </span>
                    </li>
                </ul>
            </article>

            <article class="surface-card order-create-photo-card">
                <div class="surface-card-header">
                    <div>
                        <h3 class="surface-title">Foto do equipamento</h3>
                        <p class="surface-subtitle">Contexto visual da OS enquanto os dados vao sendo preenchidos.</p>
                    </div>
                </div>

                <div class="order-create-photo-frame {{ $selectedEquipmentPhotoUrl !== '' ? 'has-photo' : '' }}" data-order-create-photo-frame>
                            <img
                                src="{{ $selectedEquipmentPhotoUrl !== '' ? $selectedEquipmentPhotoUrl : '' }}"
                                alt="Foto do equipamento selecionado"
                                class="order-create-photo-image {{ $selectedEquipmentPhotoUrl === '' ? 'd-none' : '' }}"
                                data-order-create-main-photo
                    >

                    <div class="order-create-photo-placeholder {{ $selectedEquipmentPhotoUrl !== '' ? 'd-none' : '' }}" data-order-create-main-photo-placeholder>
                        <i class="bi bi-image"></i>
                        <strong>Selecione um equipamento</strong>
                        <span>O preview principal aparece aqui assim que houver contexto.</span>
                    </div>
                </div>
            </article>
        </aside>

        <div class="order-create-workbench">
            <div class="equipment-tabs order-create-tabs" role="tablist" aria-label="Etapas da OS">
                <button type="button" class="equipment-tab is-active" data-order-tab="cliente">Cliente</button>
                <button type="button" class="equipment-tab" data-order-tab="equipamento">Equipamento</button>
                <button type="button" class="equipment-tab" data-order-tab="defeito">Defeito</button>
                <button type="button" class="equipment-tab" data-order-tab="checklist">Checklist de entrada</button>
                <button type="button" class="equipment-tab" data-order-tab="operacionais">Dados Operacionais</button>
                <button type="button" class="equipment-tab" data-order-tab="fotos">Fotos</button>
            </div>

            <section class="equipment-tab-panel is-active" data-order-tab-panel="cliente">
                <div class="order-create-panel-grid">
                    <div class="order-create-field order-create-field-span-2">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                            <label for="clienteId" class="mb-0">Cliente</label>
                            @if ($canCreateClient)
                                <button type="button" id="btnNovoClienteRapido" class="btn btn-soft btn-sm">
                                    <i class="bi bi-person-plus me-1"></i>
                                    Novo cliente
                                </button>
                            @endif
                        </div>

                        <select
                            id="clienteId"
                            name="cliente_id"
                            class="form-select @error('cliente_id') is-invalid @enderror"
                            required
                            data-order-create-client-select
                            data-select2-placeholder="Selecione o cliente"
                            data-native-select="true"
                            data-order-create-client-search-url="{{ route('orders.clients.search') }}"
                        >
                            <option value="">Selecione</option>
                            @if ($selectedClientId > 0)
                                <option
                                    value="{{ $selectedClientId }}"
                                    selected
                                    data-client-name="{{ $selectedClientLabel }}"
                                    data-client-phone="{{ $selectedClientPhone }}"
                                    data-client-email="{{ $selectedClientEmail }}"
                                    data-client-contact="{{ trim((string) data_get($selectedClient, 'nome_contato', '')) }}"
                                    data-client-city="{{ $selectedClientCity }}"
                                    data-client-uf="{{ $selectedClientUf }}"
                                >
                                    {{ $selectedClientLabel !== '' ? $selectedClientLabel : 'Cliente #' . $selectedClientId }}
                                </option>
                            @endif
                        </select>
                        @error('cliente_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @if ($canCreateClient)
                            <small class="text-secondary d-block mt-2">Se o cliente ainda nao estiver cadastrado, abra o cadastro rapido sem sair da OS.</small>
                        @endif
                    </div>
                </div>
            </section>

            <section class="equipment-tab-panel" data-order-tab-panel="equipamento">
                <div class="order-create-panel-grid">
                    <div class="order-create-field order-create-field-span-2">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                            <label for="equipamentoId" class="mb-0">Equipamento</label>
                            @if ($canCreateEquipment)
                                <button
                                    type="button"
                                    class="btn btn-soft btn-sm"
                                    data-bs-toggle="modal"
                                    data-bs-target="#quickEquipmentModal"
                                >
                                    <i class="bi bi-plus-lg me-1"></i>
                                    Novo equipamento
                                </button>
                            @endif
                        </div>
                        <select
                            id="equipamentoId"
                            name="equipamento_id"
                            class="form-select @error('equipamento_id') is-invalid @enderror"
                            required
                            data-native-select="true"
                            data-order-create-equipment-select
                            data-select2-placeholder="Selecione o equipamento"
                            data-select2-allow-clear="true"
                            data-order-create-equipment-search-url="{{ route('orders.equipments.search') }}"
                        >
                            <option value="">Selecione</option>
                            @if ($selectedEquipmentId > 0)
                                <option
                                    value="{{ $selectedEquipmentId }}"
                                    selected
                                    data-client-id="{{ $selectedEquipmentClientId }}"
                                    data-client-name="{{ $selectedEquipmentClientName }}"
                                    data-equipment-summary="{{ $selectedEquipmentLabel }}"
                                    data-equipment-brand-name="{{ $selectedEquipmentBrandName }}"
                                    data-equipment-model-name="{{ $selectedEquipmentModelName }}"
                                    data-equipment-serial="{{ $selectedEquipmentSerial }}"
                                    data-equipment-photo-url="{{ $selectedEquipmentPhotoUrl }}"
                                    data-equipment-tipo-id="{{ $selectedEquipmentTipoId }}"
                                    data-equipment-tipo-name="{{ $selectedEquipmentTipoNome }}"
                                >
                                    {{ $selectedEquipmentLabelDisplay !== '' ? $selectedEquipmentLabelDisplay : 'Equipamento #' . $selectedEquipmentId }}
                                </option>
                            @endif
                        </select>
                        @error('equipamento_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        <small class="text-secondary d-block mt-2">Busque por nome, serie ou cliente. Se o equipamento nao estiver cadastrado, abra o modal de cadastro sem sair da OS.</small>
                    </div>

                    <div class="order-create-field order-create-field-span-2">
                        <label for="observacoesInternas">Observacoes internas</label>
                        <textarea
                            id="observacoesInternas"
                            name="observacoes_internas"
                            class="form-control @error('observacoes_internas') is-invalid @enderror"
                            rows="4"
                            placeholder="Notas internas opcionais"
                            data-order-create-observacoes
                        >{{ $selectedOperacionaisNote }}</textarea>
                        @error('observacoes_internas')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                </div>
            </section>

            <section class="equipment-tab-panel" data-order-tab-panel="defeito">
                <div class="order-create-panel-grid">
                    <div class="order-create-field order-create-field-span-2">
                        <label class="mb-1">Defeitos comuns deste equipamento</label>
                        <div class="d-flex flex-wrap gap-2" data-order-create-defect-suggestions>
                            <span class="text-secondary small">Selecione o equipamento para ver sugestões de defeitos.</span>
                        </div>
                        <small class="text-secondary d-block mt-2">Clique em um item para inserir o relato padronizado abaixo.</small>
                    </div>

                    <div class="order-create-field order-create-field-span-2">
                        <label for="relatoCliente">Relato do cliente</label>
                        <textarea
                            id="relatoCliente"
                            name="relato_cliente"
                            class="form-control @error('relato_cliente') is-invalid @enderror"
                            rows="6"
                            required
                            placeholder="Descreva o problema relatado pelo cliente"
                            data-order-create-relato
                        >{{ $selectedRelato }}</textarea>
                        @error('relato_cliente')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                </div>
            </section>

            <section class="equipment-tab-panel" data-order-tab-panel="checklist">
                <div
                    class="order-entry-checklist"
                    data-order-entry-checklist
                    data-checklist-model='@json($entryChecklistModel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)'
                    data-checklist-responses='@json($entryChecklistResponses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)'
                    data-checklist-discrepancies="{{ $entryChecklistDiscrepancies }}"
                >
                    <div class="order-create-panel-note" data-order-entry-checklist-empty>
                        <strong>Checklist de entrada</strong>
                        <p>Selecione um equipamento para carregar o modelo de checklist configurado para o tipo do equipamento.</p>
                    </div>

                    <div class="order-entry-checklist-content d-none" data-order-entry-checklist-content>
                        <div class="order-entry-checklist-header">
                            <div>
                                <h3 class="surface-title mb-1" data-order-entry-checklist-title>Checklist de entrada</h3>
                                <p class="surface-subtitle mb-0" data-order-entry-checklist-description>Conferência inicial do equipamento recebido.</p>
                            </div>
                            <span class="desktop-chip" data-order-entry-checklist-count>0 itens</span>
                        </div>

                        <div class="order-entry-checklist-items" data-order-entry-checklist-items></div>

                        <div class="order-create-field mt-3">
                            <label for="checklistEntradaObservacoes">Observações gerais do estado de entrada</label>
                            <textarea
                                id="checklistEntradaObservacoes"
                                name="checklist_entrada[observacoes_estado]"
                                class="form-control @error('checklist_entrada.observacoes_estado') is-invalid @enderror"
                                rows="3"
                                placeholder="Ex.: riscos, marcas, acessórios, condição física observada"
                                data-order-entry-checklist-notes
                            >{{ old('checklist_entrada.observacoes_estado', data_get($entryChecklist, 'observacoes_estado', '')) }}</textarea>
                            @error('checklist_entrada.observacoes_estado')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </section>

            <section class="equipment-tab-panel" data-order-tab-panel="operacionais">
                <div class="order-create-panel-grid">
                    <div>
                        <label for="prioridade">Prioridade</label>
                        <select
                            id="prioridade"
                            name="prioridade"
                            class="form-select @error('prioridade') is-invalid @enderror"
                            data-order-create-priority
                        >
                            <option value="baixa" @selected($selectedPriority === 'baixa')>Baixa</option>
                            <option value="normal" @selected($selectedPriority === 'normal' || $selectedPriority === '')>Normal</option>
                            <option value="alta" @selected($selectedPriority === 'alta')>Alta</option>
                            <option value="urgente" @selected($selectedPriority === 'urgente')>Urgente</option>
                        </select>
                        @error('prioridade')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label for="tecnicoId">Tecnico responsavel</label>
                        <select
                            id="tecnicoId"
                            name="tecnico_id"
                            class="form-select @error('tecnico_id') is-invalid @enderror"
                            data-order-create-technician-select
                            data-select2-placeholder="Nao atribuido"
                        >
                            <option value="">Nao atribuido</option>
                            @foreach (($technicians ?? []) as $technician)
                                @php
                                    $technicianId = (int) ($technician['id'] ?? 0);
                                    $technicianName = trim((string) ($technician['nome'] ?? ''));
                                    $technicianEmail = trim((string) ($technician['email'] ?? ''));
                                @endphp
                                <option
                                    value="{{ $technicianId }}"
                                    @selected($selectedTechnicianId === $technicianId)
                                    data-technician-email="{{ $technicianEmail }}"
                                >
                                    {{ $technicianName !== '' ? $technicianName : 'Tecnico #' . $technicianId }}
                                </option>
                            @endforeach
                        </select>
                        @error('tecnico_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label for="prazoEntrega">Prazo de entrega</label>
                        <select
                            id="prazoEntrega"
                            class="form-select"
                            data-order-create-prazo-entrega
                        >
                            <option value="">Selecione um prazo</option>
                            <option value="1">1 dia</option>
                            <option value="3">3 dias</option>
                            <option value="7">7 dias</option>
                            <option value="15">15 dias</option>
                            <option value="30">30 dias</option>
                        </select>
                    </div>

                    <div>
                        <label for="dataPrevisao">Data de previsao</label>
                        <input
                            type="date"
                            id="dataPrevisao"
                            name="data_previsao"
                            class="form-control @error('data_previsao') is-invalid @enderror"
                            value="{{ $selectedPrevisao }}"
                            data-order-create-previsao
                        >
                        @error('data_previsao')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    @if ($isEditing)
                        <div class="order-create-panel-note">
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <strong>Status atual</strong>
                                @if ($canChangeStatus)
                                    <button type="button" class="btn btn-soft btn-sm" data-bs-toggle="modal" data-bs-target="#orderStatusModal">
                                        <i class="bi bi-arrow-repeat me-1"></i>
                                        Alterar status
                                    </button>
                                @endif
                            </div>
                            <span class="status-pill status-pill-sm">{{ $statusLabel }}</span>
                            @if ($isEncerrada)
                                <p class="mb-0">Esta OS está encerrada — a mudança de status fica bloqueada. Use "Cancelar baixa" na tela de detalhe se a baixa foi feita por engano.</p>
                            @else
                                <p class="mb-0">A mudanca de status e enviada direto ao backend central, separada da gravacao dos demais campos desta tela.</p>
                            @endif
                        </div>
                    @else
                        <div class="order-create-panel-note">
                            <strong>Fluxo inicial</strong>
                            <p>A OS entra em triagem no backend central. O tecnico pode ser atribuido antes da abertura para agilizar a fila de atendimento.</p>
                        </div>
                    @endif
                </div>
            </section>

            <section class="equipment-tab-panel" data-order-tab-panel="fotos">
                <div class="order-create-panel-grid">
                    @if ($isEditing && $existingPhotos !== [])
                        <div class="order-create-field order-create-field-span-2">
                            <label class="mb-1">Fotos ja anexadas</label>
                            <div class="order-create-photo-preview-grid">
                                @foreach ($existingPhotos as $existingPhoto)
                                    <div class="order-create-photo-preview-item">
                                        <a
                                            href="{{ route('orders.photos.show', [$orderId, (int) ($existingPhoto['id'] ?? 0)]) }}"
                                            target="_blank"
                                            rel="noopener"
                                            class="order-create-photo-preview-thumb"
                                        >
                                            <i class="bi bi-image"></i>
                                        </a>
                                        <div class="order-create-photo-preview-meta">
                                            <strong>{{ $existingPhoto['tipo_label'] ?? 'Foto' }}</strong>
                                            <small>{{ $existingPhoto['nome_arquivo'] ?? '' }}</small>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <small class="text-secondary d-block mt-2">Fotos ja enviadas para a OS. A remocao nao esta disponivel por aqui.</small>
                        </div>
                    @endif

                    <div class="order-create-field order-create-field-span-2">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                            <div>
                                <label class="mb-0">{{ $isEditing ? 'Adicionar novas fotos' : 'Fotos de entrada do equipamento' }}</label>
                                <small class="text-secondary d-block">Opcional: acessorios, estado fisico, placa interna e detalhes uteis.</small>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-primary btn-sm" data-order-create-photos-pick>
                                    <i class="bi bi-upload me-1"></i>
                                    Selecionar fotos
                                </button>
                                <button type="button" class="btn btn-outline-light btn-sm" data-order-create-photos-clear>
                                    <i class="bi bi-trash me-1"></i>
                                    Limpar
                                </button>
                            </div>
                        </div>

                        <input
                            type="file"
                            id="orderPhotos"
                            name="fotos[]"
                            class="d-none @error('fotos') is-invalid @enderror @error('fotos.*') is-invalid @enderror"
                            accept="image/jpeg,image/png,image/webp"
                            multiple
                            data-order-create-photos-input
                        >

                        <div class="order-create-photo-preview-grid" data-order-create-photos-preview></div>
                        <div class="text-secondary small mt-2">Maximo de 4 fotos por envio, ate 2MB cada. A visualizacao eh local antes do envio para o backend central.</div>
                        @error('fotos')<div class="invalid-feedback d-block mt-2">{{ $message }}</div>@enderror
                        @error('fotos.*')<div class="invalid-feedback d-block mt-2">{{ $message }}</div>@enderror
                    </div>
                </div>
            </section>

            <div class="order-create-actions">
                <a href="{{ $cancelUrl }}" class="btn btn-outline-light">Cancelar</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi {{ $submitIcon }} me-2"></i>
                    {{ $submitLabel }}
                </button>
            </div>
        </div>
    </form>
</section>

@if ($canChangeStatus)
    @push('modals')
        <div class="modal fade" id="orderStatusModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content modal-shell">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title mb-0">Atualizar status</h5>
                            <p class="surface-subtitle mb-0">Acao enviada ao backend central com validacao RBAC e catalogo de status.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <form method="post" action="{{ route('orders.status.update', $orderId) }}" class="d-grid gap-3">
                            @csrf
                            <div>
                                <label for="orderStatusSelect">Novo status</label>
                                <select name="status" id="orderStatusSelect" class="form-select" required>
                                    @foreach ($statusDisponiveis as $statusOption)
                                        <option value="{{ $statusOption['codigo'] ?? '' }}" @selected($currentStatusCode === ($statusOption['codigo'] ?? ''))>
                                            {{ $statusOption['nome'] ?? ($statusOption['codigo'] ?? '') }} ({{ $statusOption['codigo'] ?? '' }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="orderStatusObservacao">Observacao da mudanca</label>
                                <textarea name="observacao" id="orderStatusObservacao" class="form-control" rows="4" placeholder="Registre o motivo ou contexto da alteracao"></textarea>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-arrow-repeat me-2"></i>
                                    Salvar novo status
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endpush
@endif

@if ($canCreateClient || $canCreateEquipment)
    @push('modals')
        @if ($canCreateClient)
            @include('clients.quick-modal', [
                'fullCreateUrl' => route('clients.create'),
            ])
        @endif

        @if ($canCreateEquipment)
            <div
                class="modal fade"
                id="quickEquipmentModal"
                tabindex="-1"
                aria-hidden="true"
                data-bs-backdrop="static"
                data-bs-keyboard="false"
                data-order-create-equipment-create-url="{{ route('equipments.create', ['embedded' => 1]) }}"
            >
                <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
                    <div class="modal-content modal-shell">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title mb-0">Novo equipamento</h5>
                                <p class="surface-subtitle mb-0">Cadastro completo com cliente, catalogo, cor, fotos e apoio tecnico.</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body p-0">
                            <iframe
                                src="about:blank"
                                title="Cadastro de equipamento"
                                class="order-create-equipment-frame"
                                data-order-create-equipment-frame
                            ></iframe>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endpush
@endif
