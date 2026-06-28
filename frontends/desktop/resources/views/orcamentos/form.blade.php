@php
    $budget = is_array($budget ?? null) ? $budget : [];
    $form = is_array($form ?? null) ? $form : [];

    $clients = $form['clients'] ?? [];
    $equipments = $form['equipments'] ?? [];
    $orders = $form['orders'] ?? [];
    $services = $form['services'] ?? [];
    $parts = $form['parts'] ?? [];
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
            'acrescimo' => 0,
            'observacoes' => '',
            'modo_precificacao' => 'manual',
        ]];
    }

    $formMethod = strtoupper((string) ($formMethod ?? ($isEditMode ? 'PATCH' : 'POST')));
    $budgetId = (int) ($budget['id'] ?? 0);
    $draftKey = 'orcamentos:' . ($isEditMode ? 'edit:' . $budgetId : 'create');
    $selectedClientId = (int) old('cliente_id', $form['selected_client_id'] ?? ($budget['cliente']['id'] ?? $budget['cliente_id'] ?? 0));
    $selectedOrderId = (int) old('os_id', $form['selected_order_id'] ?? ($budget['os']['id'] ?? $budget['os_id'] ?? 0));
    $selectedEquipmentId = (int) old('equipamento_id', $budget['equipamento']['id'] ?? $budget['equipamento_id'] ?? 0);
    $statusValue = old('status', $budget['status'] ?? 'rascunho');
    $typeValue = old('tipo_orcamento', $budget['tipo_orcamento'] ?? 'previo');
    $originValue = old('origem', $budget['origem'] ?? 'manual');
@endphp

<section class="desktop-form-card">
    <div class="surface-card-header align-items-start">
        <div>
            <h2 class="surface-title mb-1">{{ $formTitle ?? 'Orçamento' }}</h2>
            <p class="surface-subtitle mb-0">
                {{ $formSubtitle ?? 'Fluxo comercial completo com catálogo de clientes, equipamentos, OS, serviços e peças, tudo mediado pela API central.' }}
            </p>
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
            <button type="button" class="equipment-tab" data-budget-tab="pacotes" aria-pressed="false">
                <i class="bi bi-boxes"></i>
                Pacotes de serviço
            </button>
            <button type="button" class="equipment-tab" data-budget-tab="financeiro" aria-pressed="false">
                <i class="bi bi-cash-coin"></i>
                Orçamento e financeiro
            </button>
        </div>

        <div class="equipment-tab-panel is-active" data-budget-panel="cliente">
            <div class="desktop-grid desktop-grid-two">
                <div class="desktop-grid-span-2">
                    <label for="orcamentoClienteId">Cliente cadastrado</label>
                    <select id="orcamentoClienteId" name="cliente_id" class="form-select" data-select2-placeholder="Selecione um cliente..." data-select2-allow-clear="true">
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
                    <small class="text-secondary d-block mt-2">Se o cliente ainda não existir, preencha os dados avulsos abaixo.</small>
                </div>

                <div class="desktop-grid-span-2">
                    <label for="orcamentoClienteAvulso">Nome do cliente eventual</label>
                    <input type="text" id="orcamentoClienteAvulso" name="cliente_nome_avulso" class="form-control" value="{{ old('cliente_nome_avulso', $budget['cliente_nome_avulso'] ?? '') }}" placeholder="Preencher apenas se não houver cadastro">
                </div>

                <div>
                    <label for="orcamentoTelefoneContato">Telefone de contato</label>
                    <input type="text" id="orcamentoTelefoneContato" name="telefone_contato" class="form-control" value="{{ old('telefone_contato', $budget['telefone_contato'] ?? '') }}" placeholder="(11) 98765-4321">
                </div>

                <div>
                    <label for="orcamentoEmailContato">E-mail de contato</label>
                    <input type="email" id="orcamentoEmailContato" name="email_contato" class="form-control" value="{{ old('email_contato', $budget['email_contato'] ?? '') }}" placeholder="cliente@dominio.com">
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
                                $equipmentLabel = trim((string) ($equipment['resumo_tecnico'] ?? ''));
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
                    <input type="number" id="orcamentoValidadeDias" name="validade_dias" class="form-control" min="0" step="1" value="{{ old('validade_dias', $budget['validade_dias'] ?? $form['default_validity_days'] ?? 10) }}">
                </div>

                <div>
                    <label for="orcamentoValidadeData">Validade até</label>
                    <input type="date" id="orcamentoValidadeData" name="validade_data" class="form-control" value="{{ old('validade_data', ! empty($budget['validade_data']) ? \Illuminate\Support\Carbon::parse($budget['validade_data'])->format('Y-m-d') : '') }}">
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
                    <input type="text" id="orcamentoPrazoExecucao" name="prazo_execucao" class="form-control" value="{{ old('prazo_execucao', $budget['prazo_execucao'] ?? '') }}" placeholder="Ex.: 3 dias úteis">
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

        <div class="equipment-tab-panel" data-budget-panel="pacotes">
            <div class="surface-card-header align-items-start mb-3">
                <div>
                    <h3 class="surface-title fs-5 mb-1">Pacotes de serviço</h3>
                    <p class="surface-subtitle mb-0">Selecione serviços e peças do catálogo do backend e monte o orçamento sem sair da tela.</p>
                </div>

                <button type="button" class="btn btn-primary" data-budget-item-add>
                    <i class="bi bi-plus-lg me-2"></i>
                    Adicionar item
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Referência</th>
                        <th>Descrição</th>
                        <th>Qtd</th>
                        <th>Valor unit.</th>
                        <th>Desconto</th>
                        <th>Acréscimo</th>
                        <th>Total</th>
                        <th>Observações</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody data-budget-items>
                        @foreach ($selectedItems as $index => $item)
                            @include('orcamentos.partials.item-row', ['index' => $index, 'item' => $item])
                        @endforeach
                    </tbody>
                </table>
            </div>

            <template id="orcamentoItemTemplate">
                @include('orcamentos.partials.item-row', ['index' => '__INDEX__', 'item' => []])
            </template>
        </div>

        <div class="equipment-tab-panel" data-budget-panel="financeiro">
            <div class="desktop-grid desktop-grid-two">
                <div class="desktop-grid-span-2">
                    <div class="surface-card-header align-items-start mb-2">
                        <div>
                            <h3 class="surface-title fs-5 mb-1">Resumo financeiro</h3>
                            <p class="surface-subtitle mb-0">Os valores são recalculados automaticamente no navegador e validados pelo backend.</p>
                        </div>
                        <span class="desktop-chip" data-budget-items-count>
                            <i class="bi bi-list-check"></i>
                            0 itens
                        </span>
                    </div>
                </div>

                <div>
                    <label for="orcamentoSubtotal">Subtotal</label>
                    <input type="number" id="orcamentoSubtotal" name="subtotal" class="form-control" min="0" step="0.01" value="{{ old('subtotal', $budget['subtotal'] ?? 0) }}" readonly data-budget-subtotal>
                </div>

                <div>
                    <label for="orcamentoDesconto">Desconto geral</label>
                    <input type="number" id="orcamentoDesconto" name="desconto" class="form-control" min="0" step="0.01" value="{{ old('desconto', $budget['desconto'] ?? 0) }}" data-budget-global-discount>
                </div>

                <div>
                    <label for="orcamentoAcrescimo">Acréscimo geral</label>
                    <input type="number" id="orcamentoAcrescimo" name="acrescimo" class="form-control" min="0" step="0.01" value="{{ old('acrescimo', $budget['acrescimo'] ?? 0) }}" data-budget-global-addition>
                </div>

                <div>
                    <label for="orcamentoTotal">Total</label>
                    <input type="number" id="orcamentoTotal" name="total" class="form-control" min="0" step="0.01" value="{{ old('total', $budget['total'] ?? 0) }}" readonly data-budget-total>
                </div>
            </div>
        </div>

        <div class="desktop-form-actions">
            <a href="{{ $cancelUrl ?? route('orcamentos.index') }}" class="btn btn-outline-light">Cancelar</a>
            <button type="submit" class="btn btn-primary">{{ $submitLabel ?? 'Salvar orçamento' }}</button>
        </div>
    </form>
</section>

@push('modals')
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
@endpush
