@php
    $budget = is_array($budget ?? null) ? $budget : [];
    $form = is_array($form ?? null) ? $form : [];

    $clients = $form['clients'] ?? [];
    $equipments = $form['equipments'] ?? [];
    $orders = $form['orders'] ?? [];
    $services = $form['services'] ?? [];
    $parts = $form['parts'] ?? [];
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

    <form
        method="post"
        action="{{ $formAction }}"
        class="desktop-form-stack desktop-budget-form"
        data-budget-form
        data-budget-draft-key="{{ $draftKey }}"
        data-budget-is-edit="{{ $isEditMode ? '1' : '0' }}"
    >
        @csrf
        @if ($formMethod !== 'POST')
            @method($formMethod)
        @endif
        <input type="hidden" name="submission_mode" value="save_only" data-budget-submission-mode>

        <input type="hidden" name="numero" value="{{ old('numero', $budget['numero'] ?? '') }}">
        <input type="hidden" name="versao" value="{{ old('versao', $budget['versao'] ?? 1) }}">

        <div class="equipment-tabs" role="tablist" aria-label="Etapas do orçamento">
            <button type="button" class="equipment-tab is-active" data-budget-tab="cliente" aria-pressed="true">
                <i class="bi bi-person-vcard"></i>
                Dados do cliente
            </button>
            <button type="button" class="equipment-tab" data-budget-tab="equipamento" aria-pressed="false">
                <i class="bi bi-laptop"></i>
                Dados do equipamento
            </button>
            <button type="button" class="equipment-tab" data-budget-tab="operacional" aria-pressed="false">
                <i class="bi bi-sliders"></i>
                Dados operacionais
            </button>
            <button type="button" class="equipment-tab" data-budget-tab="financeiro" aria-pressed="false">
                <i class="bi bi-cash-coin"></i>
                Orçamento e financeiro
            </button>
        </div>

        <div class="equipment-tab-panel is-active" data-budget-panel="cliente">
            <div class="desktop-grid desktop-grid-two">
                <div>
                    <label for="orcamentoClienteId">Cliente cadastrado</label>
                    <select id="orcamentoClienteId" name="{{ $clientLocked ? '' : 'cliente_id' }}" class="form-select" data-select2-placeholder="Selecione um cliente..." data-select2-allow-clear="true" @disabled($clientLocked)>
                        <option value=""></option>
                        @foreach ($clients as $client)
                            @php
                                $clientId = (int) ($client['id'] ?? 0);
                                $clientName = trim((string) ($client['nome_razao'] ?? ''));
                                $clientDocument = trim((string) ($client['cpf_cnpj'] ?? ''));
                                $clientPhone = trim((string) ($client['telefone1'] ?? ''));
                                $clientLabel = implode(' - ', array_values(array_filter([$clientName, $clientDocument, $clientPhone])));
                            @endphp
                            <option value="{{ $clientId }}" @selected($selectedClientId === $clientId)>{{ $clientLabel !== '' ? $clientLabel : 'Cliente' }}</option>
                        @endforeach
                    </select>
                    @if ($clientLocked)
                        <input type="hidden" name="cliente_id" value="{{ $selectedClientId }}">
                    @else
                        <small class="text-secondary d-block mt-2">Se o cliente ainda não existir, preencha os dados avulsos abaixo.</small>
                    @endif
                </div>

                <div>
                    <label for="orcamentoClienteAvulso">Nome do cliente eventual</label>
                    <input type="text" id="orcamentoClienteAvulso" name="cliente_nome_avulso" class="form-control" value="{{ old('cliente_nome_avulso', $budget['cliente_nome_avulso'] ?? '') }}" placeholder="Preencher apenas se não houver cadastro" @disabled($clientLocked)>
                </div>

                <div>
                    <label for="orcamentoTelefoneContato">Telefone de contato</label>
                    <input type="text" id="orcamentoTelefoneContato" name="telefone_contato" class="form-control" value="{{ old('telefone_contato', $budget['telefone_contato'] ?? ($form['selected_client_phone'] ?? '')) }}" placeholder="(11) 98765-4321">
                </div>

                <div>
                    <label for="orcamentoEmailContato">E-mail de contato</label>
                    <input type="email" id="orcamentoEmailContato" name="email_contato" class="form-control" value="{{ old('email_contato', $budget['email_contato'] ?? ($form['selected_client_email'] ?? '')) }}" placeholder="cliente@dominio.com">
                </div>
            </div>
        </div>

        <div class="equipment-tab-panel" data-budget-panel="equipamento">
            <div class="desktop-grid desktop-grid-two">
                <div>
                    <label for="orcamentoOsId">OS vinculada</label>
                    <select id="orcamentoOsId" name="os_id" class="form-select" data-select2-placeholder="Selecione uma OS..." data-select2-allow-clear="true">
                        <option value=""></option>
                        @foreach ($orders as $order)
                            @php
                                $orderId = (int) ($order['id'] ?? 0);
                                $orderLabel = trim((string) ($order['numero_os'] ?? 'OS #' . $orderId));
                                $orderClient = trim((string) ($order['cliente_nome'] ?? ''));
                            @endphp
                            <option value="{{ $orderId }}" @selected($selectedOrderId === $orderId)>{{ $orderLabel }}{{ $orderClient !== '' ? ' - ' . $orderClient : '' }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="orcamentoEquipamentoId">Equipamento</label>
                    <select id="orcamentoEquipamentoId" name="equipamento_id" class="form-select" data-select2-placeholder="Selecione um equipamento..." data-select2-allow-clear="true">
                        <option value=""></option>
                        @foreach ($equipments as $equipment)
                            @php
                                $equipmentId = (int) ($equipment['id'] ?? 0);
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
                            @endphp
                            <option value="{{ $equipmentId }}" @selected($selectedEquipmentId === $equipmentId)>{{ $equipmentLabel !== '' ? $equipmentLabel : 'Equipamento #' . $equipmentId }}{{ $serial !== '' ? ' · S/N ' . $serial : '' }}{{ $clientName !== '' ? ' · ' . $clientName : '' }}</option>
                        @endforeach
                    </select>
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
                <div>
                    <label for="orcamentoTipo">Tipo de orçamento</label>
                    <select id="orcamentoTipo" name="tipo_orcamento" class="form-select">
                        @foreach ($typeOptions as $typeOption)
                            <option value="{{ $typeOption['value'] ?? '' }}" @selected($typeValue === ($typeOption['value'] ?? ''))>{{ $typeOption['label'] ?? 'Tipo' }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="orcamentoOrigem">Origem</label>
                    <select id="orcamentoOrigem" name="origem" class="form-select">
                        @foreach ($originOptions as $originOption)
                            <option value="{{ $originOption['value'] ?? '' }}" @selected($originValue === ($originOption['value'] ?? ''))>{{ $originOption['label'] ?? 'Origem' }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="orcamentoStatus">Status</label>
                    <select id="orcamentoStatus" name="status" class="form-select">
                        @foreach ($statusOptions as $statusOption)
                            <option value="{{ $statusOption['value'] ?? '' }}" @selected($statusValue === ($statusOption['value'] ?? ''))>
                                {{ $statusOption['label'] ?? 'Status' }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="orcamentoPrazoExecucao">Prazo de execução</label>
                    <input type="text" id="orcamentoPrazoExecucao" name="prazo_execucao" class="form-control" value="{{ $prazoExecucaoValue }}" placeholder="Ex.: 3 dias úteis">
                </div>

                <div class="desktop-grid-span-2">
                    <label for="orcamentoObservacoes">Observações</label>
                    <textarea id="orcamentoObservacoes" name="observacoes" class="form-control" rows="4" placeholder="Notas internas do orçamento">{{ old('observacoes', $budget['observacoes'] ?? '') }}</textarea>
                </div>

                <div class="desktop-grid-span-2">
                    <label for="orcamentoCondicoes">Condições comerciais</label>
                    <textarea id="orcamentoCondicoes" name="condicoes" class="form-control" rows="4" placeholder="Condições, garantias e observações de pagamento">{{ old('condicoes', $budget['condicoes'] ?? '') }}</textarea>
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
        </div>

        <div class="desktop-form-actions">
            <a href="{{ $cancelUrl ?? route('orcamentos.index') }}" class="btn btn-outline-light">Cancelar</a>
            <button type="submit" class="btn btn-primary">{{ $submitLabel ?? 'Salvar orçamento' }}</button>
        </div>
    </form>
</section>

@push('modals')
    @if ($canQuickCatalog)
        @include('orcamentos.partials.quick-item-modal', ['quickCatalogs' => $quickCatalogs])
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
                                <strong>Existem pendencias que bloqueiam o envio para aprovacao.</strong>
                                <p class="mb-0">Voce ainda pode salvar sem enviar o PDF agora.</p>
                            </div>
                        </div>
                        <ul class="mb-0" data-budget-review-pendencies></ul>
                    </div>

                    <div class="budget-review-grid">
                        <section class="budget-review-card">
                            <div class="budget-review-card-head">
                                <h5>Cliente e contato</h5>
                                <span class="desktop-chip">Comercial</span>
                            </div>
                            <div class="budget-review-list" data-budget-review-client></div>
                        </section>

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
                            <h5>Itens do orcamento</h5>
                            <span class="desktop-chip" data-budget-review-items-count>0 itens</span>
                        </div>
                        <div class="budget-review-items" data-budget-review-items></div>
                    </section>

                    <div class="budget-review-grid budget-review-grid-bottom">
                        <section class="budget-review-card">
                            <div class="budget-review-card-head">
                                <h5>Resultado financeiro</h5>
                                <span class="budget-summary-result-pill">Resultado final</span>
                            </div>
                            <div class="budget-review-totals" data-budget-review-totals></div>
                        </section>

                        <section class="budget-review-card">
                            <div class="budget-review-card-head">
                                <h5>Observacoes e condicoes</h5>
                                <span class="desktop-chip">Complementos</span>
                            </div>
                            <div class="budget-review-notes" data-budget-review-notes></div>
                        </section>
                    </div>
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
