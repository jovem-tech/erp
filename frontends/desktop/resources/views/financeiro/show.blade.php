@extends('layouts.app')

@section('content')
    @php
        $id = (int) ($lancamento['id'] ?? 0);
        $tipo = (string) ($lancamento['tipo'] ?? '');
        $status = (string) ($lancamento['status'] ?? 'pendente');
        $tipoLabel = (string) ($detalhes['tipo_label'] ?? ($tipo === 'receber' ? 'A receber' : 'A pagar'));
        $statusLabel = (string) ($detalhes['status_label'] ?? ucfirst($status));
        $statusColors = [
            'pendente' => '#f59e0b',
            'parcial' => '#3b82f6',
            'pago' => '#29c384',
            'cancelado' => '#8b93a7',
        ];

        $money = static fn (mixed $value): string => $value === null || $value === ''
            ? '—'
            : 'R$ ' . number_format((float) $value, 2, ',', '.');

        $date = static function (mixed $value, bool $withTime = false): string {
            if ($value === null || trim((string) $value) === '') {
                return '—';
            }

            try {
                return \Illuminate\Support\Carbon::parse($value)->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
            } catch (\Throwable) {
                return (string) $value;
            }
        };

        $text = static fn (mixed $value, string $fallback = '—'): string => trim((string) $value) !== ''
            ? trim((string) $value)
            : $fallback;

        $yesNo = static fn (mixed $value): string => (bool) $value ? 'Sim' : 'Não';

        $contraparte = $detalhes['contraparte'] ?? [];
        $origem = $detalhes['origem'] ?? [];
        $os = $detalhes['os'] ?? null;
        $movimentos = $detalhes['movimentos'] ?? [];
        $impactos = $detalhes['impactos'] ?? [];
        $auditoria = $detalhes['auditoria'] ?? [];
        $osDatas = is_array($os) ? ($os['datas'] ?? []) : [];
        $osEquipamento = is_array($os) ? ($os['equipamento'] ?? []) : [];
        $osDefeito = is_array($os) ? ($os['defeito'] ?? []) : [];
        $osValores = is_array($os) ? ($os['valores'] ?? []) : [];
        $canViewOs = \App\Support\DesktopSession::can('os', 'visualizar');
        $canEditFinanceiro = \App\Support\DesktopSession::can('financeiro', 'editar');
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro</p>
            <h2 class="surface-title fs-3 mb-2">Lançamento #{{ $id > 0 ? $id : '-' }}</h2>
            <div class="d-flex flex-wrap gap-2">
                <span class="badge {{ $tipo === 'receber' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $tipoLabel }}</span>
                @include('layouts.partials.status-pill', [
                    'label' => $statusLabel,
                    'color' => $statusColors[$status] ?? '#8b93a7',
                ])
                @if ((bool) ($lancamento['avulso'] ?? false))
                    <span class="desktop-chip"><i class="bi bi-link-45deg"></i> Avulso</span>
                @endif
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('financeiro.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
            @if ($canEditFinanceiro)
                <a href="{{ route('financeiro.edit', $id) }}" class="btn btn-primary">
                    <i class="bi bi-pencil me-2"></i>
                    Editar
                </a>
            @endif
        </div>
    </div>

    <section class="desktop-grid desktop-grid-four mb-4">
        <article class="summary-card">
            <span class="summary-card-eyebrow">Valor do título</span>
            <div class="summary-card-value">{{ $money($lancamento['valor'] ?? null) }}</div>
            <div class="summary-card-meta">Vencimento: {{ $date($lancamento['data_vencimento'] ?? null) }}</div>
        </article>

        <article class="summary-card">
            <span class="summary-card-eyebrow">Valor baixado</span>
            <div class="summary-card-value">{{ $money($resumo['valor_movimentado'] ?? 0) }}</div>
            <div class="summary-card-meta">{{ (int) ($resumo['total_movimentos'] ?? 0) }} movimento(s)</div>
        </article>

        <article class="summary-card">
            <span class="summary-card-eyebrow">Saldo em aberto</span>
            <div class="summary-card-value">{{ $money($resumo['valor_aberto'] ?? 0) }}</div>
            <div class="summary-card-meta">Quitado: {{ number_format((float) ($resumo['percentual_quitado'] ?? 0), 2, ',', '.') }}%</div>
        </article>

        <article class="summary-card">
            <span class="summary-card-eyebrow">{{ $tipo === 'receber' ? 'Recebido em' : 'Pago em' }}</span>
            <div class="summary-card-value">{{ $date($lancamento['data_pagamento'] ?? null) }}</div>
            <div class="summary-card-meta">Forma: {{ $text($detalhes['forma_pagamento_label'] ?? null, 'Não informada') }}</div>
        </article>
    </section>

    <div class="desktop-grid desktop-grid-two align-items-start">
        <div class="d-flex flex-column gap-4">
            <article class="surface-card">
                <h3 class="surface-title fs-5 mb-2">
                    <i class="bi bi-receipt-cutoff me-2"></i>
                    Dados do lançamento
                </h3>
                <p class="surface-subtitle mb-4">O que é o título, como ele classifica nos relatórios e qual sua origem operacional.</p>

                <div class="row g-3">
                    <div class="col-md-6">
                        <span class="summary-card-eyebrow">Categoria</span>
                        <p class="mb-0 fw-semibold">{{ $text($lancamento['categoria'] ?? null, 'Sem categoria') }}</p>
                    </div>
                    <div class="col-md-6">
                        <span class="summary-card-eyebrow">Competência</span>
                        <p class="mb-0 fw-semibold">{{ $date($impactos['data_competencia'] ?? $lancamento['data_competencia'] ?? null) }}</p>
                    </div>
                    <div class="col-12">
                        <span class="summary-card-eyebrow">Descrição</span>
                        <p class="mb-0">{{ $text($lancamento['descricao'] ?? null, 'Sem descrição') }}</p>
                    </div>
                    <div class="col-12">
                        <span class="summary-card-eyebrow">Observações</span>
                        <p class="mb-0">{{ $text($lancamento['observacoes'] ?? null, 'Nenhuma observação registrada.') }}</p>
                    </div>
                    <div class="col-md-6">
                        <span class="summary-card-eyebrow">DRE</span>
                        <p class="mb-0">{{ $text($impactos['grupo_dre'] ?? $lancamento['grupo_dre'] ?? null, 'Sem grupo') }} / {{ $text($impactos['subgrupo_dre'] ?? $lancamento['subgrupo_dre'] ?? null, 'Sem subgrupo') }}</p>
                    </div>
                    <div class="col-md-6">
                        <span class="summary-card-eyebrow">Impactos</span>
                        <p class="mb-0">
                            DRE: {{ $yesNo($impactos['impacta_dre'] ?? false) }} ·
                            Fluxo caixa: {{ $yesNo($impactos['impacta_fluxo_caixa'] ?? false) }} ·
                            Fixo mensal: {{ $yesNo($impactos['dre_fixo_mensal'] ?? false) }}
                        </p>
                    </div>
                </div>
            </article>

            <article class="surface-card">
                <h3 class="surface-title fs-5 mb-2">
                    <i class="bi bi-person-lines-fill me-2"></i>
                    {{ $text($contraparte['titulo'] ?? null, $tipo === 'receber' ? 'Quem pagou' : 'Para quem pagou') }}
                </h3>
                <p class="surface-subtitle mb-4">Cliente, fornecedor ou contraparte vinculada ao lançamento.</p>

                <div class="row g-3">
                    <div class="col-md-6">
                        <span class="summary-card-eyebrow">Nome</span>
                        <p class="mb-0 fw-semibold">{{ $text($contraparte['nome'] ?? null, $tipo === 'receber' ? 'Cliente não vinculado' : 'Fornecedor não vinculado') }}</p>
                    </div>
                    <div class="col-md-6">
                        <span class="summary-card-eyebrow">Documento</span>
                        <p class="mb-0">{{ $text($contraparte['documento'] ?? null) }}</p>
                    </div>
                    <div class="col-md-6">
                        <span class="summary-card-eyebrow">Telefone</span>
                        <p class="mb-0">{{ $text($contraparte['telefone'] ?? null) }}</p>
                    </div>
                    <div class="col-md-6">
                        <span class="summary-card-eyebrow">E-mail</span>
                        <p class="mb-0">{{ $text($contraparte['email'] ?? null) }}</p>
                    </div>
                    <div class="col-12">
                        <span class="summary-card-eyebrow">Observações da contraparte</span>
                        <p class="mb-0">{{ $text($contraparte['observacoes'] ?? null, 'Nenhuma observação registrada.') }}</p>
                    </div>
                </div>
            </article>

            <article class="surface-card">
                <h3 class="surface-title fs-5 mb-2">
                    <i class="bi bi-clock-history me-2"></i>
                    Baixas e formas de pagamento
                </h3>
                <p class="surface-subtitle mb-4">Cada movimento efetivamente lançado no caixa, incluindo taxas de cartão quando houver.</p>

                @if ($movimentos !== [])
                    <div class="table-responsive">
                        <table class="table table-stack align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Data</th>
                                <th>Tipo</th>
                                <th>Forma</th>
                                <th>Valor</th>
                                <th>Taxa/cartão</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($movimentos as $movimento)
                                @php
                                    $cartao = $movimento['cartao'] ?? null;
                                @endphp
                                <tr>
                                    <td data-label="Data">{{ $date($movimento['data_movimento'] ?? null) }}</td>
                                    <td data-label="Tipo">{{ $text($movimento['tipo_label'] ?? null) }}</td>
                                    <td data-label="Forma">
                                        <div class="fw-semibold">{{ $text($movimento['forma_pagamento_label'] ?? null, 'Não informada') }}</div>
                                        @if (! empty($movimento['documento_ref']))
                                            <small class="text-secondary">Doc.: {{ $movimento['documento_ref'] }}</small>
                                        @endif
                                    </td>
                                    <td data-label="Valor">{{ $money($movimento['valor'] ?? null) }}</td>
                                    <td data-label="Taxa/cartão">
                                        @if (is_array($cartao))
                                            <div class="fw-semibold">
                                                {{ $text($cartao['operadora'] ?? null, 'Operadora não informada') }}
                                                @if (! empty($cartao['bandeira']))
                                                    · {{ $cartao['bandeira'] }}
                                                @endif
                                            </div>
                                            <small class="text-secondary d-block">
                                                {{ ucfirst((string) ($cartao['modalidade'] ?? 'crédito')) }}
                                                em {{ (int) ($cartao['parcelas'] ?? 1) }}x ·
                                                Taxa {{ number_format((float) ($cartao['taxa_percentual'] ?? 0), 4, ',', '.') }}%
                                                @if ((float) ($cartao['taxa_fixa'] ?? 0) > 0)
                                                    + {{ $money($cartao['taxa_fixa']) }}
                                                @endif
                                            </small>
                                            <small class="text-secondary d-block">
                                                Bruto {{ $money($cartao['valor_bruto'] ?? null) }} ·
                                                Taxa {{ $money($cartao['valor_taxa'] ?? null) }} ·
                                                Líquido {{ $money($cartao['valor_liquido'] ?? null) }}
                                            </small>
                                            <small class="text-secondary d-block">
                                                Repasse previsto: {{ $date($cartao['data_prevista_repasse'] ?? $cartao['data_prevista_recebimento'] ?? null) }}
                                            </small>
                                        @else
                                            —
                                        @endif
                                        @if (! empty($movimento['observacoes']))
                                            <small class="text-secondary d-block mt-1">{{ $movimento['observacoes'] }}</small>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="empty-state py-4">
                        <i class="bi bi-cash-stack"></i>
                        <h4>Nenhuma baixa registrada</h4>
                        <p>O título ainda não possui movimento de pagamento/recebimento no fluxo de caixa.</p>
                    </div>
                @endif
            </article>
        </div>

        <div class="d-flex flex-column gap-4">
            <article class="surface-card">
                <h3 class="surface-title fs-5 mb-2">
                    <i class="bi bi-diagram-3 me-2"></i>
                    Origem do lançamento
                </h3>
                <p class="surface-subtitle mb-4">{{ $text($origem['descricao'] ?? null, 'Origem não informada.') }}</p>

                <div class="row g-3">
                    <div class="col-12">
                        <span class="summary-card-eyebrow">Tipo de origem</span>
                        <p class="mb-0 fw-semibold">{{ $text($origem['titulo'] ?? null, 'Origem não informada') }}</p>
                    </div>

                    @if (! empty($origem['lancamento_origem_id']))
                        <div class="col-12">
                            <span class="summary-card-eyebrow">Lançamento de origem</span>
                            <p class="mb-0">
                                <a href="{{ route('financeiro.show', (int) $origem['lancamento_origem_id']) }}">
                                    #{{ (int) $origem['lancamento_origem_id'] }}
                                </a>
                                — {{ $text($origem['lancamento_origem_descricao'] ?? null, 'Sem descrição') }}
                            </p>
                        </div>
                    @endif
                </div>
            </article>

            @if (is_array($os))
                <article class="surface-card">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                        <div>
                            <h3 class="surface-title fs-5 mb-1">
                                <i class="bi bi-clipboard-check me-2"></i>
                                OS vinculada
                            </h3>
                            <p class="surface-subtitle mb-0">Equipamento, defeito, datas e valores que ajudam a explicar o recebimento.</p>
                        </div>

                        @if ($canViewOs && (int) ($os['id'] ?? 0) > 0)
                            <a href="{{ route('orders.show', (int) $os['id']) }}" class="btn btn-sm btn-outline-light">
                                <i class="bi bi-box-arrow-up-right me-1"></i>
                                Abrir OS
                            </a>
                        @endif
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <span class="summary-card-eyebrow">Número</span>
                            <p class="mb-0 fw-semibold">{{ $text($os['numero_os'] ?? null, '#' . (int) ($os['id'] ?? 0)) }}</p>
                        </div>
                        <div class="col-md-6">
                            <span class="summary-card-eyebrow">Status</span>
                            <p class="mb-0">{{ $text($os['status_nome'] ?? null) }}</p>
                        </div>
                        <div class="col-12">
                            <span class="summary-card-eyebrow">Equipamento</span>
                            <p class="mb-0 fw-semibold">{{ $text($osEquipamento['label'] ?? null, 'Equipamento não informado') }}</p>
                            <small class="text-secondary">
                                Tipo: {{ $text($osEquipamento['tipo'] ?? null) }} ·
                                Marca: {{ $text($osEquipamento['marca'] ?? null) }} ·
                                Modelo: {{ $text($osEquipamento['modelo'] ?? null) }}
                            </small>
                            <small class="text-secondary d-block">
                                Série: {{ $text($osEquipamento['serie'] ?? null) }} ·
                                IMEI: {{ $text($osEquipamento['imei'] ?? null) }}
                            </small>
                        </div>
                        <div class="col-12">
                            <span class="summary-card-eyebrow">Defeito / relato</span>
                            <p class="mb-1">{{ $text($osDefeito['relato_cliente'] ?? null, 'Relato do cliente não informado.') }}</p>
                            <small class="text-secondary d-block">Diagnóstico: {{ $text($osDefeito['diagnostico_tecnico'] ?? null, 'Não informado') }}</small>
                            <small class="text-secondary d-block">Solução: {{ $text($osDefeito['solucao_aplicada'] ?? null, 'Não informada') }}</small>
                        </div>
                        <div class="col-md-6">
                            <span class="summary-card-eyebrow">Entrada</span>
                            <p class="mb-0">{{ $date($osDatas['entrada'] ?? $osDatas['abertura'] ?? null, true) }}</p>
                        </div>
                        <div class="col-md-6">
                            <span class="summary-card-eyebrow">Entrega</span>
                            <p class="mb-0">{{ $date($osDatas['entrega'] ?? null, true) }}</p>
                        </div>
                        <div class="col-md-6">
                            <span class="summary-card-eyebrow">Conclusão técnica</span>
                            <p class="mb-0">{{ $date($osDatas['conclusao'] ?? $osDatas['baixa_tecnica'] ?? null, true) }}</p>
                        </div>
                        <div class="col-md-6">
                            <span class="summary-card-eyebrow">Valor final da OS</span>
                            <p class="mb-0 fw-semibold">{{ $money($osValores['final'] ?? $osValores['total'] ?? null) }}</p>
                        </div>
                    </div>
                </article>
            @else
                <article class="surface-card">
                    <h3 class="surface-title fs-5 mb-2">
                        <i class="bi bi-clipboard-x me-2"></i>
                        Sem OS vinculada
                    </h3>
                    <p class="surface-subtitle mb-0">
                        Este lançamento não está associado a ordem de serviço. Se for avulso com cliente, ele aparece no histórico financeiro do cliente; se for avulso puro, aparece apenas nos registros financeiros, DRE e fluxo de caixa.
                    </p>
                </article>
            @endif

            <article class="surface-card">
                <h3 class="surface-title fs-5 mb-2">
                    <i class="bi bi-shield-check me-2"></i>
                    Auditoria
                </h3>
                <div class="row g-3">
                    <div class="col-md-6">
                        <span class="summary-card-eyebrow">Criado em</span>
                        <p class="mb-0">{{ $date($auditoria['criado_em'] ?? $lancamento['created_at'] ?? null, true) }}</p>
                    </div>
                    <div class="col-md-6">
                        <span class="summary-card-eyebrow">Atualizado em</span>
                        <p class="mb-0">{{ $date($auditoria['atualizado_em'] ?? $lancamento['updated_at'] ?? null, true) }}</p>
                    </div>
                </div>
            </article>
        </div>
    </div>
@endsection
