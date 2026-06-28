@extends('layouts.app')

@section('content')
    @php
        $caixa = $caixa ?? false;
        $receita = $dre['receita'] ?? [];
        $custos = $dre['custos_diretos'] ?? [];
        $outras = $dre['outras_receitas'] ?? [];
        $despesas = $dre['despesas_operacionais'] ?? [];
        $fmt = static fn ($valor) => 'R$ ' . number_format((float) ($valor ?? 0), 2, ',', '.');
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro</p>
            <h2 class="surface-title fs-3 mb-2">{{ $caixa ? 'DRE de caixa' : 'DRE por competência' }}</h2>
            <p class="surface-subtitle mb-0">
                @if ($caixa)
                    Reconhece receitas e despesas apenas quando o dinheiro entra ou sai de fato (baixa registrada), referência: {{ $dre['periodo_label'] ?? '' }}.
                @else
                    Reconhece a receita de OS pela data de entrega e as demais entradas/saídas pela data de competência, referência: {{ $dre['periodo_label'] ?? '' }}.
                @endif
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ $caixa ? route('financeiro.relatorios.dre') : route('financeiro.relatorios.dre-caixa') }}" class="btn btn-outline-info">
                <i class="bi bi-arrow-left-right me-2"></i>
                Ver {{ $caixa ? 'DRE por competência' : 'DRE de caixa' }}
            </a>
            <a href="{{ route('financeiro.relatorios.fluxo-caixa', ['mes' => $mes]) }}" class="btn btn-outline-light">
                <i class="bi bi-calendar3-week me-2"></i>
                Fluxo de caixa
            </a>
        </div>
    </div>

    <section class="desktop-form-card mb-4">
        <form method="get" class="desktop-filter-grid">
            <div>
                <label for="mes">Mês de referência</label>
                <input type="month" id="mes" name="mes" class="form-control" value="{{ $mes }}">
            </div>
            <div class="field-actions">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-search me-2"></i>
                    Atualizar
                </button>
            </div>
        </form>
    </section>

    <div class="desktop-grid desktop-grid-three mb-4">
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">{{ $caixa ? 'Receita realizada' : 'Receita líquida' }}</p>
            <h3 class="surface-title mb-0">{{ $fmt($receita['receita_liquida'] ?? 0) }}</h3>
        </div>
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">Lucro bruto</p>
            <h3 class="surface-title mb-0">{{ $fmt($dre['lucro_bruto'] ?? 0) }}</h3>
        </div>
        <div class="desktop-form-card text-center">
            <p class="surface-subtitle mb-1">Resultado líquido</p>
            <h3 class="surface-title mb-0">{{ $fmt($dre['resultado_liquido'] ?? 0) }}</h3>
        </div>
    </div>

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Demonstração de resultado</h2>
                <p class="surface-subtitle">{{ $caixa ? 'Valores pela data de realização (baixa).' : 'Valores pela data de competência.' }}</p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-stack align-middle">
                <tbody>
                <tr>
                    <td class="fw-semibold">Receita bruta {{ $caixa ? '(OS recebida)' : '(OS entregue)' }}</td>
                    <td class="text-end">{{ $fmt($receita['receita_bruta'] ?? 0) }}</td>
                </tr>
                <tr>
                    <td>(-) Descontos</td>
                    <td class="text-end">{{ $fmt($receita['descontos'] ?? 0) }}</td>
                </tr>
                <tr class="table-light">
                    <td class="fw-semibold">(=) Receita líquida</td>
                    <td class="text-end fw-semibold">{{ $fmt($receita['receita_liquida'] ?? 0) }}</td>
                </tr>
                <tr>
                    <td>(-) Custos diretos (OS)</td>
                    <td class="text-end">{{ $fmt($custos['total'] ?? 0) }}</td>
                </tr>
                <tr class="table-light">
                    <td class="fw-semibold">(=) Lucro bruto</td>
                    <td class="text-end fw-semibold">{{ $fmt($dre['lucro_bruto'] ?? 0) }}</td>
                </tr>
                <tr>
                    <td>(+) Outras receitas</td>
                    <td class="text-end">{{ $fmt($outras['total'] ?? 0) }}</td>
                </tr>
                @foreach (($outras['por_subgrupo'] ?? []) as $subgrupo => $valor)
                    <tr class="text-secondary small">
                        <td class="ps-4">{{ $subgrupo }}</td>
                        <td class="text-end">{{ $fmt($valor) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td>(-) Despesas operacionais</td>
                    <td class="text-end">{{ $fmt($despesas['total'] ?? 0) }}</td>
                </tr>
                @foreach (($despesas['por_subgrupo'] ?? []) as $subgrupo => $valor)
                    <tr class="text-secondary small">
                        <td class="ps-4">{{ $subgrupo }}</td>
                        <td class="text-end">{{ $fmt($valor) }}</td>
                    </tr>
                @endforeach
                <tr class="table-light">
                    <td class="fw-semibold">(=) Resultado líquido</td>
                    <td class="text-end fw-semibold">{{ $fmt($dre['resultado_liquido'] ?? 0) }}</td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>
@endsection
