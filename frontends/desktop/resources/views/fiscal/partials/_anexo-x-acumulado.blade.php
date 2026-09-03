{{-- Acumulado do ano contra o limite do MEI.

     Soma sempre COMPETÊNCIA, mesmo com a tabela em caixa: o limite de
     R$ 81.000 é sobre a receita bruta AUFERIDA no ano-calendário, e "auferida"
     é o termo do regime de competência. O regime de caixa é opção do ME/EPP
     exercida no PGDAS-D, e o MEI usa DASN-Simei, que não tem esse mecanismo. --}}
@php
    $fmt = static fn ($valor) => 'R$ ' . number_format((float) ($valor ?? 0), 2, ',', '.');
@endphp

<section class="desktop-form-card">
    @if ($acumulado)
        @php
            $faixa = $acumulado['faixa'] ?? 'dentro';
            $cor = ['dentro' => 'success', 'excesso_ate_20' => 'warning', 'excesso_acima_20' => 'danger'][$faixa] ?? 'secondary';
            $percentual = min(100, (float) ($acumulado['percentual_do_limite'] ?? 0));
        @endphp

        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
            <div>
                <h3 class="surface-title mb-1">Acumulado de {{ $acumulado['ano'] }} × limite do MEI</h3>
                <p class="surface-subtitle mb-0">
                    Receita bruta <strong>auferida</strong> no ano-calendário — o critério do limite.
                    @if ($acumulado['limite_proporcional'])
                        Limite proporcionalizado a {{ $acumulado['meses_de_atividade'] }} meses de atividade no ano de abertura.
                    @endif
                </p>
            </div>
            <div class="text-end">
                <div class="fs-4 fw-bold">{{ $fmt($acumulado['acumulado']) }}</div>
                <div class="surface-subtitle small">de {{ $fmt($acumulado['limite']) }}</div>
            </div>
        </div>

        <div class="progress" style="height: 10px;" role="progressbar"
             aria-valuenow="{{ $percentual }}" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar bg-{{ $cor }}" style="width: {{ $percentual }}%"></div>
        </div>

        <div class="d-flex flex-wrap justify-content-between gap-2 mt-2 small">
            <span class="surface-subtitle">
                {{ number_format((float) $acumulado['percentual_do_limite'], 1, ',', '.') }}% do limite
                @if (($acumulado['restante'] ?? 0) >= 0)
                    · restam {{ $fmt($acumulado['restante']) }}
                @endif
            </span>
            @if (! empty($acumulado['meses_fechados']))
                <span class="surface-subtitle">
                    <i class="bi bi-lock-fill me-1"></i>
                    {{ count($acumulado['meses_fechados']) }}
                    {{ count($acumulado['meses_fechados']) === 1 ? 'mês encerrado usa o valor declarado' : 'meses encerrados usam o valor declarado' }}
                </span>
            @endif
        </div>

        @if ($acumulado['mensagem'])
            <div class="alert alert-{{ $cor }} mt-3 mb-0 small">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                {{ $acumulado['mensagem'] }}
            </div>
        @endif
    @else
        <h3 class="surface-title mb-1">Acumulado do ano</h3>
        <p class="surface-subtitle mb-0">
            O teto de receita bruta anual é exclusivo do MEI e não se aplica ao regime tributário
            configurado — por isso a barra não aparece aqui.
        </p>
    @endif
</section>
