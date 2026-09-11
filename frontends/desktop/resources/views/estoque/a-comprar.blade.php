@extends('layouts.app')

@section('content')
    @php
        // Mesmo formatador da listagem: quantidade é DECIMAL(14,4) desde a
        // specs/036, e "10" não pode aparecer como "10,0000".
        $qtd = static function ($valor): string {
            $numero = round((float) ($valor ?? 0), 4);

            return $numero === floor($numero)
                ? number_format($numero, 0, ',', '.')
                : rtrim(rtrim(number_format($numero, 4, ',', '.'), '0'), ',');
        };

        $custoTotal = array_sum(array_map(
            static fn ($peca): float => (float) ($peca['custo_estimado'] ?? 0),
            is_array($parts ?? null) ? $parts : []
        ));
    @endphp

    <div class="surface mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h2 class="surface-title">Peças a comprar</h2>
                <p class="text-muted mb-0">
                    O que foi prometido em orçamento e não existe no estoque.
                    A conta é por peça, somando todos os orçamentos ativos.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('estoque.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>
                    Voltar ao estoque
                </a>
            </div>
        </div>
    </div>

    @if (! empty($error))
        <div class="alert alert-warning">{{ $error }}</div>
    @endif

    <div class="surface">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h3 class="surface-title h5 mb-1">Pendências de compra</h3>
                <p class="text-muted mb-0 small">
                    {{ (int) ($totalItens ?? 0) }} peça(s) com falta.
                    @if ($custoTotal > 0)
                        Custo estimado total: <strong>R$ {{ number_format($custoTotal, 2, ',', '.') }}</strong>.
                    @endif
                </p>
            </div>

            <span class="desktop-chip">
                <i class="bi bi-cart-plus"></i>
                {{ (int) ($totalItens ?? 0) }} pendência(s)
            </span>
        </div>

        @if (! empty($parts))
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Código</th>
                        <th>Peça</th>
                        <th>Fornecedor</th>
                        <th>Em estoque</th>
                        <th>Reservado</th>
                        <th>Falta</th>
                        <th>Custo estimado</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($parts as $part)
                        @php
                            $part = is_array($part) ? $part : [];
                            $pecaId = (int) ($part['id'] ?? $part['peca_id'] ?? 0);
                            $falta = (float) ($part['falta'] ?? 0);
                            $orcamentos = is_array($part['orcamentos'] ?? null) ? $part['orcamentos'] : [];
                        @endphp
                        <tr>
                            <td data-label="Código">{{ trim((string) ($part['codigo'] ?? '')) !== '' ? $part['codigo'] : '-' }}</td>
                            <td data-label="Peça">
                                <div class="fw-semibold">{{ trim((string) ($part['nome'] ?? '')) !== '' ? $part['nome'] : 'Sem nome' }}</div>
                                @if ($orcamentos !== [])
                                    {{-- Quem segura a peça: sem isto o operador não sabe
                                         de qual cliente é a promessa que está travada. --}}
                                    <small class="text-muted d-block mt-1">
                                        Prometida em:
                                        @foreach ($orcamentos as $vinculo)
                                            <span class="d-inline-block me-2">
                                                {{ trim((string) ($vinculo['numero'] ?? '')) !== '' ? $vinculo['numero'] : 'Orçamento #' . (int) ($vinculo['orcamento_id'] ?? 0) }}
                                                @if (trim((string) ($vinculo['cliente'] ?? '')) !== '')
                                                    ({{ $vinculo['cliente'] }})
                                                @endif
                                                — {{ $qtd($vinculo['quantidade'] ?? 0) }}
                                            </span>
                                        @endforeach
                                    </small>
                                @endif
                            </td>
                            <td data-label="Fornecedor">{{ trim((string) ($part['fornecedor'] ?? '')) !== '' ? $part['fornecedor'] : '-' }}</td>
                            <td data-label="Em estoque">{{ $qtd($part['quantidade_atual'] ?? 0) }}</td>
                            <td data-label="Reservado">{{ $qtd($part['reservado'] ?? 0) }}</td>
                            <td data-label="Falta">
                                <span class="badge text-bg-warning">{{ $qtd($falta) }} {{ $part['unidade'] ?? 'UN' }}</span>
                            </td>
                            <td data-label="Custo estimado">R$ {{ number_format((float) ($part['custo_estimado'] ?? 0), 2, ',', '.') }}</td>
                            <td data-label="Ações" class="text-end">
                                {{-- Fecha o ciclo no fluxo de entrada que a specs/039
                                     já entregou: a compra É um lançamento financeiro. --}}
                                @if (\App\Support\DesktopSession::can('estoque', 'editar')
                                    && \App\Support\DesktopSession::can('financeiro', 'criar'))
                                    <a href="{{ route('financeiro.create', ['tipo' => 'pagar', 'entrada_estoque' => 1, 'peca_id' => $pecaId, 'quantidade' => $falta]) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-box-arrow-in-down me-1"></i>
                                        Comprar
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-muted mb-0">
                Nenhuma peça em falta. Tudo que está prometido em orçamento existe no estoque.
            </p>
        @endif
    </div>
@endsection
