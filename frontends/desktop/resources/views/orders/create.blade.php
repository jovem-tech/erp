@extends('layouts.app')

@section('content')
    @php
        $selectedClientId = (int) ($selectedClientId ?? 0);
        $selectedEquipmentId = (int) ($selectedEquipmentId ?? 0);
        $canCreateClient = \App\Support\DesktopSession::can('clientes', 'criar');
    @endphp

    <section class="desktop-form-card">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Nova OS</h2>
                <p class="surface-subtitle">Criação direta via backend central, sem acesso ao banco pelo desktop.</p>
            </div>
        </div>

        <form method="post" action="{{ route('orders.store') }}" class="desktop-grid desktop-grid-two">
            @csrf

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
                @if ($canCreateClient)
                    <small class="text-secondary d-block mt-2">
                        Se o cliente ainda não estiver cadastrado, abra o cadastro rápido sem sair da OS.
                    </small>
                @endif
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
                <textarea id="relatoCliente" name="relato_cliente" class="form-control" rows="5" required placeholder="Descreva o problema relatado pelo cliente"></textarea>
            </div>

            <div>
                <label for="prioridade">Prioridade</label>
                <select id="prioridade" name="prioridade" class="form-select">
                    <option value="">Normal</option>
                    <option value="baixa">Baixa</option>
                    <option value="normal" selected>Normal</option>
                    <option value="alta">Alta</option>
                    <option value="urgente">Urgente</option>
                </select>
            </div>

            <div>
                <label for="dataPrevisao">Data de previsão</label>
                <input type="date" id="dataPrevisao" name="data_previsao" class="form-control">
            </div>

            <div class="desktop-grid-span-2">
                <label for="observacoesInternas">Observações internas</label>
                <textarea id="observacoesInternas" name="observacoes_internas" class="form-control" rows="4" placeholder="Notas internas opcionais"></textarea>
            </div>

            <div class="desktop-grid-span-2 d-flex justify-content-end gap-2">
                <a href="{{ route('orders.index') }}" class="btn btn-outline-light">Cancelar</a>
                <button type="submit" class="btn btn-primary">Criar OS</button>
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
