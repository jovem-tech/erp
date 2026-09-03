@php
    $budget = is_array($budget ?? null) ? $budget : [];
    $form = is_array($form ?? null) ? $form : [];

    $clients = $form['clients'] ?? [];
    $equipments = $form['equipments'] ?? [];
    $orders = $form['orders'] ?? [];
    $services = $form['services'] ?? [];
    $parts = $form['parts'] ?? [];
    // Tipos de equipamento do banco (EquipmentType ativos) para o Select2 do campo Tipo.
    $tiposEquipamento = is_array($form['tipos_equipamento'] ?? null) ? $form['tipos_equipamento'] : [];
    $quickCatalogs = is_array($quickCatalogs ?? null) ? $quickCatalogs : [];
    $quickServiceEnabled = (bool) data_get($quickCatalogs, 'service.enabled', false);
    $quickPartEnabled = (bool) data_get($quickCatalogs, 'part.enabled', false);
    $canQuickCatalog = $quickServiceEnabled || $quickPartEnabled;
    $statusOptions = $form['status_options'] ?? [];
    $typeOptions = $form['type_options'] ?? [
        ['value' => 'previo', 'label' => 'Orçamento prévio'],
        ['value' => 'assistencia', 'label' => 'Orçamento com equipamento na assistência'],
    ];
    $originOptions = $form['origin_options'] ?? [
        ['value' => 'manual', 'label' => 'Manual'],
        ['value' => 'os', 'label' => 'Ordem de serviço'],
        ['value' => 'conversa', 'label' => 'Conversa'],
        ['value' => 'cliente', 'label' => 'Cliente'],
    ];

    // Condições comerciais: catálogo vindo das configurações financeiras
    // (formas de pagamento ativas e chaves Pix) + prazos de garantia.
    $termsCatalog = is_array($form['condicoes_comerciais_catalogo'] ?? null)
        ? $form['condicoes_comerciais_catalogo']
        : [];
    $paymentMethodOptions = is_array($termsCatalog['formas_pagamento'] ?? null) ? $termsCatalog['formas_pagamento'] : [];
    $pixKeyOptions = is_array($termsCatalog['chaves_pix'] ?? null) ? $termsCatalog['chaves_pix'] : [];
    $warrantyOptions = is_array($termsCatalog['garantia_options'] ?? null) ? $termsCatalog['garantia_options'] : [];
    $maxInstallments = (int) ($termsCatalog['max_parcelas_sem_juros'] ?? 24);

    $savedPaymentCodes = collect(data_get($budget, 'condicoes_comerciais.formas_pagamento', []))
        ->pluck('codigo')
        ->map(static fn ($code): string => (string) $code)
        ->all();
    $selectedPaymentCodes = old('formas_pagamento', $savedPaymentCodes);
    $selectedPaymentCodes = is_array($selectedPaymentCodes)
        ? array_map(static fn ($code): string => (string) $code, $selectedPaymentCodes)
        : [];
    $selectedWarrantyDays = (string) old('garantia_dias', $budget['garantia_dias'] ?? '');
    $selectedInstallments = (string) old('parcelas_sem_juros', $budget['parcelas_sem_juros'] ?? '');

    $parseMoney = static function (mixed $value): float {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d,.\-]/u', '', trim((string) $value)) ?? '';
        if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === ',') {
            return 0.0;
        }

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastComma !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($lastDot !== false) {
            $parts = explode('.', $normalized);
            $lastPart = (string) end($parts);

            if (count($parts) > 2 || strlen($lastPart) === 3) {
                $normalized = str_replace('.', '', $normalized);
            }
        }

        return round((float) $normalized, 2);
    };

    $formatMoney = static fn (mixed $value): string => 'R$ ' . number_format($parseMoney($value), 2, ',', '.');
    $parseDecimal = static function (mixed $value, int $scale = 4): float {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d,.\-]/u', '', trim((string) $value)) ?? '';
        if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === ',') {
            return 0.0;
        }

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastComma !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($lastDot !== false) {
            $parts = explode('.', $normalized);
            $lastPart = (string) end($parts);

            if (count($parts) > 2 || strlen($lastPart) === 3) {
                $normalized = str_replace('.', '', $normalized);
            }
        }

        return round((float) $normalized, $scale);
    };
    $formatDecimalValue = static fn (mixed $value, int $scale = 4): string => number_format($parseDecimal($value, $scale), $scale, '.', '');
    $formatPercentDisplay = static fn (mixed $value): string => number_format($parseDecimal($value, 4), 2, ',', '.');
    $resolveAdjustmentType = static function (mixed $value): string {
        $normalized = trim((string) $value);

        return in_array($normalized, ['valor', 'percentual'], true) ? $normalized : 'valor';
    };

    $selectedItems = old('itens');
    if (! is_array($selectedItems)) {
        $selectedItems = $budget['itens'] ?? [];
    }
    if (! is_array($selectedItems) || $selectedItems === []) {
        $selectedItems = [[
            'tipo_item' => 'servico',
            'referencia_id' => null,
            'descricao' => '',
            'quantidade' => 1,
            'valor_unitario' => 0,
            'desconto' => 0,
            'desconto_tipo' => 'valor',
            'desconto_percentual' => 0,
            'acrescimo' => 0,
            'acrescimo_tipo' => 'valor',
            'acrescimo_percentual' => 0,
            'observacoes' => '',
            'modo_precificacao' => 'manual',
        ]];
    }

    $formMethod = strtoupper((string) ($formMethod ?? ($isEditMode ? 'PATCH' : 'POST')));
    $budgetId = (int) ($budget['id'] ?? 0);
    $draftKey = 'orcamentos:' . ($isEditMode ? 'edit:' . $budgetId : 'create');
    $selectedClientId = (int) old('cliente_id', $form['selected_client_id'] ?? ($budget['cliente']['id'] ?? $budget['cliente_id'] ?? 0));
    $selectedOrderId = (int) old('os_id', $form['selected_order_id'] ?? ($budget['os']['id'] ?? $budget['os_id'] ?? 0));
    $selectedEquipmentId = (int) old('equipamento_id', $budget['equipamento']['id'] ?? $budget['equipamento_id'] ?? ($form['selected_equipment_id'] ?? 0));
    $fromOrderListing = ! $isEditMode && $selectedOrderId > 0;
    $statusValue = old('status', $budget['status'] ?? ($fromOrderListing ? 'pendente_envio' : 'rascunho'));
    $typeValue = old('tipo_orcamento', $budget['tipo_orcamento'] ?? ($fromOrderListing ? 'assistencia' : 'previo'));
    $originValue = old('origem', $budget['origem'] ?? ($fromOrderListing ? 'os' : 'manual'));
    $prazoExecucaoValue = old('prazo_execucao', $budget['prazo_execucao'] ?? ($fromOrderListing ? ($form['selected_order_deadline'] ?? '') : ''));
    $relatoClienteValue = old('relato_cliente', $budget['relato_cliente'] ?? ($fromOrderListing ? ($form['selected_order_relato'] ?? '') : ''));
    $lockedOrderContext = is_array($lockedOrderContext ?? null) ? $lockedOrderContext : [];
    $clientLocked = (bool) ($lockedOrderContext['locked'] ?? (! $isEditMode && $selectedOrderId > 0 && $selectedClientId > 0));
    $lockedOrderNumber = trim((string) ($lockedOrderContext['order_number'] ?? ''));
    $lockedClientName = trim((string) ($lockedOrderContext['client_name'] ?? ''));
    if ($clientLocked && ($lockedOrderNumber === '' || $lockedClientName === '')) {
        foreach ($orders as $orderOption) {
            if ((int) ($orderOption['id'] ?? 0) === $selectedOrderId) {
                $lockedOrderNumber = $lockedOrderNumber !== '' ? $lockedOrderNumber : trim((string) ($orderOption['numero_os'] ?? ''));
                $lockedClientName = $lockedClientName !== '' ? $lockedClientName : trim((string) ($orderOption['cliente_nome'] ?? ''));
                break;
            }
        }
    }
    // Cadastro rapido de cliente: so faz sentido quando o operador pode criar
    // clientes E o cliente ainda nao esta travado por uma OS de origem.
    $canQuickClient = ($canQuickClient ?? false) && ! $clientLocked;

    // Cadastro do equipamento do orcamento: usa o MESMO formulario embutido da
    // abertura de OS (tipo -> marca -> modelo, com criacao inline de cada nivel).
    // Precisa de um cliente cadastrado — equipamento pertence a um cliente.
    $canCreateEquipment = ($canCreateEquipment ?? false);

    $globalDiscountType = $resolveAdjustmentType(old('desconto_tipo', $budget['desconto_tipo'] ?? 'valor'));
    $globalDiscountAmount = old('desconto', $budget['desconto'] ?? 0);
    $globalDiscountPercent = old('desconto_percentual', $budget['desconto_percentual'] ?? 0);
    $globalDiscountDisplay = $globalDiscountType === 'percentual'
        ? $formatPercentDisplay($globalDiscountPercent)
        : $formatMoney($globalDiscountAmount);
    $globalDiscountPreviewVisible = $globalDiscountType === 'percentual';
    $globalAdditionType = $resolveAdjustmentType(old('acrescimo_tipo', $budget['acrescimo_tipo'] ?? 'valor'));
    $globalAdditionAmount = old('acrescimo', $budget['acrescimo'] ?? 0);
    $globalAdditionPercent = old('acrescimo_percentual', $budget['acrescimo_percentual'] ?? 0);
    $globalAdditionDisplay = $globalAdditionType === 'percentual'
        ? $formatPercentDisplay($globalAdditionPercent)
        : $formatMoney($globalAdditionAmount);
    $globalAdditionPreviewVisible = $globalAdditionType === 'percentual';

    // OS encerrada (skill sistema-erp-os-fluxo-fechamento): edição exige
    // confirmação de administrador — ver BudgetWorkflowService::isOrderClosed().
    $osIsEncerrada = (bool) ($budget['os']['is_encerrada'] ?? false);

    // Equipamento eventual (aparelho sem cadastro) — espelha o cliente eventual.
    $clienteAvulsoValue = trim((string) old('cliente_nome_avulso', $budget['cliente_nome_avulso'] ?? ''));
    $envolveEquipamentoRaw = old('envolve_equipamento', (($budget['envolve_equipamento'] ?? true) ? '1' : '0'));
    $envolveEquipamento = (string) $envolveEquipamentoRaw === '1' || $envolveEquipamentoRaw === true;
    $equipTipoAvulso = trim((string) old('equipamento_tipo_avulso', $budget['equipamento_tipo_avulso'] ?? ''));
    $equipMarcaAvulso = trim((string) old('equipamento_marca_avulso', $budget['equipamento_marca_avulso'] ?? ''));
    $equipModeloAvulso = trim((string) old('equipamento_modelo_avulso', $budget['equipamento_modelo_avulso'] ?? ''));
    $equipCorAvulso = trim((string) old('equipamento_cor', $budget['equipamento_cor'] ?? ''));
    $hasEventualEquipment = ($equipTipoAvulso . $equipMarcaAvulso . $equipModeloAvulso . $equipCorAvulso) !== '';
    $hasEventualClient = $clienteAvulsoValue !== '' && $selectedClientId <= 0;

    // Marca/modelo do equipamento eventual: tipo/marca/modelo são catálogo do
    // ERP (EquipmentType/EquipmentBrand/EquipmentModel), não vinculados a
    // cliente algum — servem só de consulta. Os selects abaixo listam o
    // catálogo real (filtrado em cascata: tipo -> marca -> modelo, igual ao
    // cadastro de equipamento) para o operador escolher em vez de digitar
    // texto solto; o valor continua sendo o NOME (equipamento eventual não tem
    // FK), mas marca/modelo novos digitados são gravados no catálogo de
    // verdade pelo orcamentos-form.js (equipments.brands/models.quick.store).
    $equipmentCatalog = is_array($equipmentCatalog ?? null) ? $equipmentCatalog : [];
    $catalogTypes = is_array($equipmentCatalog['types'] ?? null) ? $equipmentCatalog['types'] : [];
    $catalogBrands = is_array($equipmentCatalog['brands'] ?? null) ? $equipmentCatalog['brands'] : [];
    $catalogModels = is_array($equipmentCatalog['models'] ?? null) ? $equipmentCatalog['models'] : [];
    $catalogRelations = is_array($equipmentCatalog['catalog_relations'] ?? null) ? $equipmentCatalog['catalog_relations'] : [];

    $equipTipoAvulsoId = (int) (collect($catalogTypes)
        ->first(fn (array $type): bool => mb_strtolower(trim((string) ($type['nome'] ?? ''))) === mb_strtolower($equipTipoAvulso))['id'] ?? 0);

    $filteredBrandsAvulso = $equipTipoAvulsoId > 0
        ? collect($catalogRelations)
            ->filter(fn (array $relation): bool => (int) ($relation['tipo_id'] ?? 0) === $equipTipoAvulsoId)
            ->pluck('marca_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->flatMap(fn (int $marcaId) => collect($catalogBrands)->where('id', $marcaId))
            ->sortBy('nome')
            ->values()
            ->all()
        : [];

    $equipMarcaAvulsoId = (int) (collect($catalogBrands)
        ->first(fn (array $brand): bool => mb_strtolower(trim((string) ($brand['nome'] ?? ''))) === mb_strtolower($equipMarcaAvulso))['id'] ?? 0);

    $filteredModelsAvulso = ($equipTipoAvulsoId > 0 && $equipMarcaAvulsoId > 0)
        ? collect($catalogRelations)
            ->filter(fn (array $relation): bool => (int) ($relation['tipo_id'] ?? 0) === $equipTipoAvulsoId
                && (int) ($relation['marca_id'] ?? 0) === $equipMarcaAvulsoId)
            ->pluck('modelo_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->flatMap(fn (int $modeloId) => collect($catalogModels)->where('id', $modeloId))
            ->sortBy('nome')
            ->values()
            ->all()
        : [];
@endphp

<section class="desktop-form-card">
    <div class="surface-card-header align-items-start">
        <div>
            <h2 class="surface-title mb-1">{{ $formTitle ?? 'Orçamento' }}</h2>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-start">
            <span class="desktop-chip">
                <i class="bi bi-receipt"></i>
                {{ $isEditMode ? 'Edição' : 'Rascunho' }}
            </span>
            @if (($budget['numero'] ?? '') !== '')
                <span class="desktop-chip">
                    <i class="bi bi-hash"></i>
                    {{ $budget['numero'] }}
                </span>
            @endif
        </div>
    </div>

    <div class="alert alert-primary d-none d-flex flex-wrap align-items-center justify-content-between gap-3" role="alert" data-budget-draft-banner>
        <div>
            <strong>Encontramos um rascunho salvo automaticamente.</strong>
            <div class="small text-secondary">Você pode restaurar os dados locais ou descartá-los e começar de novo.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-light btn-sm" data-budget-draft-discard>Descartar</button>
            <button type="button" class="btn btn-info btn-sm" data-budget-draft-restore>Restaurar</button>
        </div>
    </div>

    @if ($osIsEncerrada)
        <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-lock-fill"></i>
            <div>
                <strong>Esta OS está encerrada.</strong>
                Qualquer alteração salva aqui exigirá confirmação de um administrador.
            </div>
        </div>
    @endif

    <form
        method="post"
        action="{{ $formAction }}"
        class="desktop-form-stack desktop-budget-form"
        data-budget-form
        data-budget-draft-key="{{ $draftKey }}"
        data-budget-is-edit="{{ $isEditMode ? '1' : '0' }}"
        data-budget-is-encerrada="{{ $osIsEncerrada ? '1' : '0' }}"
    >
        @csrf
        @if ($formMethod !== 'POST')
            @method($formMethod)
        @endif
        <input type="hidden" name="submission_mode" value="save_only" data-budget-submission-mode>
        <input type="hidden" name="admin_email" value="" data-budget-admin-email>
        <input type="hidden" name="admin_password" value="" data-budget-admin-password>

        <input type="hidden" name="numero" value="{{ old('numero', $budget['numero'] ?? '') }}">
        <input type="hidden" name="versao" value="{{ old('versao', $budget['versao'] ?? 1) }}">

        <div class="equipment-tabs" role="tablist" aria-label="Etapas do orçamento">
            <button type="button" class="equipment-tab is-active" data-budget-tab="cliente" aria-pressed="true">
                <i class="bi bi-person-vcard"></i>
                Dados do cliente
                <span class="equipment-tab-flag" data-budget-tab-flag hidden title="Faltam campos obrigatórios nesta aba"></span>
            </button>
            <button type="button" class="equipment-tab" data-budget-tab="equipamento" aria-pressed="false">
                <i class="bi bi-laptop"></i>
                Dados do equipamento
                <span class="equipment-tab-flag" data-budget-tab-flag hidden title="Faltam campos obrigatórios nesta aba"></span>
            </button>
            <button type="button" class="equipment-tab" data-budget-tab="operacional" aria-pressed="false">
                <i class="bi bi-sliders"></i>
                Dados operacionais
                <span class="equipment-tab-flag" data-budget-tab-flag hidden title="Faltam campos obrigatórios nesta aba"></span>
            </button>
            <button type="button" class="equipment-tab" data-budget-tab="financeiro" aria-pressed="false">
                <i class="bi bi-cash-coin"></i>
                Orçamento e financeiro
                <span class="equipment-tab-flag" data-budget-tab-flag hidden title="Faltam campos obrigatórios nesta aba"></span>
            </button>
        </div>

        <div class="equipment-tab-panel is-active" data-budget-panel="cliente">
            <div class="desktop-grid desktop-grid-two">
                <div>
                    <label for="orcamentoClienteId">Cliente cadastrado <span class="text-danger" aria-hidden="true">*</span></label>
                    <div class="d-flex gap-2 align-items-start">
                        <select id="orcamentoClienteId" name="{{ $clientLocked ? '' : 'cliente_id' }}" class="form-select" data-select2-placeholder="Selecione um cliente..." data-select2-allow-clear="true" data-budget-client-select @disabled($clientLocked)>
                            <option value=""></option>
                            @foreach ($clients as $client)
                                @php
                                    $clientId = (int) ($client['id'] ?? 0);
                                    $clientName = trim((string) ($client['nome_razao'] ?? ''));
                                    $clientDocument = trim((string) ($client['cpf_cnpj'] ?? ''));
                                    $clientPhone = trim((string) ($client['telefone1'] ?? ''));
                                    $clientLabel = implode(' - ', array_values(array_filter([$clientName, $clientDocument, $clientPhone])));
                                @endphp
                                <option
                                    value="{{ $clientId }}"
                                    data-client-name="{{ $clientName }}"
                                    data-client-phone="{{ $clientPhone }}"
                                    data-client-email="{{ trim((string) ($client['email'] ?? '')) }}"
                                    @selected($selectedClientId === $clientId)
                                >{{ $clientLabel !== '' ? $clientLabel : 'Cliente' }}</option>
                            @endforeach
                        </select>
                        @if ($canQuickClient)
                            <button
                                type="button"
                                id="orcamentoQuickClientButton"
                                class="btn btn-soft flex-shrink-0"
                                title="Cadastrar novo cliente"
                                aria-label="Cadastrar novo cliente"
                            >
                                <i class="bi bi-person-plus"></i>
                            </button>
                        @endif
                    </div>
                    @if ($clientLocked)
                        <input type="hidden" name="cliente_id" value="{{ $selectedClientId }}">
                    @elseif ($canQuickClient)
                        <small class="text-secondary d-block mt-2">Se o cliente ainda não existir, use o botão <i class="bi bi-person-plus"></i> para cadastrá-lo sem sair do orçamento.</small>
                    @else
                        <small class="text-secondary d-block mt-2">Se o cliente ainda não existir, preencha os dados avulsos abaixo.</small>
                    @endif
                </div>

                <div>
                    <label for="orcamentoClienteAvulso">Nome do cliente eventual <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text" id="orcamentoClienteAvulso" name="cliente_nome_avulso" class="form-control" value="{{ old('cliente_nome_avulso', $budget['cliente_nome_avulso'] ?? '') }}" placeholder="Preencher apenas se não houver cadastro" data-budget-client-avulso @disabled($clientLocked)>
                </div>

                <div>
                    <label for="orcamentoTelefoneContato">Telefone de contato <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text" id="orcamentoTelefoneContato" name="telefone_contato" class="form-control" value="{{ old('telefone_contato', $budget['telefone_contato'] ?? ($form['selected_client_phone'] ?? '')) }}" placeholder="(11) 98765-4321" @if (! $isEditMode) required aria-required="true" @endif>
                </div>

                <div>
                    <label for="orcamentoEmailContato">E-mail de contato</label>
                    <input type="email" id="orcamentoEmailContato" name="email_contato" class="form-control" value="{{ old('email_contato', $budget['email_contato'] ?? ($form['selected_client_email'] ?? '')) }}" placeholder="cliente@dominio.com">
                </div>

                {{-- OS vinculada: só aparece quando o cliente selecionado tem OS
                     aberta. As opções são recarregadas via AJAX ao trocar o
                     cliente (data-budget-order-field controla a visibilidade). --}}
                <div class="desktop-grid-span-2 d-none" data-budget-equipment-field data-budget-registered-only data-budget-order-field>
                    <label for="orcamentoOsId">OS vinculada</label>
                    <select id="orcamentoOsId" name="os_id" class="form-select" data-select2-placeholder="Selecione uma OS..." data-select2-allow-clear="true">
                        <option value=""></option>
                        @foreach ($orders as $order)
                            @php
                                $orderId = (int) ($order['id'] ?? 0);
                                $orderClienteId = (int) ($order['cliente_id'] ?? 0);
                                $orderEquipamentoId = (int) ($order['equipamento_id'] ?? 0);
                                $orderLabel = trim((string) ($order['numero_os'] ?? 'OS #' . $orderId));
                                $orderClient = trim((string) ($order['cliente_nome'] ?? ''));
                                $orderRelato = trim((string) ($order['relato_cliente'] ?? ''));
                            @endphp
                            <option value="{{ $orderId }}" data-cliente-id="{{ $orderClienteId }}" data-equipamento-id="{{ $orderEquipamentoId }}" data-relato-cliente="{{ $orderRelato }}" @selected($selectedOrderId === $orderId)>{{ $orderLabel }}{{ $orderClient !== '' ? ' - ' . $orderClient : '' }}</option>
                        @endforeach
                    </select>
                    <small class="text-secondary d-block mt-2">Somente OS abertas do cliente selecionado.</small>
                </div>
            </div>
        </div>

        <div class="equipment-tab-panel" data-budget-panel="equipamento">
            <div class="desktop-grid desktop-grid-two">
                <div class="desktop-grid-span-2">
                    <div class="form-check">
                        <input type="hidden" name="envolve_equipamento" value="0">
                        <input type="checkbox" class="form-check-input" id="orcamentoEnvolveEquipamento" name="envolve_equipamento" value="1" data-budget-envolve-equipamento @checked($envolveEquipamento)>
                        <label class="form-check-label" for="orcamentoEnvolveEquipamento">Orçamento para reparo de um equipamento</label>
                    </div>
                    <small class="text-secondary d-block mt-1">Desmarque para serviços sem aparelho (visita técnica, instalação de cabo de rede, etc.).</small>
                </div>

                <div class="desktop-grid-span-2" data-budget-equipment-field data-budget-registered-only>
                    <label for="orcamentoEquipamentoId">Equipamento cadastrado</label>
                    {{-- O Select2 deste campo é montado pelo orcamentos-form.js
                         (initEquipmentSelect) com template de miniatura (foto do
                         equipamento) e marcado com data-select2Ready para o init
                         genérico do desktop.js não o reinicializar. As opções são
                         recarregadas via AJAX ao trocar o cliente (apenas
                         equipamentos do cliente escolhido). --}}
                    <div class="d-flex gap-2 align-items-start">
                        <select id="orcamentoEquipamentoId" name="equipamento_id" class="form-select" data-select2-placeholder="Selecione um equipamento..." data-select2-allow-clear="true" data-budget-equipment-select>
                            <option value=""></option>
                            @foreach ($equipments as $equipment)
                                @php
                                    $equipmentId = (int) ($equipment['id'] ?? 0);
                                    $equipmentClienteId = (int) ($equipment['cliente_id'] ?? 0);
                                    $equipmentTipoNome = trim((string) ($equipment['tipo_nome'] ?? ''));
                                    $equipmentMarcaNome = trim((string) ($equipment['marca_nome'] ?? ''));
                                    $equipmentModeloNome = trim((string) ($equipment['modelo_nome'] ?? ''));
                                    $equipmentMarcaModelo = trim(implode(' ', array_filter([$equipmentMarcaNome, $equipmentModeloNome])));
                                    $equipmentLabel = trim(implode(' - ', array_filter([$equipmentTipoNome, $equipmentMarcaModelo])));
                                    if ($equipmentLabel === '') {
                                        $equipmentLabel = trim((string) ($equipment['resumo_tecnico'] ?? ''));
                                    }
                                    $serial = trim((string) ($equipment['numero_serie'] ?? ''));
                                    $clientName = trim((string) ($equipment['cliente_nome'] ?? ''));
                                    $equipmentFotoId = (int) ($equipment['foto_principal_id'] ?? 0);
                                    $equipmentFotoUrl = $equipmentFotoId > 0 && $equipmentId > 0
                                        ? route('equipments.photos.show', [$equipmentId, $equipmentFotoId])
                                        : '';
                                @endphp
                                <option value="{{ $equipmentId }}" data-cliente-id="{{ $equipmentClienteId }}" data-foto-url="{{ $equipmentFotoUrl }}" @selected($selectedEquipmentId === $equipmentId)>{{ $equipmentLabel !== '' ? $equipmentLabel : 'Equipamento #' . $equipmentId }}{{ $serial !== '' ? ' · S/N ' . $serial : '' }}{{ $clientName !== '' ? ' · ' . $clientName : '' }}</option>
                            @endforeach
                        </select>
                        @if ($canCreateEquipment)
                            <button
                                type="button"
                                id="orcamentoQuickEquipmentButton"
                                class="btn btn-soft flex-shrink-0"
                                title="Cadastrar equipamento do cliente"
                                aria-label="Cadastrar equipamento do cliente"
                            >
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        @endif
                    </div>
                    @if ($canCreateEquipment)
                        <small class="text-secondary d-block mt-2" data-budget-equipment-create-hint>
                            Cliente novo, aparelho ainda em casa? Selecione o cliente e use o botão
                            <i class="bi bi-plus-lg"></i> para cadastrar o equipamento agora — a foto fica
                            para quando ele trouxer o aparelho.
                        </small>
                    @endif
                </div>

                <div class="desktop-grid-span-2" data-budget-equipment-field data-budget-eventual-equipment>
                    <div class="surface-subtle-card p-3">
                        <div class="fw-semibold mb-1"><i class="bi bi-phone me-1"></i>Equipamento eventual</div>
                        <small class="text-secondary d-block mb-3">Aparelho que ainda não veio à assistência. Preencha o que souber — estes dados pré-preenchem o cadastro do equipamento ao gerar a OS.</small>
                        <div class="desktop-grid desktop-grid-two">
                            <div>
                                <label for="orcamentoEquipTipoAvulso">Tipo <span class="text-danger" aria-hidden="true">*</span></label>
                                @php
                                    // Se o valor salvo não estiver no catálogo (tipo digitado antes),
                                    // acrescentamos como opção para não perder a seleção.
                                    $tipoAvulsoIsCustom = $equipTipoAvulso !== '' && ! in_array($equipTipoAvulso, $tiposEquipamento, true);
                                @endphp
                                <select id="orcamentoEquipTipoAvulso" name="equipamento_tipo_avulso" class="form-select" data-native-select="true" data-select2-placeholder="Selecione ou digite o tipo..." data-budget-eventual-input data-budget-equip-type-select>
                                    <option value=""></option>
                                    @foreach ($tiposEquipamento as $tipo)
                                        <option value="{{ $tipo }}" @selected($equipTipoAvulso === $tipo)>{{ $tipo }}</option>
                                    @endforeach
                                    @if ($tipoAvulsoIsCustom)
                                        <option value="{{ $equipTipoAvulso }}" selected>{{ $equipTipoAvulso }}</option>
                                    @endif
                                </select>
                            </div>
                            <div>
                                <label for="orcamentoEquipMarcaAvulso">Marca <span class="text-danger" aria-hidden="true">*</span></label>
                                @php
                                    // Marca digitada antes e que não bateu com o catálogo por nome:
                                    // preserva como opção para não perder a seleção salva.
                                    $marcaAvulsoIsCustom = $equipMarcaAvulso !== '' && $equipMarcaAvulsoId === 0;
                                @endphp
                                <select id="orcamentoEquipMarcaAvulso" name="equipamento_marca_avulso" class="form-select" data-native-select="true" data-select2-placeholder="Selecione ou digite a marca..." data-budget-eventual-input data-budget-equip-brand-select>
                                    <option value=""></option>
                                    @foreach ($filteredBrandsAvulso as $brand)
                                        <option value="{{ $brand['nome'] }}" data-brand-id="{{ $brand['id'] }}" @selected($equipMarcaAvulso === $brand['nome'])>{{ $brand['nome'] }}</option>
                                    @endforeach
                                    @if ($marcaAvulsoIsCustom)
                                        <option value="{{ $equipMarcaAvulso }}" selected>{{ $equipMarcaAvulso }}</option>
                                    @endif
                                </select>
                            </div>
                            <div>
                                <label for="orcamentoEquipModeloAvulso">Modelo <span class="text-danger" aria-hidden="true">*</span></label>
                                @php
                                    $modeloAvulsoIsCustom = $equipModeloAvulso !== '' && ! collect($filteredModelsAvulso)->contains(fn (array $model): bool => mb_strtolower(trim((string) ($model['nome'] ?? ''))) === mb_strtolower($equipModeloAvulso));
                                @endphp
                                <select id="orcamentoEquipModeloAvulso" name="equipamento_modelo_avulso" class="form-select" data-native-select="true" data-select2-placeholder="Selecione ou digite o modelo..." data-budget-eventual-input data-budget-equip-model-select>
                                    <option value=""></option>
                                    @foreach ($filteredModelsAvulso as $model)
                                        <option value="{{ $model['nome'] }}" data-model-id="{{ $model['id'] }}" @selected($equipModeloAvulso === $model['nome'])>{{ $model['nome'] }}</option>
                                    @endforeach
                                    @if ($modeloAvulsoIsCustom)
                                        <option value="{{ $equipModeloAvulso }}" selected>{{ $equipModeloAvulso }}</option>
                                    @endif
                                </select>
                            </div>
                            <div>
                                <label for="orcamentoEquipCorAvulso">Cor <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="text" id="orcamentoEquipCorAvulso" name="equipamento_cor" class="form-control" value="{{ $equipCorAvulso }}" placeholder="Ex.: Preto" data-budget-eventual-input maxlength="100">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="desktop-grid-span-2">
                    <label for="orcamentoRelatoCliente">Relato do cliente / defeito relatado <span class="text-danger" aria-hidden="true">*</span></label>
                    <textarea id="orcamentoRelatoCliente" name="relato_cliente" class="form-control" rows="3" placeholder="Descreva o problema relatado pelo cliente. Ao gerar a OS, isto preenche o relato da ordem.">{{ $relatoClienteValue }}</textarea>
                </div>

                <div class="desktop-grid-span-2">
                    <label for="orcamentoTitulo">Título do orçamento</label>
                    <input type="text" id="orcamentoTitulo" name="titulo" class="form-control" value="{{ old('titulo', $budget['titulo'] ?? '') }}" placeholder="Ex.: Orçamento com equipamento na assistência">
                </div>

                <div>
                    <label for="orcamentoValidadeDias">Validade em dias</label>
                    @php
                        $validadeDiasValue = (int) old('validade_dias', $budget['validade_dias'] ?? $form['default_validity_days'] ?? 10);
                        $validadeDiasOptions = [10, 15, 20];
                        if (! in_array($validadeDiasValue, $validadeDiasOptions, true)) {
                            $validadeDiasOptions[] = $validadeDiasValue;
                            sort($validadeDiasOptions);
                        }
                    @endphp
                    <select id="orcamentoValidadeDias" name="validade_dias" class="form-select" data-budget-validity-days>
                        @foreach ($validadeDiasOptions as $validadeDiasOption)
                            <option value="{{ $validadeDiasOption }}" @selected($validadeDiasValue === $validadeDiasOption)>{{ $validadeDiasOption }} dias</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="orcamentoValidadeData">Validade até</label>
                    @php
                        // O backend devolve validade_data em d/m/Y; o input date exige Y-m-d.
                        $validadeDataValue = trim((string) old('validade_data', $budget['validade_data'] ?? ''));
                        if ($validadeDataValue !== '') {
                            try {
                                $validadeDataValue = str_contains($validadeDataValue, '/')
                                    ? \Illuminate\Support\Carbon::createFromFormat('d/m/Y', $validadeDataValue)->format('Y-m-d')
                                    : \Illuminate\Support\Carbon::parse($validadeDataValue)->format('Y-m-d');
                            } catch (\Throwable $exception) {
                                $validadeDataValue = '';
                            }
                        }
                    @endphp
                    <input type="date" id="orcamentoValidadeData" name="validade_data" class="form-control" data-budget-validity-date value="{{ $validadeDataValue }}">
                </div>
            </div>
        </div>

        <div class="equipment-tab-panel" data-budget-panel="operacional">
            <div class="desktop-grid desktop-grid-two">
                @php
                    $typeLabelMap = [];
                    foreach ($typeOptions as $typeOption) {
                        $typeLabelMap[(string) ($typeOption['value'] ?? '')] = (string) ($typeOption['label'] ?? 'Tipo');
                    }
                    $originLabelMap = [];
                    foreach ($originOptions as $originOption) {
                        $originLabelMap[(string) ($originOption['value'] ?? '')] = (string) ($originOption['label'] ?? 'Origem');
                    }
                    $statusLabelMap = [];
                    foreach ($statusOptions as $statusOption) {
                        $statusLabelMap[(string) ($statusOption['value'] ?? '')] = (string) ($statusOption['label'] ?? 'Status');
                    }
                    $typeDisplayLabel = $typeLabelMap[$typeValue] ?? 'Orçamento prévio';
                    $originDisplayLabel = $originLabelMap[$originValue] ?? 'Manual';
                    $statusDisplayLabel = $statusLabelMap[$statusValue] ?? 'Rascunho';
                    $statusManagedBySystem = in_array($statusValue, ['pendente_abertura_os', 'convertido'], true);
                @endphp
                <div>
                    <label for="orcamentoTipoDisplay">Tipo de orçamento</label>
                    <input type="text" id="orcamentoTipoDisplay" class="form-control" value="{{ $typeDisplayLabel }}" readonly data-budget-type-display>
                    <input type="hidden" name="tipo_orcamento" value="{{ $typeValue }}" data-budget-type-value>
                    <small class="text-secondary d-block mt-2">Definido automaticamente: <strong>sem OS</strong> é orçamento prévio (avulso); <strong>com OS</strong> é equipamento na assistência.</small>
                </div>

                <div>
                    <label for="orcamentoOrigemDisplay">Origem</label>
                    <input type="text" id="orcamentoOrigemDisplay" class="form-control" value="{{ $originDisplayLabel }}" readonly data-budget-origin-display>
                    <input type="hidden" name="origem" value="{{ $originValue }}" data-budget-origin-value>
                </div>

                <div>
                    <label for="orcamentoStatus">Status</label>
                    @if ($statusManagedBySystem)
                        <select id="orcamentoStatus" class="form-select" disabled>
                            <option selected>{{ $statusDisplayLabel }}</option>
                        </select>
                        <small class="text-secondary d-block mt-2">Este estado é controlado automaticamente pela aprovação e pela conversão em OS.</small>
                    @else
                        <select id="orcamentoStatus" name="status" class="form-select">
                            @foreach ($statusOptions as $statusOption)
                                @continue(in_array((string) ($statusOption['value'] ?? ''), ['pendente_abertura_os', 'convertido'], true))
                                <option value="{{ $statusOption['value'] ?? '' }}" @selected($statusValue === ($statusOption['value'] ?? ''))>
                                    {{ $statusOption['label'] ?? 'Status' }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                </div>

                <div>
                    <label for="orcamentoPrazoExecucao">Prazo de execução <span class="text-danger" aria-hidden="true">*</span></label>
                    @php
                        $prazoExecucaoDiasOptions = [1, 3, 7, 15, 30];
                        $formatPrazoExecucaoLabel = static fn (int $dias): string => $dias . ' dia' . ($dias === 1 ? '' : 's');
                        // Valor legado/vindo da OS (ex.: "Previsão: 25/07/2026") que não bate
                        // com nenhuma opção fixa: preservado como opção extra para não se perder.
                        $prazoExecucaoIsCustom = $prazoExecucaoValue !== ''
                            && ! in_array($prazoExecucaoValue, array_map($formatPrazoExecucaoLabel, $prazoExecucaoDiasOptions), true);
                    @endphp
                    <select id="orcamentoPrazoExecucao" name="prazo_execucao" class="form-select">
                        <option value=""></option>
                        @foreach ($prazoExecucaoDiasOptions as $prazoExecucaoDias)
                            @php $prazoExecucaoLabel = $formatPrazoExecucaoLabel($prazoExecucaoDias); @endphp
                            <option value="{{ $prazoExecucaoLabel }}" @selected($prazoExecucaoValue === $prazoExecucaoLabel)>{{ $prazoExecucaoLabel }}</option>
                        @endforeach
                        @if ($prazoExecucaoIsCustom)
                            <option value="{{ $prazoExecucaoValue }}" selected>{{ $prazoExecucaoValue }}</option>
                        @endif
                    </select>
                </div>

                <div class="desktop-grid-span-2">
                    <label for="orcamentoObservacoes">Observações</label>
                    <textarea id="orcamentoObservacoes" name="observacoes" class="form-control" rows="4" placeholder="Notas internas do orçamento">{{ old('observacoes', $budget['observacoes'] ?? '') }}</textarea>
                </div>
            </div>
        </div>

        <div class="equipment-tab-panel" data-budget-panel="financeiro">
            <div class="surface-card-header align-items-start mb-3">
                <div>
                    <h3 class="surface-title fs-5 mb-1">Itens do orçamento</h3>
                    <p class="surface-subtitle mb-0">Lance serviços cadastrados, peças do estoque ou itens avulsos sem cadastro vinculado.</p>
                </div>

                <button type="button" class="btn btn-primary" data-budget-item-add>
                    <i class="bi bi-plus-lg me-2"></i>
                    Adicionar item
                </button>
            </div>

            <div class="table-responsive mb-4">
                <table class="table align-middle budget-items-table">
                    <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Referência</th>
                        <th>Qtd</th>
                        <th>Valor unit.</th>
                        <th>Desconto</th>
                        <th>Total</th>
                        <th>Observações</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody data-budget-items>
                        @foreach ($selectedItems as $index => $item)
                            @include('orcamentos.partials.item-row', ['index' => $index, 'item' => $item, 'quickCatalogs' => $quickCatalogs])
                        @endforeach
                    </tbody>
                </table>
            </div>

            <template id="orcamentoItemTemplate">
                @include('orcamentos.partials.item-row', ['index' => '__INDEX__', 'item' => [], 'quickCatalogs' => $quickCatalogs])
            </template>

            <section class="budget-terms-card mb-4" data-budget-terms aria-labelledby="orcamentoCondicoesTitulo">
                <div class="surface-card-header align-items-start mb-3">
                    <div>
                        <p class="desktop-eyebrow mb-2">Transparência</p>
                        <h3 id="orcamentoCondicoesTitulo" class="surface-title fs-5 mb-1">Condições comerciais</h3>
                        <p class="surface-subtitle mb-0">
                            Marque o que será oferecido ao cliente. Formas de pagamento e chaves Pix vêm das
                            <a href="{{ route('financeiro.configuracoes') }}" target="_blank" rel="noopener">configurações financeiras</a>
                            e são impressas automaticamente no PDF do orçamento.
                        </p>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Formas de pagamento aceitas</label>

                    {{-- Marcador vazio: garante que desmarcar tudo chegue ao backend
                         como "nenhuma forma aceita" em vez de campo ausente (que
                         preservaria a seleção anterior). --}}
                    <input type="hidden" name="formas_pagamento[]" value="">

                    @forelse ($paymentMethodOptions as $option)
                        <div class="form-check form-check-inline">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="formas_pagamento[]"
                                value="{{ $option['codigo'] }}"
                                id="orcamentoForma{{ $option['id'] }}"
                                data-budget-payment-method
                                data-installments="{{ ($option['aceita_parcelamento'] ?? false) ? '1' : '0' }}"
                                data-pix="{{ ($option['is_pix'] ?? false) ? '1' : '0' }}"
                                {{ in_array((string) $option['codigo'], $selectedPaymentCodes, true) ? 'checked' : '' }}
                            >
                            <label class="form-check-label" for="orcamentoForma{{ $option['id'] }}">{{ $option['nome'] }}</label>
                        </div>
                    @empty
                        <p class="text-secondary mb-0">
                            Nenhuma forma de pagamento ativa cadastrada. Cadastre em Financeiro &gt; Configurações &gt; Formas de Pagamento.
                        </p>
                    @endforelse
                </div>

                <div class="desktop-grid desktop-grid-two">
                    <div data-budget-installments-wrapper class="{{ $selectedInstallments !== '' ? '' : 'd-none' }}">
                        <label for="orcamentoParcelas">Parcelamento sem juros no cartão</label>
                        <select id="orcamentoParcelas" name="parcelas_sem_juros" class="form-select" data-budget-installments>
                            <option value="">Somente à vista</option>
                            @for ($parcela = 2; $parcela <= $maxInstallments; $parcela++)
                                <option value="{{ $parcela }}" {{ $selectedInstallments === (string) $parcela ? 'selected' : '' }}>
                                    Em até {{ $parcela }}x sem juros
                                </option>
                            @endfor
                        </select>
                    </div>

                    <div>
                        <label for="orcamentoGarantia">Garantia</label>
                        <select id="orcamentoGarantia" name="garantia_dias" class="form-select">
                            <option value="">Sem garantia definida</option>
                            @foreach ($warrantyOptions as $option)
                                <option value="{{ $option['value'] }}" {{ $selectedWarrantyDays === (string) $option['value'] ? 'selected' : '' }}>
                                    {{ $option['label'] }}
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted d-block mt-1">Contada a partir da entrega do equipamento. Acompanha a OS na baixa.</small>
                    </div>
                </div>

                <div class="budget-terms-pix mt-3 {{ in_array('pix', $selectedPaymentCodes, true) ? '' : 'd-none' }}" data-budget-pix-preview>
                    <p class="fw-semibold mb-2"><i class="bi bi-key me-2"></i>Chaves Pix informadas ao cliente</p>
                    @forelse ($pixKeyOptions as $chave)
                        <div class="small">
                            <span data-budget-pix-key>{{ $chave['rotulo'] }}</span>
                            @if ($chave['principal'] ?? false)
                                <span class="badge text-bg-light border ms-1">Principal</span>
                            @endif
                        </div>
                    @empty
                        <p class="small text-secondary mb-0">
                            Nenhuma chave Pix cadastrada — o orçamento sairá sem chave. Cadastre em
                            Financeiro &gt; Configurações &gt; Formas de Pagamento &gt; Pix &gt; Chaves.
                        </p>
                    @endforelse
                </div>

                <div class="mt-3">
                    <label for="orcamentoCondicoes">Observações complementares</label>
                    <textarea id="orcamentoCondicoes" name="condicoes" class="form-control" rows="3" placeholder="Só o que fugir do padrão acima (ex.: entrada de 50%, retirada em loja)">{{ old('condicoes', $budget['condicoes'] ?? '') }}</textarea>
                </div>
            </section>

            <section class="budget-summary-card" aria-labelledby="orcamentoResumoFinanceiro">
                <div class="surface-card-header align-items-start budget-summary-card-header">
                    <div>
                        <p class="desktop-eyebrow mb-2">Fechamento</p>
                        <h3 id="orcamentoResumoFinanceiro" class="surface-title fs-5 mb-1">Resumo financeiro</h3>
                        <p class="surface-subtitle mb-0">Este card consolida o resultado final do orçamento com descontos, acréscimos e total validado.</p>
                    </div>

                    <div class="budget-summary-card-meta">
                        <span class="budget-summary-result-pill">Resultado final</span>
                        <span class="desktop-chip" data-budget-items-count>
                            <i class="bi bi-list-check"></i>
                            0 itens
                        </span>
                    </div>
                </div>

                <div class="desktop-grid desktop-grid-four budget-summary-grid">
                    <div>
                        <label for="orcamentoSubtotal">Subtotal</label>
                        <input type="text" id="orcamentoSubtotal" name="subtotal" class="form-control" value="{{ old('subtotal', $budget['subtotal'] ?? 0) }}" readonly data-budget-subtotal data-budget-money inputmode="decimal" autocomplete="off">
                    </div>

                    <div>
                        <label for="orcamentoDescontoDisplay">Desconto geral</label>
                        <div class="budget-adjustment-group" data-budget-adjustment-group>
                            <input
                                type="text"
                                id="orcamentoDescontoDisplay"
                                class="form-control budget-adjustment-input"
                                value="{{ $globalDiscountDisplay }}"
                                inputmode="decimal"
                                autocomplete="off"
                                data-budget-global-discount-display
                            >
                            <div class="budget-adjustment-toggle" role="group" aria-label="Modo do desconto geral">
                                <button
                                    type="button"
                                    class="budget-adjustment-toggle-btn {{ $globalDiscountType === 'valor' ? 'is-active' : '' }}"
                                    data-budget-adjustment-option="valor"
                                    aria-pressed="{{ $globalDiscountType === 'valor' ? 'true' : 'false' }}"
                                >R$</button>
                                <button
                                    type="button"
                                    class="budget-adjustment-toggle-btn {{ $globalDiscountType === 'percentual' ? 'is-active' : '' }}"
                                    data-budget-adjustment-option="percentual"
                                    aria-pressed="{{ $globalDiscountType === 'percentual' ? 'true' : 'false' }}"
                                >%</button>
                            </div>
                        </div>
                        <div class="budget-adjustment-preview" data-budget-global-discount-preview-wrapper @if (! $globalDiscountPreviewVisible) hidden @endif>
                            <label class="budget-adjustment-preview-label" for="orcamentoDescontoPreview">Valor do desconto</label>
                            <input
                                type="text"
                                id="orcamentoDescontoPreview"
                                class="form-control budget-adjustment-preview-input"
                                value="{{ $formatMoney($globalDiscountAmount) }}"
                                readonly
                                tabindex="-1"
                                data-budget-global-discount-preview
                            >
                        </div>
                        <input type="hidden" id="orcamentoDescontoTipo" name="desconto_tipo" value="{{ $globalDiscountType }}" data-budget-global-discount-type>
                        <input type="hidden" name="desconto" value="{{ $formatDecimalValue($globalDiscountAmount, 2) }}" data-budget-global-discount>
                        <input type="hidden" name="desconto_percentual" value="{{ $formatDecimalValue($globalDiscountPercent, 4) }}" data-budget-global-discount-percent>
                    </div>

                    <div>
                        <label for="orcamentoAcrescimoDisplay">Acréscimo geral</label>
                        <div class="budget-adjustment-group" data-budget-adjustment-group>
                            <input
                                type="text"
                                id="orcamentoAcrescimoDisplay"
                                class="form-control budget-adjustment-input"
                                value="{{ $globalAdditionDisplay }}"
                                inputmode="decimal"
                                autocomplete="off"
                                data-budget-global-addition-display
                            >
                            <div class="budget-adjustment-toggle" role="group" aria-label="Modo do acréscimo geral">
                                <button
                                    type="button"
                                    class="budget-adjustment-toggle-btn {{ $globalAdditionType === 'valor' ? 'is-active' : '' }}"
                                    data-budget-adjustment-option="valor"
                                    aria-pressed="{{ $globalAdditionType === 'valor' ? 'true' : 'false' }}"
                                >R$</button>
                                <button
                                    type="button"
                                    class="budget-adjustment-toggle-btn {{ $globalAdditionType === 'percentual' ? 'is-active' : '' }}"
                                    data-budget-adjustment-option="percentual"
                                    aria-pressed="{{ $globalAdditionType === 'percentual' ? 'true' : 'false' }}"
                                >%</button>
                            </div>
                        </div>
                        <div class="budget-adjustment-preview" data-budget-global-addition-preview-wrapper @if (! $globalAdditionPreviewVisible) hidden @endif>
                            <label class="budget-adjustment-preview-label" for="orcamentoAcrescimoPreview">Valor do acréscimo</label>
                            <input
                                type="text"
                                id="orcamentoAcrescimoPreview"
                                class="form-control budget-adjustment-preview-input"
                                value="{{ $formatMoney($globalAdditionAmount) }}"
                                readonly
                                tabindex="-1"
                                data-budget-global-addition-preview
                            >
                        </div>
                        <input type="hidden" id="orcamentoAcrescimoTipo" name="acrescimo_tipo" value="{{ $globalAdditionType }}" data-budget-global-addition-type>
                        <input type="hidden" name="acrescimo" value="{{ $formatDecimalValue($globalAdditionAmount, 2) }}" data-budget-global-addition>
                        <input type="hidden" name="acrescimo_percentual" value="{{ $formatDecimalValue($globalAdditionPercent, 4) }}" data-budget-global-addition-percent>
                    </div>

                    <div class="budget-summary-total-field">
                        <label for="orcamentoTotal">Total final</label>
                        <input type="text" id="orcamentoTotal" name="total" class="form-control" value="{{ old('total', $budget['total'] ?? 0) }}" readonly data-budget-total data-budget-money inputmode="decimal" autocomplete="off">
                    </div>
                </div>
            </section>
        </div>

        <div class="desktop-form-actions">
            <a href="{{ $cancelUrl ?? route('orcamentos.index') }}" class="btn btn-outline-light">Cancelar</a>
            <button
                type="{{ $isEditMode ? 'submit' : 'button' }}"
                class="btn btn-primary"
                data-budget-primary-action
                data-budget-next-label="Próximo"
                data-budget-submit-label="{{ $submitLabel ?? ($isEditMode ? 'Salvar alterações' : 'Criar orçamento') }}"
            >
                <i class="bi {{ $isEditMode ? 'bi-check2-circle' : 'bi-arrow-right-circle' }} me-2" data-budget-primary-action-icon aria-hidden="true"></i>
                <span data-budget-primary-action-label>{{ $isEditMode ? ($submitLabel ?? 'Salvar alterações') : 'Próximo' }}</span>
            </button>
        </div>
    </form>
</section>

@push('modals')
    @if ($osIsEncerrada)
        @include('orcamentos._admin_confirm_modal')
    @endif

    @if ($canCreateEquipment)
        {{-- Mesmo formulario embutido usado pelo wizard da OS: tipo -> marca ->
             modelo com criacao inline de cada nivel. `pendente=1` faz a foto
             virar opcional e marca o cadastro como incompleto. --}}
        <div
            class="modal fade"
            id="orcamentoEquipmentModal"
            tabindex="-1"
            aria-hidden="true"
            data-bs-backdrop="static"
            data-bs-keyboard="false"
            data-budget-equipment-create-url="{{ route('equipments.create', ['embedded' => 1, 'pendente' => 1]) }}"
        >
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content modal-shell">
                    <div class="modal-header">
                        <div>
                            <p class="desktop-eyebrow mb-1">Equipamentos</p>
                            <h5 class="modal-title mb-0">Cadastrar equipamento do cliente</h5>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body p-0">
                        <iframe
                            title="Cadastro de equipamento"
                            class="w-100 border-0"
                            style="height: 72vh;"
                            data-budget-equipment-frame
                        ></iframe>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($canQuickClient)
        @include('clients.quick-modal', [
            'fullCreateUrl' => route('clients.create'),
            'quickStoreUrl' => route('clients.quick.store'),
        ])
    @endif

    @if ($canQuickCatalog)
        @include('orcamentos.partials.quick-item-modal', [
            'quickCatalogs' => $quickCatalogs,
            'tiposEquipamento' => data_get($form, 'tipos_equipamento', []),
        ])
    @endif

    <div class="modal fade" id="orcamentoDraftModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-shell">
                <div class="modal-body p-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="desktop-brand-mark">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div>
                            <h4 class="surface-title fs-5 mb-1">Rascunho automático</h4>
                            <p class="surface-subtitle mb-0">O sistema encontrou dados locais salvos para este orçamento.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="orcamentoReviewModal" tabindex="-1" aria-labelledby="orcamentoReviewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content modal-shell budget-review-modal">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <p class="desktop-eyebrow mb-2">Revisao final</p>
                        <h4 id="orcamentoReviewModalLabel" class="surface-title fs-4 mb-1">Confirmar salvamento do orcamento</h4>
                        <p class="surface-subtitle mb-0">Revise os dados, confira as pendencias e escolha entre salvar ou enviar para aprovacao.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <div class="budget-review-pendencies d-none" data-budget-review-pendencies-wrapper>
                        <div class="budget-review-pendencies-head">
                            <i class="bi bi-exclamation-triangle"></i>
                            <div>
                                <strong>Existem pendências que impedem a conclusão do orçamento.</strong>
                                <p class="mb-0">Volte ao formulário e preencha os campos obrigatórios indicados.</p>
                            </div>
                        </div>
                        <ul class="mb-0" data-budget-review-pendencies></ul>
                    </div>

                    <div class="budget-review-grid">
                        <div class="budget-review-col">
                            <section class="budget-review-card">
                                <div class="budget-review-card-head">
                                    <h5>Cliente e contato</h5>
                                    <span class="desktop-chip">Comercial</span>
                                </div>
                                <div class="budget-review-list" data-budget-review-client></div>
                            </section>

                            <section class="budget-review-card">
                                <div class="budget-review-card-head">
                                    <h5>Observacoes e condicoes</h5>
                                    <span class="desktop-chip">Complementos</span>
                                </div>
                                <div class="budget-review-notes" data-budget-review-notes></div>
                            </section>
                        </div>

                        <section class="budget-review-card">
                            <div class="budget-review-card-head">
                                <h5>Equipamento e contexto</h5>
                                <span class="desktop-chip">Operacional</span>
                            </div>
                            <div class="budget-review-list" data-budget-review-context></div>
                        </section>
                    </div>

                    <section class="budget-review-card">
                        <div class="budget-review-card-head">
                            <h5>Condicoes comerciais</h5>
                            <span class="desktop-chip">Transparencia ao cliente</span>
                        </div>
                        <div class="budget-review-list" data-budget-review-terms></div>
                    </section>

                    <section class="budget-review-card">
                        <div class="budget-review-card-head">
                            <h5>Itens do orcamento</h5>
                            <span class="desktop-chip" data-budget-review-items-count>0 itens</span>
                        </div>
                        <div class="budget-review-items" data-budget-review-items></div>
                    </section>

                    <section class="budget-review-card budget-review-grid-bottom">
                        <div class="budget-review-card-head">
                            <h5>Resultado financeiro</h5>
                            <span class="budget-summary-result-pill">Resultado final</span>
                        </div>
                        <div class="budget-review-totals" data-budget-review-totals></div>
                    </section>
                </div>

                <div class="modal-footer border-0 pt-0">
                    <div class="budget-review-footer-copy">
                        Salvar sem envio mantem o orcamento interno. Enviar para aprovacao gera o PDF e a proposta do cliente.
                    </div>
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Voltar e revisar</button>
                    <button type="button" class="btn btn-outline-secondary" data-budget-review-submit="save_only">Salvar sem enviar</button>
                    <button type="button" class="btn btn-primary" data-budget-review-submit="send_for_approval">Salvar e enviar para aprovacao</button>
                </div>
            </div>
        </div>
    </div>
@endpush
