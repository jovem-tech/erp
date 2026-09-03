{{-- A tabela do ano: doze meses, uma linha cada.

     O alternador de regime troca a leitura NO CLIENTE — o resumo já traz os
     dois regimes de cada mês no bootstrap da página. Refetch ao trocar
     dobraria o custo da tela mais cara do módulo. --}}
@php
    $fmt = static fn ($valor) => 'R$ ' . number_format((float) ($valor ?? 0), 2, ',', '.');
    $nomesDosMeses = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
    $colunas = $mostrarIndustria ? 9 : 8;
@endphp

<section class="surface-table mb-4">
    <div class="surface-table-header">
        <div>
            <h2 class="surface-title">Relatórios do ano</h2>
            <p class="surface-subtitle mb-0">
                Cada linha é o Anexo X de um mês. Use a coluna de ações para conferir, editar, imprimir ou encerrar.
            </p>
        </div>

        <div class="d-flex flex-column align-items-end gap-1">
            <div class="dashboard-regime-switch" role="group" aria-label="Regime de apuração da tabela">
                <button type="button" class="dashboard-regime-option {{ $regime === 'competencia' ? 'is-active' : '' }}"
                        data-anexo-x-regime="competencia" aria-pressed="{{ $regime === 'competencia' ? 'true' : 'false' }}">
                    Competência
                </button>
                <button type="button" class="dashboard-regime-option {{ $regime === 'caixa' ? 'is-active' : '' }}"
                        data-anexo-x-regime="caixa" aria-pressed="{{ $regime === 'caixa' ? 'true' : 'false' }}">
                    Caixa
                </button>
            </div>

            <p class="surface-subtitle small mb-0 text-end" data-anexo-x-nota-regime
               data-nota-competencia="Competência é o regime que conta para o limite do MEI (receita bruta auferida no ano-calendário)."
               data-nota-caixa="Leitura gerencial. O regime de caixa não conta para o limite do MEI nem para a DASN-Simei.">
                Competência é o regime que conta para o limite do MEI (receita bruta auferida no ano-calendário).
            </p>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table align-middle mb-0" data-anexo-x-tabela>
            <thead>
                <tr>
                    <th>Mês</th>
                    <th class="text-end">Total (X)</th>
                    <th class="text-end">Comércio (III)</th>
                    @if ($mostrarIndustria)
                        <th class="text-end">Indústria (VI)</th>
                    @endif
                    <th class="text-end">Serviços (IX)</th>
                    <th class="text-end">Com documento</th>
                    <th class="text-end">Sem documento</th>
                    <th>Situação</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($meses as $mes)
                    @php
                        $dados = $mes['regimes'][$regime] ?? [];
                        $fechamento = $dados['fechamento'] ?? null;
                        $encerrado = ($fechamento['status'] ?? '') === 'fechado';
                    @endphp
                    <tr data-anexo-x-linha="{{ $mes['competencia'] }}"
                        @class(['anexo-x-linha-futura' => $mes['futuro']])>
                        <td>
                            {{ $nomesDosMeses[$mes['mes'] - 1] }}
                            @if ($mes['em_curso'])
                                <span class="badge text-bg-info ms-1">em curso</span>
                            @endif
                            <i class="bi bi-pencil-square ms-1 text-warning" data-anexo-x-marca-ajuste
                               title="Este mês tem ajuste manual declarado"
                               @style(['display: none' => (float) ($dados['ajuste_total'] ?? 0) == 0.0])></i>
                        </td>
                        <td class="text-end fw-semibold" data-anexo-x-celula="total">{{ $fmt($dados['total'] ?? 0) }}</td>
                        <td class="text-end" data-anexo-x-celula="comercio">{{ $fmt($dados['comercio'] ?? 0) }}</td>
                        @if ($mostrarIndustria)
                            <td class="text-end" data-anexo-x-celula="industria">{{ $fmt($dados['industria'] ?? 0) }}</td>
                        @endif
                        <td class="text-end" data-anexo-x-celula="servicos">{{ $fmt($dados['servicos'] ?? 0) }}</td>
                        <td class="text-end" data-anexo-x-celula="com_documento">{{ $fmt($dados['com_documento'] ?? 0) }}</td>
                        <td class="text-end" data-anexo-x-celula="sem_documento">{{ $fmt($dados['sem_documento'] ?? 0) }}</td>
                        <td data-anexo-x-celula="situacao">
                            @if ($encerrado)
                                <span class="badge text-bg-success"
                                      title="Encerrada por {{ $fechamento['fechado_por']['nome'] ?? '—' }} em {{ $fechamento['fechado_em'] ? \Carbon\Carbon::parse($fechamento['fechado_em'])->format('d/m/Y H:i') : '—' }}">
                                    <i class="bi bi-lock-fill me-1"></i>Encerrada v{{ $fechamento['versao'] }}
                                </span>
                            @elseif ($mes['futuro'])
                                <span class="badge text-bg-light">Futuro</span>
                            @else
                                <span class="badge text-bg-secondary">Aberta</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <div class="dropdown os-actions-dropdown">
                                <button type="button" class="btn btn-sm btn-outline-light dropdown-toggle"
                                        data-bs-toggle="dropdown" aria-expanded="false"
                                        aria-label="Ações de {{ $nomesDosMeses[$mes['mes'] - 1] }}">
                                    Ações
                                </button>

                                <div class="dropdown-menu dropdown-menu-end os-actions-menu">
                                    <button type="button" class="dropdown-item" data-bs-toggle="modal"
                                            data-bs-target="#modalReceitasDoMes"
                                            data-competencia="{{ $mes['competencia'] }}" data-mes="{{ $mes['mes'] }}">
                                        <i class="bi bi-list-ol me-2"></i>Receitas brutas do mês
                                    </button>

                                    <button type="button" class="dropdown-item" data-bs-toggle="modal"
                                            data-bs-target="#modalFormularioReceita"
                                            data-competencia="{{ $mes['competencia'] }}">
                                        <i class="bi bi-file-earmark-text me-2"></i>Ver no padrão da Receita Federal
                                    </button>

                                    @if ($podeEditar)
                                        <button type="button" class="dropdown-item" data-bs-toggle="modal"
                                                data-bs-target="#modalEditarRelatorio"
                                                data-competencia="{{ $mes['competencia'] }}" data-mes="{{ $mes['mes'] }}">
                                            <i class="bi bi-pencil-square me-2"></i>Editar o relatório
                                        </button>
                                    @endif

                                    <a class="dropdown-item" target="_blank" rel="noopener"
                                       href="{{ route('fiscal.anexo-x.pdf', ['competencia' => $mes['competencia'], 'regime' => $regime]) }}"
                                       data-anexo-x-pdf-mes="{{ $mes['competencia'] }}">
                                        <i class="bi bi-printer me-2"></i>Imprimir o PDF do mês
                                    </a>

                                    <button type="button" class="dropdown-item" data-bs-toggle="modal"
                                            data-bs-target="#modalOperacoesDoMes"
                                            data-competencia="{{ $mes['competencia'] }}">
                                        <i class="bi bi-card-list me-2"></i>Todas as operações do mês
                                    </button>

                                    @if ($podeEncerrar && ! $mes['futuro'])
                                        <div class="dropdown-divider"></div>

                                        @if ($encerrado)
                                            <button type="button" class="dropdown-item" data-bs-toggle="modal"
                                                    data-bs-target="#modalReconferir"
                                                    data-competencia="{{ $mes['competencia'] }}">
                                                <i class="bi bi-arrow-repeat me-2"></i>Reconferir
                                            </button>
                                            <button type="button" class="dropdown-item text-danger" data-bs-toggle="modal"
                                                    data-bs-target="#modalReabrirAnexoX"
                                                    data-competencia="{{ $mes['competencia'] }}"
                                                    data-periodo="{{ $mes['periodo_label'] }}">
                                                <i class="bi bi-unlock me-2"></i>Reabrir competência
                                            </button>
                                        @else
                                            <button type="button" class="dropdown-item" data-anexo-x-encerrar
                                                    data-competencia="{{ $mes['competencia'] }}">
                                                <i class="bi bi-lock me-2"></i>Encerrar competência
                                            </button>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="fw-bold">
                    <td>Total do ano</td>
                    <td class="text-end" data-anexo-x-total="total">{{ $fmt($totais['total'] ?? 0) }}</td>
                    <td class="text-end" data-anexo-x-total="comercio">{{ $fmt($totais['comercio'] ?? 0) }}</td>
                    @if ($mostrarIndustria)
                        <td class="text-end" data-anexo-x-total="industria">{{ $fmt($totais['industria'] ?? 0) }}</td>
                    @endif
                    <td class="text-end" data-anexo-x-total="servicos">{{ $fmt($totais['servicos'] ?? 0) }}</td>
                    <td class="text-end" data-anexo-x-total="com_documento">{{ $fmt($totais['com_documento'] ?? 0) }}</td>
                    <td class="text-end" data-anexo-x-total="sem_documento">{{ $fmt($totais['sem_documento'] ?? 0) }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <form method="post" action="{{ route('fiscal.anexo-x.fechar') }}" class="d-none" data-anexo-x-form-encerrar>
        @csrf
        <input type="hidden" name="competencia" value="">
        <input type="hidden" name="regime" value="{{ $regime }}">
    </form>
</section>
