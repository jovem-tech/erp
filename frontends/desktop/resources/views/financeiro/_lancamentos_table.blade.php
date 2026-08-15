{{--
    Tabela de lançamentos + modais de baixa/cancelamento/exclusão, extraída
    de index.blade.php para ser reaproveitada também por despesas-fixas.blade.php
    (mesmo markup e mesma lógica de permissões nos dois lugares — evita
    duplicar ~250 linhas com os modais). Espera $lancamentos, $pagination,
    $filters, $cartaoDataset, $accountDataset no escopo (via @include com
    array explícito ou por herança normal de variáveis do Blade).
--}}
<section class="surface-table">
    <div class="surface-table-header">
        <div>
            <h2 class="surface-title">Títulos financeiros</h2>
            <p class="surface-subtitle">
                {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} lançamentos retornados pela API central.
            </p>
        </div>

        <span class="desktop-chip">
            <i class="bi bi-cash-coin"></i>
            {{ number_format((int) ($pagination['total'] ?? 0), 0, ',', '.') }} registros
        </span>
    </div>

    @if ($lancamentos !== [])
        <div class="table-responsive">
            <table class="table table-stack align-middle">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Tipo</th>
                    <th>Categoria</th>
                    <th>Valor</th>
                    <th>Vencimento</th>
                    <th>Status</th>
                    <th class="text-end">Ações</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($lancamentos as $lancamento)
                    @php
                        $id = (int) ($lancamento['id'] ?? 0);
                        $tipo = (string) ($lancamento['tipo'] ?? '');
                        $status = (string) ($lancamento['status'] ?? 'pendente');
                        $statusColors = [
                            'pendente' => '#f59e0b',
                            'parcial' => '#3b82f6',
                            'pago' => '#29c384',
                            'cancelado' => '#8b93a7',
                        ];
                        $canPay = in_array($status, ['pendente', 'parcial'], true);
                        $valorAberto = round((float) ($lancamento['valor_aberto'] ?? $lancamento['valor'] ?? 0), 2);
                        $osIsEncerrada = (bool) ($lancamento['os_is_encerrada'] ?? false);
                    @endphp
                    <tr>
                        <td data-label="ID">{{ $id > 0 ? $id : '-' }}</td>
                        <td data-label="Tipo">
                            <span class="badge {{ $tipo === 'receber' ? 'text-bg-success' : 'text-bg-secondary' }}">
                                {{ $tipo === 'receber' ? 'A receber' : 'A pagar' }}
                            </span>
                        </td>
                        <td data-label="Categoria">
                            <div class="fw-semibold">{{ $lancamento['categoria'] ?? 'Sem categoria' }}</div>
                            @php $origemTrilha = array_filter((array) ($lancamento['origem_trilha'] ?? [])); @endphp
                            @if ($origemTrilha !== [])
                                <small class="text-secondary d-block">{{ implode(' | ', $origemTrilha) }}</small>
                            @endif
                        </td>
                        <td data-label="Valor">R$ {{ number_format((float) ($lancamento['valor'] ?? 0), 2, ',', '.') }}</td>
                        <td data-label="Vencimento">{{ !empty($lancamento['data_vencimento']) ? \Illuminate\Support\Carbon::parse($lancamento['data_vencimento'])->format('d/m/Y') : '-' }}</td>
                        <td data-label="Status">
                            @include('layouts.partials.status-pill', [
                                'label' => ucfirst($status),
                                'color' => $statusColors[$status] ?? '#8b93a7',
                                'small' => true,
                            ])
                        </td>
                        <td data-label="Ações" class="text-end">
                            <x-list-actions>
                                <li>
                                    <a href="{{ route('financeiro.show', $id) }}" class="dropdown-item">
                                        <i class="bi bi-eye me-2"></i>
                                        Detalhes
                                    </a>
                                </li>

                                @if (\App\Support\DesktopSession::can('financeiro', 'editar'))
                                    <li>
                                        <a href="{{ route('financeiro.edit', $id) }}" class="dropdown-item">
                                            <i class="bi bi-pencil me-2"></i>
                                            Editar
                                        </a>
                                    </li>
                                @endif

                                @if ($canPay && \App\Support\DesktopSession::can('financeiro', 'editar'))
                                    <li>
                                        <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#payModal{{ $id }}">
                                            <i class="bi bi-cash-stack me-2"></i>
                                            Registrar baixa
                                        </button>
                                    </li>
                                @endif

                                @if ($status !== 'cancelado' && \App\Support\DesktopSession::can('financeiro', 'editar'))
                                    @php
                                        $hasMovements = in_array($status, ['parcial', 'pago'], true);
                                        $cancelConfirmMessage = $hasMovements
                                            ? 'Este lançamento já possui baixa registrada. Cancelar vai estornar (remover) os valores já lançados no fluxo de caixa e no DRE. Esta ação não pode ser desfeita. Deseja continuar?'
                                            : 'Deseja cancelar este lançamento? Ele deixará de contar no fluxo de caixa e no DRE, mas o registro é mantido.';
                                    @endphp
                                    <li>
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
                                                <i class="bi bi-x-circle me-2"></i>
                                                Cancelar
                                            </button>
                                        </form>
                                    </li>
                                @endif

                                @if (\App\Support\DesktopSession::can('financeiro', 'excluir'))
                                    <li>
                                        @if ($osIsEncerrada)
                                            <span class="dropdown-item disabled">
                                                <i class="bi bi-lock me-2"></i>
                                                Excluir (OS encerrada — use Cancelar)
                                            </span>
                                        @else
                                            <form id="financeiroDeleteForm{{ $id }}" method="post" action="{{ route('financeiro.destroy', $id) }}">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="admin_email" value="" data-financeiro-delete-admin-email>
                                                <input type="hidden" name="admin_password" value="" data-financeiro-delete-admin-password>
                                                <button
                                                    type="button"
                                                    class="dropdown-item text-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#financeiroDeleteAdminModal"
                                                    data-target-form="#financeiroDeleteForm{{ $id }}"
                                                >
                                                    <i class="bi bi-trash me-2"></i>
                                                    Excluir
                                                </button>
                                            </form>
                                        @endif
                                    </li>
                                @endif
                            </x-list-actions>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        {{--
            Os modais de baixa ficam FORA da tabela de propósito: um <div>
            dentro de <tbody> é HTML inválido e o navegador aplica "foster
            parenting" (move o conteúdo para antes da <table>), o que
            quebra a estrutura do <form> e esvazia seus campos — o botão
            "Confirmar baixa" chegava a submeter o formulário sem nenhum
            dado. Por isso os modais são renderizados num loop separado,
            fora de <table>/<tbody>.
        --}}
        @foreach ($lancamentos as $lancamento)
            @php
                $id = (int) ($lancamento['id'] ?? 0);
                $status = (string) ($lancamento['status'] ?? 'pendente');
                $canPay = in_array($status, ['pendente', 'parcial'], true);
                $valorAberto = round((float) ($lancamento['valor_aberto'] ?? $lancamento['valor'] ?? 0), 2);
            @endphp
            @if ($canPay)
                <div class="modal fade" id="payModal{{ $id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <form method="post" action="{{ route('financeiro.pay', $id) }}" data-financeiro-pay-form data-valor-aberto="{{ number_format($valorAberto, 2, '.', '') }}">
                                @csrf
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
        @endforeach

        @include('layouts.partials.pagination', ['pagination' => $pagination, 'filters' => $filters])
    @else
        @include('layouts.partials.empty-state', [
            'icon' => 'bi-cash-coin',
            'title' => $emptyTitle ?? 'Nenhum lançamento encontrado',
            'message' => $emptyMessage ?? 'Ajuste os filtros ou cadastre o primeiro lançamento financeiro para começar o acompanhamento.',
        ])
    @endif
</section>

@include('financeiro._cancel_reason_modal')
@include('financeiro._delete_admin_modal')
