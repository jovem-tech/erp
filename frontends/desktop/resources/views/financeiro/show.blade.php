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
        $canDeleteFinanceiro = \App\Support\DesktopSession::can('financeiro', 'excluir');
        $canCreateFinanceiro = \App\Support\DesktopSession::can('financeiro', 'criar');

        // Vínculos e ações do dropdown "Mais ações".
        $osId = is_array($os) ? (int) ($os['id'] ?? 0) : 0;
        $orcamento = is_array($os) ? ($os['orcamento'] ?? null) : null;
        $orcamentoId = is_array($orcamento) ? (int) ($orcamento['id'] ?? 0) : 0;
        $canViewOrcamento = \App\Support\DesktopSession::can('orcamentos', 'visualizar');
        $contraparteId = (int) ($contraparte['id'] ?? 0);
        $contraparteTipo = (string) ($contraparte['tipo'] ?? '');
        $canViewCliente = \App\Support\DesktopSession::can('clientes', 'visualizar');
        $canEditFornecedor = \App\Support\DesktopSession::can('fornecedores', 'editar');
        $canPay = in_array($status, ['pendente', 'parcial'], true) && $canEditFinanceiro;
        $canCancel = $status !== 'cancelado' && $canEditFinanceiro;
        $valorAberto = round((float) ($resumo['valor_aberto'] ?? $lancamento['valor'] ?? 0), 2);
        $hasMovements = in_array($status, ['parcial', 'pago'], true);
        $cancelConfirmMessage = $hasMovements
            ? 'Este lançamento já possui baixa registrada. Cancelar vai estornar (remover) os valores já lançados no fluxo de caixa e no DRE. Esta ação não pode ser desfeita. Deseja continuar?'
            : 'Deseja cancelar este lançamento? Ele deixará de contar no fluxo de caixa e no DRE, mas o registro é mantido.';
        $osIsEncerrada = (bool) ($lancamento['os_is_encerrada'] ?? false);

        $hasLinkActions = ($canViewOs && $osId > 0)
            || ($canViewOrcamento && $orcamentoId > 0)
            || ($contraparteTipo === 'cliente' && $canViewCliente && $contraparteId > 0)
            || ($contraparteTipo === 'fornecedor' && $canEditFornecedor && $contraparteId > 0);
        $hasFinanceiroSpecificActions = $canEditFinanceiro || $canPay || $canCancel || $canDeleteFinanceiro || $hasLinkActions;
        // "Ver lançamentos" sempre aparece: chegar nesta página já exige
        // financeiro,visualizar, a mesma permissão da listagem.
        $hasAnyAction = true;
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

            @if ($hasAnyAction)
            <div class="dropdown os-actions-dropdown">
                <button type="button"
                    class="btn btn-primary dropdown-toggle os-actions-toggle"
                    data-bs-toggle="dropdown"
                    aria-expanded="false">
                    Mais ações
                </button>

                <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                    <a href="{{ route('financeiro.index') }}" class="dropdown-item">
                        <i class="bi bi-list-ul me-2"></i>Ver lançamentos
                    </a>

                    @if ($canCreateFinanceiro)
                        <a href="{{ route('financeiro.create') }}" class="dropdown-item">
                            <i class="bi bi-plus-lg me-2"></i>Novo lançamento
                        </a>
                    @endif

                    @if ($hasFinanceiroSpecificActions)
                        <div class="dropdown-divider"></div>
                    @endif

                    @if ($canEditFinanceiro)
                        <a href="{{ route('financeiro.edit', $id) }}" class="dropdown-item">
                            <i class="bi bi-pencil me-2"></i>Editar lançamento
                        </a>
                    @endif

                    @if ($canPay)
                        <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#payModal{{ $id }}">
                            <i class="bi bi-cash-stack me-2"></i>Registrar baixa
                        </button>
                    @endif

                    @if (($canViewOs && $osId > 0) || ($canViewOrcamento && $orcamentoId > 0) || ($contraparteTipo === 'cliente' && $canViewCliente && $contraparteId > 0) || ($contraparteTipo === 'fornecedor' && $canEditFornecedor && $contraparteId > 0))
                        <div class="dropdown-divider"></div>
                    @endif

                    @if ($canViewOs && $osId > 0)
                        <a href="{{ route('orders.show', $osId) }}" class="dropdown-item">
                            <i class="bi bi-clipboard-check me-2"></i>Ver OS vinculada
                        </a>
                    @endif

                    @if ($canViewOrcamento && $orcamentoId > 0)
                        <a href="{{ route('orcamentos.show', $orcamentoId) }}" class="dropdown-item">
                            <i class="bi bi-receipt me-2"></i>Ver orçamento vinculado
                        </a>
                    @endif

                    @if ($contraparteTipo === 'cliente' && $canViewCliente && $contraparteId > 0)
                        <a href="{{ route('clients.show', $contraparteId) }}" class="dropdown-item">
                            <i class="bi bi-person me-2"></i>Ver cliente
                        </a>
                    @endif

                    @if ($contraparteTipo === 'fornecedor' && $canEditFornecedor && $contraparteId > 0)
                        <a href="{{ route('suppliers.edit', $contraparteId) }}" class="dropdown-item">
                            <i class="bi bi-truck me-2"></i>Ver fornecedor
                        </a>
                    @endif

                    @if ($canCancel || $canDeleteFinanceiro)
                        <div class="dropdown-divider"></div>
                    @endif

                    @if ($canCancel)
                        <form
                            id="financeiroCancelForm{{ $id }}"
                            method="post"
                            action="{{ route('financeiro.cancel', $id) }}"
                            @unless($osIsEncerrada)
                                data-confirm="{{ $cancelConfirmMessage }}"
                                data-confirm-title="Cancelar lançamento"
                                data-confirm-button="Sim, cancelar"
                            @endunless
                        >
                            @csrf
                            <input type="hidden" name="voltar_para" value="show">
                            @if ($osIsEncerrada)
                                <input type="hidden" name="motivo" value="" data-financeiro-cancel-motivo>
                                <input type="hidden" name="admin_email" value="" data-financeiro-cancel-admin-email>
                                <input type="hidden" name="admin_password" value="" data-financeiro-cancel-admin-password>
                            @endif
                            <button
                                type="{{ $osIsEncerrada ? 'button' : 'submit' }}"
                                class="dropdown-item text-warning"
                                @if ($osIsEncerrada)
                                    data-bs-toggle="modal"
                                    data-bs-target="#financeiroCancelReasonModal"
                                    data-target-form="#financeiroCancelForm{{ $id }}"
                                @endif
                            >
                                <i class="bi bi-x-circle me-2"></i>Cancelar lançamento
                            </button>
                        </form>
                    @endif

                    @if ($canDeleteFinanceiro)
                        @if ($osIsEncerrada)
                            <span class="dropdown-item disabled">
                                <i class="bi bi-lock me-2"></i>Excluir (OS encerrada — use Cancelar)
                            </span>
                        @else
                            <form id="financeiroDeleteForm{{ $id }}" method="post" action="{{ route('financeiro.destroy', $id) }}">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="voltar_para" value="show">
                                <input type="hidden" name="admin_email" value="" data-financeiro-delete-admin-email>
                                <input type="hidden" name="admin_password" value="" data-financeiro-delete-admin-password>
                                <button
                                    type="button"
                                    class="dropdown-item text-danger"
                                    data-bs-toggle="modal"
                                    data-bs-target="#financeiroDeleteAdminModal"
                                    data-target-form="#financeiroDeleteForm{{ $id }}"
                                >
                                    <i class="bi bi-trash me-2"></i>Excluir lançamento
                                </button>
                            </form>
                        @endif
                    @endif
                </div>
            </div>
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
            <span class="summary-card-eyebrow">{{ $text($contraparte['titulo'] ?? null, $tipo === 'receber' ? 'Quem pagou' : 'Para quem pagou') }}</span>
            <div class="summary-card-value">{{ $text($contraparte['nome'] ?? null, $tipo === 'receber' ? 'Cliente não vinculado' : 'Fornecedor não vinculado') }}</div>
            <div class="summary-card-meta">
                {{ $text($contraparte['documento'] ?? null, 'Documento não informado') }}
                @if ($text($contraparte['telefone'] ?? null, '') !== '')
                    · {{ $contraparte['telefone'] }}
                @endif
                @if ($text($contraparte['email'] ?? null, '') !== '')
                    · {{ $contraparte['email'] }}
                @endif
            </div>
        </article>
    </section>

    <div class="desktop-grid desktop-grid-two mb-4">
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
                    <div class="col-md-6">
                        <span class="summary-card-eyebrow">Tipo de origem</span>
                        <p class="mb-0 fw-semibold">{{ $text($origem['titulo'] ?? null, 'Origem não informada') }}</p>
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
                    <div class="col-md-6">
                        <span class="summary-card-eyebrow">{{ $tipo === 'receber' ? 'Recebido em' : 'Pago em' }}</span>
                        <p class="mb-0 fw-semibold">{{ $date($lancamento['data_pagamento'] ?? null) }}</p>
                    </div>
                    <div class="col-md-6">
                        <span class="summary-card-eyebrow">Forma de pagamento</span>
                        <p class="mb-0">{{ $text($detalhes['forma_pagamento_label'] ?? null, 'Não informada') }}</p>
                    </div>
                    <div class="col-12">
                        <span class="summary-card-eyebrow">Observações da contraparte</span>
                        <p class="mb-0">{{ $text($contraparte['observacoes'] ?? null, 'Nenhuma observação registrada.') }}</p>
                    </div>
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
    </div>

    <article class="surface-card mb-4">
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
                                        @if (! empty($movimento['conta_financeira']['nome']))
                                            <small class="text-secondary d-block">Conta: {{ $movimento['conta_financeira']['nome'] }}</small>
                                        @endif
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
@endsection

@if (($canPay ?? false) || ($canCancel && $osIsEncerrada) || ($canDeleteFinanceiro && ! $osIsEncerrada))
    @push('modals')
        @if ($canCancel && $osIsEncerrada)
            @include('financeiro._cancel_reason_modal')
        @endif

        @if ($canDeleteFinanceiro && ! $osIsEncerrada)
            @include('financeiro._delete_admin_modal')
        @endif

        @if ($canPay ?? false)
        <div class="modal fade" id="payModal{{ $id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" action="{{ route('financeiro.pay', $id) }}" data-financeiro-pay-form data-valor-aberto="{{ number_format($valorAberto, 2, '.', '') }}">
                        @csrf
                        <input type="hidden" name="voltar_para" value="show">
                        <div class="modal-header">
                            <h5 class="modal-title">Registrar baixa — Lançamento #{{ $id }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Valor da baixa</label>
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm" data-action="valor-total">
                                        <i class="bi bi-cash-coin me-1"></i>Valor total (R$ {{ number_format($valorAberto, 2, ',', '.') }})
                                    </button>
                                    <button type="button" class="btn btn-outline-light btn-sm" data-action="valor-parcial">
                                        <i class="bi bi-pie-chart me-1"></i>Valor parcial
                                    </button>
                                </div>
                                <input type="number" name="valor_movimento" class="form-control" step="0.01" min="0.01" max="{{ number_format($valorAberto, 2, '.', '') }}" data-field="valor_movimento" required>
                                <small class="text-secondary d-block mt-1">
                                    Saldo em aberto: R$ {{ number_format($valorAberto, 2, ',', '.') }}. Um valor parcial mantém o lançamento como "Parcial", com o valor pago e o saldo pendente calculados automaticamente.
                                </small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Data do movimento</label>
                                <input type="date" name="data_movimento" class="form-control" value="{{ now()->toDateString() }}">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Forma de pagamento</label>
                                <select name="forma_pagamento" class="form-select" data-field="forma_pagamento">
                                    <option value="">Não informado</option>
                                    <option value="dinheiro">Dinheiro</option>
                                    <option value="cartao_credito">Cartão de crédito</option>
                                    <option value="cartao_debito">Cartão de débito</option>
                                    <option value="pix">Pix</option>
                                    <option value="boleto">Boleto</option>
                                    <option value="transferencia">Transferência</option>
                                </select>
                            </div>
                            @include('financeiro._account_select', ['accountDataset' => $accountDataset ?? []])
                            <div class="d-none mb-3 pt-2 border-top" data-card-fields>
                                <div class="desktop-grid desktop-grid-two">
                                    <div>
                                        <label class="form-label">Operadora</label>
                                        <select class="form-select" name="operadora_id" data-field="operadora_id">
                                            <option value="">Selecione</option>
                                            @foreach ($cartaoDataset['operadoras'] ?? [] as $operadora)
                                                <option value="{{ $operadora['id'] }}">{{ $operadora['nome'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Bandeira (opcional)</label>
                                        <select class="form-select" name="bandeira_id" data-field="bandeira_id">
                                            <option value="">Genérica (qualquer bandeira)</option>
                                            @foreach ($cartaoDataset['bandeiras'] ?? [] as $bandeira)
                                                <option value="{{ $bandeira['id'] }}">{{ $bandeira['nome'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Modalidade</label>
                                        <select class="form-select" name="modalidade" data-field="modalidade">
                                            <option value="credito">Crédito</option>
                                            <option value="debito">Débito</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Parcelas</label>
                                        <input type="number" min="1" max="99" step="1" class="form-control" name="parcelas" value="1" data-field="parcelas">
                                    </div>
                                </div>
                                <p class="small text-secondary mt-2 mb-0" data-card-preview>Selecione operadora, modalidade e parcelas para estimar a taxa.</p>
                            </div>
                            <div>
                                <label class="form-label">Observações</label>
                                <textarea name="observacoes" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Confirmar baixa</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        @endif
    @endpush

    @section('scripts')
        <script>
            window.__DESKTOP_FINANCEIRO_INDEX = {!! json_encode([
                'cartao' => $cartaoDataset ?? ['operadoras' => [], 'bandeiras' => [], 'taxas' => []],
                'contasFinanceiras' => $accountDataset ?? ['contas' => [], 'contas_padrao' => []],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
        </script>
        @if ($canPay ?? false)
            <script src="{{ asset('assets/js/financeiro-pay.js') }}?v={{ filemtime(public_path('assets/js/financeiro-pay.js')) }}"></script>
        @endif
        @if ($canCancel && $osIsEncerrada)
            <script src="{{ asset('assets/js/financeiro-cancel-reason-modal.js') }}?v={{ filemtime(public_path('assets/js/financeiro-cancel-reason-modal.js')) }}"></script>
        @endif
        @if ($canDeleteFinanceiro && ! $osIsEncerrada)
            <script src="{{ asset('assets/js/financeiro-delete-admin-modal.js') }}?v={{ filemtime(public_path('assets/js/financeiro-delete-admin-modal.js')) }}"></script>
        @endif
    @endsection
@endif
