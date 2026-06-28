@extends('layouts.app')

@section('content')
    @php
        $part = array_merge([
            'id' => 0,
            'codigo' => '',
            'nome' => '',
            'quantidade_atual' => 0,
            'preco_custo' => 0,
            'preco_venda' => 0,
        ], is_array($part ?? null) ? $part : []);
    @endphp

    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Movimentações</h2>
                <p class="surface-subtitle">Histórico de entrada, saída e ajuste da peça selecionada.</p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('estoque.help') }}" class="btn btn-outline-info">
                    <i class="bi bi-question-circle me-2"></i>Ajuda
                </a>
                <a href="{{ route('estoque.index') }}" class="btn btn-outline-light">
                    <i class="bi bi-arrow-left me-2"></i>Voltar
                </a>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-4">
                <div class="surface-card p-3 h-100">
                    <div class="text-secondary small">Peça</div>
                    <strong class="d-block">{{ trim((string) ($part['nome'] ?? '')) !== '' ? $part['nome'] : 'Sem nome' }}</strong>
                    <div class="text-secondary small mt-2">Código: {{ trim((string) ($part['codigo'] ?? '')) !== '' ? $part['codigo'] : '-' }}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="surface-card p-3 h-100">
                    <div class="text-secondary small">Quantidade atual</div>
                    <strong class="d-block display-6">{{ (int) ($part['quantidade_atual'] ?? 0) }}</strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="surface-card p-3 h-100">
                    <div class="text-secondary small">Valores</div>
                    <strong class="d-block">Custo: R$ {{ number_format((float) ($part['preco_custo'] ?? 0), 2, ',', '.') }}</strong>
                    <strong class="d-block">Venda: R$ {{ number_format((float) ($part['preco_venda'] ?? 0), 2, ',', '.') }}</strong>
                </div>
            </div>
        </div>
    </section>

    <section class="desktop-form-card mb-4">
        <div class="surface-card-header">
            <div>
                <h3 class="surface-title">Registrar movimentação</h3>
                <p class="surface-subtitle">Informe entrada, saída ou ajuste de saldo para a peça atual.</p>
            </div>
        </div>

        <form method="post" action="{{ route('estoque.movements.store', $part['id']) }}" class="desktop-form-grid">
            @csrf
            <div>
                <label for="tipo">Tipo</label>
                <select id="tipo" name="tipo" class="form-select @error('tipo') is-invalid @enderror">
                    <option value="entrada" @selected(old('tipo') === 'entrada')>Entrada</option>
                    <option value="saida" @selected(old('tipo') === 'saida')>Saída</option>
                    <option value="ajuste" @selected(old('tipo') === 'ajuste')>Ajuste</option>
                </select>
                @error('tipo')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="quantidade">Quantidade</label>
                <input type="number" id="quantidade" name="quantidade" class="form-control @error('quantidade') is-invalid @enderror" value="{{ old('quantidade', 1) }}" min="1" step="1">
                @error('quantidade')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="os_id">OS</label>
                <input type="number" id="os_id" name="os_id" class="form-control @error('os_id') is-invalid @enderror" value="{{ old('os_id') }}" min="1" step="1" placeholder="Opcional">
                @error('os_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-span-full">
                <label for="motivo">Motivo</label>
                <textarea id="motivo" name="motivo" class="form-control @error('motivo') is-invalid @enderror" rows="3" placeholder="Explique a movimentação">{{ old('motivo') }}</textarea>
                @error('motivo')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="field-actions col-span-full">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-arrow-left-right me-2"></i>
                    Registrar movimentação
                </button>
            </div>
        </form>
    </section>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Histórico</h2>
                <p class="surface-subtitle">Últimos movimentos registrados para a peça selecionada.</p>
            </div>
        </div>

        @if ($movements !== [])
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Data</th>
                        <th>Tipo</th>
                        <th>Quantidade</th>
                        <th>Motivo</th>
                        <th>OS</th>
                        <th>Responsável</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($movements as $movement)
                        @php
                            $movement = array_merge([
                                'tipo' => 'ajuste',
                                'quantidade' => 0,
                                'motivo' => '',
                                'numero_os' => '',
                                'responsavel_nome' => '',
                                'created_at' => '',
                            ], is_array($movement) ? $movement : []);
                        @endphp
                        <tr>
                            <td data-label="Data">{{ $movement['created_at'] ?? '-' }}</td>
                            <td data-label="Tipo">
                                @if (($movement['tipo'] ?? '') === 'entrada')
                                    <span class="badge bg-success">Entrada</span>
                                @elseif (($movement['tipo'] ?? '') === 'saida')
                                    <span class="badge bg-danger">Saída</span>
                                @else
                                    <span class="badge bg-warning text-dark">Ajuste</span>
                                @endif
                            </td>
                            <td data-label="Quantidade">{{ (int) ($movement['quantidade'] ?? 0) }}</td>
                            <td data-label="Motivo">{{ trim((string) ($movement['motivo'] ?? '')) !== '' ? $movement['motivo'] : '-' }}</td>
                            <td data-label="OS">{{ trim((string) ($movement['numero_os'] ?? '')) !== '' ? $movement['numero_os'] : '-' }}</td>
                            <td data-label="Responsável">{{ trim((string) ($movement['responsavel_nome'] ?? '')) !== '' ? $movement['responsavel_nome'] : '-' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @include('layouts.partials.empty-state', [
                'icon' => 'bi-arrow-left-right',
                'title' => 'Nenhuma movimentação encontrada',
                'message' => 'Registre a primeira entrada, saída ou ajuste para começar o histórico da peça.',
            ])
        @endif
    </section>
@endsection
