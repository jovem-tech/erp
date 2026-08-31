@extends('layouts.app')

@section('content')
    @php
        $caixa = $caixa ?? false;
        $receita = $dre['receita'] ?? [];
        $custos = $dre['custos_diretos'] ?? [];
        $outras = $dre['outras_receitas'] ?? [];
        $despesas = $dre['despesas_operacionais'] ?? [];
        $gerencial = $dre['gerencial'] ?? [];
        $temGerencial = (bool) ($gerencial['disponivel'] ?? false);
        $variaveis = $gerencial['custos_variaveis'] ?? [];
        $cvp = $gerencial['analise_cvp'] ?? [];
        $ticket = $receita['ticket_medio'] ?? [];
        $volume = $receita['volume'] ?? ['total' => 0, 'os' => 0, 'nao_os' => 0];
        $fmt = static fn ($valor) => 'R$ ' . number_format((float) ($valor ?? 0), 2, ',', '.');
        $pct = static fn ($valor) => number_format((float) ($valor ?? 0), 2, ',', '.') . '%';
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro</p>
            <h2 class="surface-title fs-3 mb-2">{{ $caixa ? 'DRE de caixa' : 'DRE por competência' }} <x-favorite-toggle /></h2>
            <p class="surface-subtitle mb-0">
                @if ($caixa)
                    Reconhece receitas e despesas apenas quando o dinheiro entra ou sai de fato (baixa registrada), referência: {{ $dre['periodo_label'] ?? '' }}.
                @else
                    Reconhece o faturamento — OS entregues e vendas de balcão — no mês em que foi gerado, e as demais entradas/saídas pela data de competência, referência: {{ $dre['periodo_label'] ?? '' }}.
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

    @if ($temGerencial)
        <div class="desktop-grid desktop-grid-four mb-4">
            <div class="desktop-form-card text-center">
                <p class="surface-subtitle mb-1">Receita líquida</p>
                <h3 class="surface-title mb-0">{{ $fmt($gerencial['receita_liquida'] ?? 0) }}</h3>
            </div>
            {{-- Ticket médio: faturamento bruto dividido pelo número de vendas
                 (OS entregues + vendas de balcão). Só aparece por competência —
                 no caixa o que se conta são baixas, e uma venda parcelada
                 viraria "três compras". --}}
            <div class="desktop-form-card text-center">
                <p class="surface-subtitle mb-1">Ticket médio</p>
                <h3 class="surface-title mb-0">{{ ($ticket['geral'] ?? null) !== null ? $fmt($ticket['geral']) : '—' }}</h3>
                <p class="surface-subtitle mb-0 small">
                    {{ (int) $volume['total'] }} {{ (int) $volume['total'] === 1 ? 'venda no mês' : 'vendas no mês' }}
                </p>
            </div>
            <div class="desktop-form-card text-center">
                <p class="surface-subtitle mb-1">Margem de contribuição</p>
                <h3 class="surface-title mb-0 {{ ((float) ($gerencial['margem_contribuicao'] ?? 0)) < 0 ? 'text-danger' : '' }}">
                    {{ $fmt($gerencial['margem_contribuicao'] ?? 0) }}
                </h3>
                <p class="surface-subtitle mb-0 small">{{ $pct($gerencial['indice_contribuicao_percentual'] ?? 0) }} da receita</p>
            </div>
            <div class="desktop-form-card text-center">
                <p class="surface-subtitle mb-1">Resultado operacional</p>
                <h3 class="surface-title mb-0 {{ ((float) ($gerencial['resultado_operacional'] ?? 0)) < 0 ? 'text-danger' : '' }}">
                    {{ $fmt($gerencial['resultado_operacional'] ?? 0) }}
                </h3>
            </div>
        </div>
    @else
        <div class="desktop-grid desktop-grid-three mb-4">
            <div class="desktop-form-card text-center">
                <p class="surface-subtitle mb-1">Receita realizada</p>
                <h3 class="surface-title mb-0">{{ $fmt($receita['receita_liquida'] ?? 0) }}</h3>
            </div>
            <div class="desktop-form-card text-center">
                <p class="surface-subtitle mb-1">Despesas variáveis</p>
                <h3 class="surface-title mb-0">{{ $fmt($gerencial['despesas_variaveis'] ?? 0) }}</h3>
            </div>
            <div class="desktop-form-card text-center">
                <p class="surface-subtitle mb-1">Custos fixos</p>
                <h3 class="surface-title mb-0">{{ $fmt($gerencial['custos_fixos'] ?? 0) }}</h3>
            </div>
        </div>
    @endif

    @if ($temGerencial)
        <section class="surface-table mb-4">
            <div class="surface-table-header">
                <div>
                    <h2 class="surface-title">Resultado gerencial (custeio variável)</h2>
                    <p class="surface-subtitle">
                        Separa os custos pelo comportamento — variam com o volume ou não. A margem de contribuição
                        é o que sobra de cada real vendido para pagar os custos fixos.
                    </p>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <tbody>
                    <tr>
                        <td class="fw-semibold">Receita líquida (faturamento)</td>
                        <td class="text-end fw-semibold">{{ $fmt($gerencial['receita_liquida'] ?? 0) }}</td>
                    </tr>
                    <tr>
                        <td>(-) Custos e despesas variáveis</td>
                        <td class="text-end">{{ $fmt($variaveis['total'] ?? 0) }}</td>
                    </tr>
                    <tr class="text-secondary small">
                        <td class="ps-4">Peças aplicadas (custo de estoque)</td>
                        <td class="text-end">{{ $fmt($variaveis['cmv_pecas'] ?? 0) }}</td>
                    </tr>
                    @if ((float) ($variaveis['cmv_vendas'] ?? 0) != 0)
                        <tr class="text-secondary small">
                            <td class="ps-4">Mercadoria vendida no balcão (custo de estoque)</td>
                            <td class="text-end">{{ $fmt($variaveis['cmv_vendas']) }}</td>
                        </tr>
                    @endif
                    <tr class="text-secondary small">
                        <td class="ps-4">Comissões de técnicos</td>
                        <td class="text-end">{{ $fmt($variaveis['comissoes'] ?? 0) }}</td>
                    </tr>
                    <tr class="text-secondary small">
                        <td class="ps-4">Despesas variáveis (taxas, impostos)</td>
                        <td class="text-end">{{ $fmt($variaveis['despesas_variaveis'] ?? 0) }}</td>
                    </tr>
                    @if ((float) ($variaveis['custos_diretos_os'] ?? 0) > 0)
                        <tr class="text-secondary small">
                            <td class="ps-4">Custos diretos de OS</td>
                            <td class="text-end">{{ $fmt($variaveis['custos_diretos_os']) }}</td>
                        </tr>
                    @endif
                    <tr class="table-light">
                        <td class="fw-semibold">(=) Margem de contribuição</td>
                        <td class="text-end fw-semibold {{ ((float) ($gerencial['margem_contribuicao'] ?? 0)) < 0 ? 'text-danger' : '' }}">
                            {{ $fmt($gerencial['margem_contribuicao'] ?? 0) }}
                            <span class="text-secondary fw-normal small">({{ $pct($gerencial['indice_contribuicao_percentual'] ?? 0) }})</span>
                        </td>
                    </tr>
                    <tr>
                        <td>(-) Custos e despesas fixas</td>
                        <td class="text-end">{{ $fmt($gerencial['custos_fixos'] ?? 0) }}</td>
                    </tr>
                    @if ((float) ($gerencial['outras_receitas'] ?? 0) != 0)
                        <tr>
                            <td>(+) Outras receitas</td>
                            <td class="text-end">{{ $fmt($gerencial['outras_receitas']) }}</td>
                        </tr>
                    @endif
                    <tr class="table-light">
                        <td class="fw-semibold">(=) Resultado operacional</td>
                        <td class="text-end fw-semibold {{ ((float) ($gerencial['resultado_operacional'] ?? 0)) < 0 ? 'text-danger' : '' }}">
                            {{ $fmt($gerencial['resultado_operacional'] ?? 0) }}
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </section>

        @php
            $pontoEquilibrio = $cvp['ponto_equilibrio_receita'] ?? null;
            $percentualEquilibrio = (float) ($cvp['percentual_do_equilibrio'] ?? 0);
            $atingiu = (bool) ($cvp['ponto_equilibrio_atingido'] ?? false);
        @endphp

        <section class="surface-table mb-4">
            <div class="surface-table-header">
                <div>
                    <h2 class="surface-title">Análise custo-volume-lucro</h2>
                    <p class="surface-subtitle">Quanto é preciso faturar para não ter prejuízo, e quanta folga existe.</p>
                </div>
            </div>

            <div class="p-3">
                @if ($pontoEquilibrio === null)
                    <div class="alert alert-danger mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Sem ponto de equilíbrio neste período.</strong>
                        A margem de contribuição está negativa: cada venda sai sem cobrir o próprio custo variável,
                        então nenhum volume de faturamento zera o resultado. O caminho não é vender mais — é rever
                        preço ou custo de peça.
                    </div>
                @else
                    <div class="desktop-grid desktop-grid-three mb-3">
                        <div class="desktop-form-card text-center">
                            <p class="surface-subtitle mb-1">Ponto de equilíbrio</p>
                            <h3 class="surface-title mb-0">{{ $fmt($pontoEquilibrio) }}</h3>
                            <p class="surface-subtitle mb-0 small">faturamento para resultado zero</p>
                        </div>
                        <div class="desktop-form-card text-center">
                            <p class="surface-subtitle mb-1">Margem de segurança</p>
                            <h3 class="surface-title mb-0 {{ $atingiu ? '' : 'text-danger' }}">
                                {{ $fmt($cvp['margem_seguranca_valor'] ?? 0) }}
                            </h3>
                            <p class="surface-subtitle mb-0 small">{{ $pct($cvp['margem_seguranca_percentual'] ?? 0) }} da receita</p>
                        </div>
                        <div class="desktop-form-card text-center">
                            <p class="surface-subtitle mb-1">Alavancagem operacional</p>
                            <h3 class="surface-title mb-0">
                                {{ $cvp['grau_alavancagem_operacional'] !== null ? number_format((float) $cvp['grau_alavancagem_operacional'], 2, ',', '.') . 'x' : '—' }}
                            </h3>
                            <p class="surface-subtitle mb-0 small">variação do resultado por 1% de receita</p>
                        </div>
                    </div>

                    <div class="progress" style="height: 1.4rem;" role="progressbar"
                         aria-label="Percentual do ponto de equilíbrio atingido"
                         aria-valuenow="{{ (int) round($percentualEquilibrio) }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar {{ $atingiu ? 'bg-success' : 'bg-danger' }}"
                             style="width: {{ min(100, max(0, $percentualEquilibrio)) }}%;">
                            {{ $pct($percentualEquilibrio) }}
                        </div>
                    </div>
                    <p class="surface-subtitle small mt-2 mb-0">
                        @if ($atingiu)
                            Faturamento cobre os custos fixos. A receita pode cair
                            {{ $pct($cvp['margem_seguranca_percentual'] ?? 0) }} antes de entrar no prejuízo.
                        @else
                            Faltam {{ $fmt(abs((float) ($cvp['margem_seguranca_valor'] ?? 0))) }} de faturamento
                            para cobrir os custos fixos do período.
                        @endif
                    </p>
                @endif
            </div>
        </section>
    @else
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Margem de contribuição não se apura em regime de caixa.</strong>
            {{ $gerencial['motivo'] ?? '' }}
            <a href="{{ route('financeiro.relatorios.dre', ['mes' => $mes]) }}" class="alert-link">Ver o DRE por competência</a>.
        </div>
    @endif

    <section class="surface-table">
        <div class="surface-table-header">
            <div>
                <h2 class="surface-title">Demonstração de resultado (contábil)</h2>
                <p class="surface-subtitle">
                    {{ $caixa ? 'Valores pela data de realização (baixa).' : 'Valores pela data de competência.' }}
                    Agrupa os custos pela natureza, não pelo comportamento — o lucro bruto aqui não desconta as
                    peças baixadas do estoque.
                </p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-stack align-middle">
                <tbody>
                <tr>
                    <td class="fw-semibold">Receita bruta (faturamento)</td>
                    <td class="text-end">{{ $fmt($receita['receita_bruta'] ?? 0) }}</td>
                </tr>
                <tr class="text-secondary small">
                    <td class="ps-4">
                        {{ $caixa ? 'OS recebidas' : 'OS entregues' }}
                        @if (($ticket['os'] ?? null) !== null)
                            <span class="text-secondary">— {{ (int) $volume['os'] }} × {{ $fmt($ticket['os']) }} de ticket médio</span>
                        @endif
                    </td>
                    <td class="text-end">{{ $fmt($receita['os_bruto'] ?? 0) }}</td>
                </tr>
                @if ((float) ($receita['operacional_nao_os'] ?? 0) != 0)
                    <tr class="text-secondary small">
                        <td class="ps-4">
                            Vendas de balcão
                            @if (($ticket['nao_os'] ?? null) !== null)
                                <span class="text-secondary">— {{ (int) $volume['nao_os'] }} × {{ $fmt($ticket['nao_os']) }} de ticket médio</span>
                            @endif
                        </td>
                        <td class="text-end">{{ $fmt($receita['operacional_nao_os']) }}</td>
                    </tr>
                @endif
                <tr>
                    <td>(-) Descontos</td>
                    <td class="text-end">{{ $fmt($receita['descontos'] ?? 0) }}</td>
                </tr>
                {{-- Devolução é dedução da receita, não despesa: o dinheiro
                     nunca foi da empresa. Por isso ela some de Despesas
                     Operacionais quando aparece aqui — ver
                     FinanceiroReportService, que exclui origem_tipo
                     venda_devolucao daquele grupo. --}}
                <tr>
                    <td>(-) Devoluções</td>
                    <td class="text-end">{{ $fmt($receita['devolucoes'] ?? 0) }}</td>
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
                @php $despesasPorFixoVariavel = $despesas['por_fixo_variavel'] ?? ['fixas' => 0, 'variaveis' => 0]; @endphp
                <tr class="text-secondary small">
                    <td class="ps-4">Despesas fixas</td>
                    <td class="text-end">{{ $fmt($despesasPorFixoVariavel['fixas'] ?? 0) }}</td>
                </tr>
                <tr class="text-secondary small">
                    <td class="ps-4">Despesas variáveis</td>
                    <td class="text-end">{{ $fmt($despesasPorFixoVariavel['variaveis'] ?? 0) }}</td>
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
