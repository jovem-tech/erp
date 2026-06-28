@extends('layouts.app')

@section('content')
    @php
        $orderId = (int) ($order['id'] ?? 0);
        $selectedClientId = (int) ($order['cliente_id'] ?? 0);
        $selectedEquipmentId = (int) ($order['equipamento_id'] ?? 0);
        $canCreateClient = \App\Support\DesktopSession::can('clientes', 'criar');
    @endphp

    <section class="desktop-form-card">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Editar OS {{ $order['numero_os'] ?? ('#' . $orderId) }}</h2>
                <p class="surface-subtitle">Atualização direta via backend central, sem acesso ao banco pelo desktop.</p>
            </div>
        </div>

        <form method="post" action="{{ route('orders.update', $orderId) }}" class="desktop-grid desktop-grid-two">
            @csrf
            @method('PATCH')

            <div>
                <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                    <label for="clienteId" class="mb-0">Cliente</label>
                    @if ($canCreateClient)
                        <button type="button" id="btnNovoClienteRapido" class="btn btn-soft btn-sm">
                            <i class="bi bi-person-plus me-1"></i>
                            Novo cliente
                        </button>
                    @endif
                </div>
                <select id="clienteId" name="cliente_id" class="form-select" required>
                    <option value="">Selecione</option>
                    @foreach (($clients ?? []) as $client)
                        <option value="{{ $client['id'] }}" @selected($selectedClientId === (int) $client['id'])>
                            {{ $client['nome_razao'] ?? 'Cliente' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="equipamentoId">Equipamento</label>
                <select id="equipamentoId" name="equipamento_id" class="form-select" required>
                    <option value="">Selecione</option>
                    @foreach (($equipments ?? []) as $equipment)
                        <option value="{{ $equipment['id'] }}" @selected($selectedEquipmentId === (int) $equipment['id'])>
                            {{ $equipment['resumo_tecnico'] ?? 'Equipamento' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="desktop-grid-span-2">
                <label for="relatoCliente">Relato do cliente</label>
                <textarea id="relatoCliente" name="relato_cliente" class="form-control" rows="5" required>{{ old('relato_cliente', $order['relato_cliente'] ?? '') }}</textarea>
            </div>

            <div>
                <label for="prioridade">Prioridade</label>
                @php $prioridadeAtual = old('prioridade', $order['prioridade'] ?? 'normal'); @endphp
                <select id="prioridade" name="prioridade" class="form-select">
                    <option value="baixa" @selected($prioridadeAtual === 'baixa')>Baixa</option>
                    <option value="normal" @selected($prioridadeAtual === 'normal')>Normal</option>
                    <option value="alta" @selected($prioridadeAtual === 'alta')>Alta</option>
                    <option value="urgente" @selected($prioridadeAtual === 'urgente')>Urgente</option>
                </select>
            </div>

            <div>
                <label for="dataPrevisao">Data de previsão</label>
                <input type="date" id="dataPrevisao" name="data_previsao" class="form-control" value="{{ old('data_previsao', $order['data_previsao'] ?? '') }}">
            </div>

            <div class="desktop-grid-span-2">
                <label for="observacoesInternas">Observações internas</label>
                <textarea id="observacoesInternas" name="observacoes_internas" class="form-control" rows="4">{{ old('observacoes_internas', $order['observacoes_internas'] ?? '') }}</textarea>
            </div>

            <div class="desktop-grid-span-2 d-flex justify-content-end gap-2">
                <a href="{{ route('orders.show', $orderId) }}" class="btn btn-outline-light">Cancelar</a>
                <button type="submit" class="btn btn-primary">Salvar alterações</button>
            </div>
        </form>
    </section>

    @if ($canCreateClient)
        @push('modals')
            @include('clients.quick-modal', [
                'fullCreateUrl' => route('clients.create'),
            ])
        @endpush
    @endif
@endsection

@section('scripts')
    @if ($canCreateClient)
        <script>
            window.__DESKTOP_ORDER_CREATE = {!! json_encode([
                'quickClientStoreUrl' => route('clients.quick.store'),
                'clientSelectId' => 'clienteId',
                'equipmentSelectId' => 'equipamentoId',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
        </script>
        <script src="{{ asset('assets/js/orders-create.js') }}"></script>
        <script src="{{ asset('assets/js/clients-form.js') }}?v={{ filemtime(public_path('assets/js/clients-form.js')) }}"></script>
    @endif
@endsection
