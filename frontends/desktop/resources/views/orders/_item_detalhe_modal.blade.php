{{--
    Modal de detalhes do item do orçamento (peça OU serviço) — aberto pelo
    botão "Detalhes" de cada linha da seção "Peças e serviços do orçamento"
    (orders/show.blade.php).

    Um modal por item, renderizado direto do payload da OS (mapLinkedBudget()
    no backend), sem requisição adicional — mesmo padrão do
    _checklist_detail_modal. O que aparece depende do que o backend mandou:
      - `peca` só vem para quem tem estoque:visualizar E a peça existe no
        cadastro; `servico` só para quem tem servicos:visualizar E o serviço
        existe no catálogo (item digitado à mão não tem ficha);
      - custo/margem (`preco_custo_referencia`, `valor_margem`, `preco_custo`,
        `custo_direto_padrao`) só vêm para quem tem permissão financeira
        (specs/037). A redação é no payload, não aqui: se a chave não existe,
        o bloco não é montado.
--}}
@php
    $itemFmtQtd = static fn ($value): string => rtrim(rtrim(number_format((float) ($value ?? 0), 4, ',', '.'), '0'), ',');
    $itemFmtMoney = static fn ($value): string => 'R$ ' . number_format((float) ($value ?? 0), 2, ',', '.');
    $itemEstadoLabels = [
        'em_estoque' => ['Em estoque', 'desktop-chip-success'],
        'parcial' => ['Parcial', 'desktop-chip-warning'],
        'a_encomendar' => ['A encomendar', 'desktop-chip-danger'],
    ];
    $canOpenStock = \App\Support\DesktopSession::can('estoque', 'visualizar');
    $canEditStock = \App\Support\DesktopSession::can('estoque', 'editar');
    $canOpenServices = \App\Support\DesktopSession::can('servicos', 'visualizar');
    $canEditServices = \App\Support\DesktopSession::can('servicos', 'editar');
@endphp
@foreach ($orcamentoItens as $item)
    @php
        $itemId = (int) ($item['id'] ?? 0);
        $isPeca = (string) ($item['tipo_item'] ?? '') === 'peca';
        $peca = $isPeca && is_array($item['peca'] ?? null) ? $item['peca'] : null;
        $servico = ! $isPeca && is_array($item['servico'] ?? null) ? $item['servico'] : null;
        $ficha = $isPeca ? $peca : $servico;
        $temReferencia = (int) ($item['referencia_id'] ?? 0) > 0;
        $veCustoItem = array_key_exists('preco_custo_referencia', $item);
        $descricao = ($item['descricao'] ?? '') !== '' ? $item['descricao'] : 'Sem descrição';
        $quantidade = (float) ($item['quantidade'] ?? 0);
        $unidade = (string) ($ficha['unidade'] ?? '');
        $custoUnit = (float) ($item['preco_custo_referencia'] ?? 0);
        $custoTotal = round($custoUnit * $quantidade, 2);
        $margemPercentual = (float) ($item['percentual_margem'] ?? 0);
        $corMargem = $margemPercentual < 15 ? 'danger' : ($margemPercentual >= 30 ? 'success' : 'warning');
        // Custo atual do cadastro: preco_custo da peça / custo direto padrão do serviço.
        $custoAtualKey = $isPeca ? 'preco_custo' : 'custo_direto_padrao';
        $temCustoAtual = $ficha !== null && array_key_exists($custoAtualKey, $ficha);
        $custoAtual = (float) ($ficha[$custoAtualKey] ?? 0);
        $custoDivergente = $temCustoAtual && $veCustoItem && abs($custoAtual - $custoUnit) >= 0.01;
        [$estadoLabel, $estadoClass] = $itemEstadoLabels[(string) ($peca['estado'] ?? '')] ?? [null, null];

        $itemRows = array_filter([
            'Descrição no orçamento' => $descricao,
            'Quantidade' => trim($itemFmtQtd($quantidade) . ' ' . $unidade),
            'Valor unitário' => $itemFmtMoney($item['valor_unitario'] ?? 0),
            'Desconto' => (float) ($item['desconto'] ?? 0) > 0 ? $itemFmtMoney($item['desconto']) : '',
            'Acréscimo' => (float) ($item['acrescimo'] ?? 0) > 0 ? $itemFmtMoney($item['acrescimo']) : '',
            'Total' => $itemFmtMoney($item['total'] ?? 0),
            'Observações do item' => (string) ($item['observacoes'] ?? ''),
        ], static fn ($v) => trim((string) $v) !== '');

        if ($peca !== null) {
            $classificacao = implode(' › ', array_filter([
                $peca['tipo_equipamento_efetivo'] ?? '',
                $peca['estoque_categoria_nome'] ?? '',
                $peca['categoria_efetiva'] ?? '',
            ], static fn ($v) => trim((string) $v) !== ''));
            $fichaRows = array_filter([
                'Código' => (string) ($peca['codigo'] ?? ''),
                'Código do fabricante' => (string) ($peca['codigo_fabricante'] ?? ''),
                'Nome no cadastro' => (string) ($peca['nome'] ?? ''),
                'Classificação' => $classificacao,
                'Modelos compatíveis' => (string) ($peca['modelos_compativeis'] ?? ''),
                'Fornecedor' => (string) ($peca['fornecedor'] ?? ''),
                'Localização' => (string) ($peca['localizacao'] ?? ''),
                'Preço de venda (cadastro)' => $itemFmtMoney($peca['preco_venda'] ?? 0),
                'Observações da peça' => (string) ($peca['observacoes'] ?? ''),
            ], static fn ($v) => trim((string) $v) !== '');
        } elseif ($servico !== null) {
            $tempoPadrao = (float) ($servico['tempo_padrao_horas'] ?? 0);
            $aliquotaIss = $servico['aliquota_iss'] ?? null;
            $fichaRows = array_filter([
                'Nome no catálogo' => (string) ($servico['nome'] ?? ''),
                'Descrição' => (string) ($servico['descricao'] ?? ''),
                'Tipo de equipamento' => (string) ($servico['tipo_equipamento'] ?? ''),
                'Tempo padrão' => $tempoPadrao > 0 ? $itemFmtQtd($tempoPadrao) . ' h' : '',
                'Valor de catálogo' => $itemFmtMoney($servico['valor'] ?? 0),
                'Item LC 116' => (string) ($servico['item_lc116'] ?? ''),
                'Código de tributação nacional' => (string) ($servico['codigo_tributacao_nacional'] ?? ''),
                'Alíquota ISS' => $aliquotaIss !== null ? number_format((float) $aliquotaIss, 2, ',', '.') . '%' : '',
            ], static fn ($v) => trim((string) $v) !== '');
        } else {
            $fichaRows = [];
        }

        $estoqueRows = $peca !== null ? [
            'Saldo em estoque' => trim($itemFmtQtd($peca['quantidade_atual'] ?? 0) . ' ' . $unidade),
            'Reservado para este orçamento' => $itemFmtQtd($peca['reservado_para_este'] ?? 0),
            'Reservado para outros' => $itemFmtQtd($peca['reservado_por_terceiros'] ?? 0),
            'Disponível' => $itemFmtQtd($peca['quantidade_disponivel'] ?? 0),
            'Estoque mínimo' => $itemFmtQtd($peca['estoque_minimo'] ?? 0),
        ] : [];

        $canOpenFicha = $isPeca ? $canOpenStock : $canOpenServices;
        $tituloFicha = $isPeca ? 'No estoque' : 'No catálogo de serviços';
        $tituloModal = $isPeca ? 'Detalhes da peça' : 'Detalhes do serviço';
        $iconeModal = $isPeca ? 'bi-box-seam' : 'bi-tools';
        $nomeTipo = $isPeca ? 'peça' : 'serviço';
        $nomeCadastro = $isPeca ? 'cadastro de estoque' : 'catálogo de serviços';
    @endphp
    <div class="modal fade" id="osItemDetalheModal-{{ $itemId }}" tabindex="-1" aria-labelledby="osItemDetalheModalLabel-{{ $itemId }}" aria-hidden="true" data-os-item-detalhe-modal="{{ $itemId }}" data-os-item-tipo="{{ $isPeca ? 'peca' : 'servico' }}">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="osItemDetalheModalLabel-{{ $itemId }}">
                        <i class="bi {{ $iconeModal }} me-2"></i>{{ $tituloModal }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <span class="desktop-chip">{{ $isPeca ? 'Peça' : 'Serviço' }}</span>
                        @if ($peca !== null && ($peca['codigo'] ?? '') !== '')
                            <span class="desktop-chip">{{ $peca['codigo'] }}</span>
                        @endif
                        @if ($estadoLabel !== null)
                            <span class="desktop-chip {{ $estadoClass }}">{{ $estadoLabel }}</span>
                        @endif
                        @if ($ficha !== null && ! ($ficha['ativo'] ?? true))
                            <span class="desktop-chip desktop-chip-warning">{{ ucfirst($nomeTipo) }} {{ $isPeca ? 'inativa' : 'inativo' }} no cadastro</span>
                        @endif
                        @if ($custoDivergente)
                            <span class="desktop-chip desktop-chip-warning" title="O custo gravado no orçamento difere do custo atual do cadastro">Custo mudou desde o orçamento</span>
                        @endif
                    </div>

                    <p class="surface-subtitle">{{ $descricao }}</p>

                    <div class="desktop-grid desktop-grid-two">
                        <div class="os-subcard">
                            <h4 class="os-panel-title">No orçamento</h4>
                            <table class="os-info-table">
                                <tbody>
                                @foreach ($itemRows as $label => $value)
                                    <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="os-subcard">
                            <h4 class="os-panel-title">{{ $tituloFicha }}</h4>
                            @if ($ficha !== null)
                                <table class="os-info-table">
                                    <tbody>
                                    @foreach ($fichaRows as $label => $value)
                                        <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            @elseif (! $temReferencia)
                                <p class="os-info-table-empty mb-0">{{ ucfirst($nomeTipo) }} {{ $isPeca ? 'digitada' : 'digitado' }} à mão no orçamento, sem vínculo com o {{ $nomeCadastro }}.</p>
                            @elseif (! $canOpenFicha)
                                <p class="os-info-table-empty mb-0">A ficha do {{ $nomeCadastro }} não está disponível para o seu perfil.</p>
                            @else
                                <p class="os-info-table-empty mb-0">{{ ucfirst($nomeTipo) }} {{ $isPeca ? 'referenciada' : 'referenciado' }} por este item não existe mais no {{ $nomeCadastro }}.</p>
                            @endif
                        </div>
                    </div>

                    @if ($peca !== null || $veCustoItem)
                        {{-- Segunda linha: Saldo e Custo lado a lado (quando só um
                             existe, ele ocupa a largura toda) — evita empilhar
                             tabela de uma coluna e dobrar a rolagem do modal. --}}
                        <div class="desktop-grid desktop-grid-two mt-3">
                            @if ($peca !== null)
                                <div class="os-subcard {{ $veCustoItem ? '' : 'desktop-grid-span-2' }}">
                                    <h4 class="os-panel-title">Saldo</h4>
                                    <table class="os-info-table">
                                        <tbody>
                                        @foreach ($estoqueRows as $label => $value)
                                            <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
                                        @endforeach
                                        @if ((float) ($peca['falta'] ?? 0) > 0)
                                            <tr><th>Falta para esta OS</th><td class="text-danger fw-semibold">{{ $itemFmtQtd($peca['falta']) }}</td></tr>
                                        @endif
                                        </tbody>
                                    </table>
                                </div>
                            @endif

                            @if ($veCustoItem)
                                {{-- Só existe quando o backend mandou custo (specs/037). --}}
                                <div class="os-subcard {{ $peca !== null ? '' : 'desktop-grid-span-2' }}" data-os-item-custo>
                                    <h4 class="os-panel-title">Custo e margem</h4>
                                    <table class="os-info-table">
                                        <tbody>
                                        <tr><th>Custo unitário (no orçamento)</th><td>{{ $itemFmtMoney($custoUnit) }}</td></tr>
                                        <tr><th>Custo total ({{ $itemFmtQtd($quantidade) }} × unit.)</th><td>{{ $itemFmtMoney($custoTotal) }}</td></tr>
                                        @if ($temCustoAtual)
                                            <tr>
                                                <th>{{ $isPeca ? 'Custo atual no cadastro' : 'Custo direto padrão (catálogo)' }}</th>
                                                <td class="{{ $custoDivergente ? 'text-warning fw-semibold' : '' }}">{{ $itemFmtMoney($custoAtual) }}</td>
                                            </tr>
                                        @endif
                                        <tr>
                                            <th>Margem da linha</th>
                                            <td class="text-{{ $corMargem }}">
                                                {{ $itemFmtMoney($item['valor_margem'] ?? 0) }}
                                                <small class="d-block">{{ number_format($margemPercentual, 1, ',', '.') }}%</small>
                                            </td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    @if ($peca !== null && $canEditStock)
                        <a href="{{ route('estoque.edit', $peca['id']) }}" class="btn btn-soft me-auto">
                            <i class="bi bi-box-arrow-up-right me-2"></i>Abrir no estoque
                        </a>
                    @elseif ($peca !== null && $canOpenStock)
                        <a href="{{ route('estoque.movements', $peca['id']) }}" class="btn btn-soft me-auto">
                            <i class="bi bi-box-arrow-up-right me-2"></i>Ver movimentações
                        </a>
                    @elseif ($servico !== null && $canEditServices)
                        <a href="{{ route('servicos.edit', $servico['id']) }}" class="btn btn-soft me-auto">
                            <i class="bi bi-box-arrow-up-right me-2"></i>Abrir no catálogo
                        </a>
                    @endif
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
@endforeach
