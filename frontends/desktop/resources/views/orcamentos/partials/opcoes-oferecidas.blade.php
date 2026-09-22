{{-- Opções de manutenção oferecidas ao cliente (Básica/Avançada/Completa).

     Antes da decisão, vem da projeção viva (`niveis_ofertados.origem = atual`);
     depois, do snapshot gravado na aprovação (`origem = aprovacao`) — os
     itens do orçamento já são só o escopo contratado, e é este bloco que
     preserva o que foi apresentado: cartões por opção, o comparativo item ×
     opção (com os itens que saíram do escopo) e as condições por opção.

     Espera: $offered (niveis_ofertados), $approvedLevel (int|null),
     $approvedLevelLabel (string). --}}
@php
    $offered = is_array($offered ?? null) ? $offered : [];
    $offeredLevels = is_array($offered['niveis'] ?? null) ? array_values($offered['niveis']) : [];
    $offeredLayout = is_array($offered['layout'] ?? null) ? $offered['layout'] : [];
    $offeredApproval = is_array($offered['aprovacao'] ?? null) ? $offered['aprovacao'] : [];
    $fromApproval = (string) ($offered['origem'] ?? '') === 'aprovacao';
    $approvedLevel = (int) ($approvedLevel ?? 0);
    $approvedLevelLabel = trim((string) ($approvedLevelLabel ?? ''));
    $approvalIsCurrent = $fromApproval && ! empty($offeredApproval['vigente']);
    $snapshotLevel = (int) ($offeredApproval['nivel'] ?? 0);
    // Coluna/cartão marcado como escolhido: a opção aprovada vigente, ou a
    // que a aprovação registrou (histórico de uma decisão já reaberta).
    $chosenLevel = $approvalIsCurrent ? $approvedLevel : ($fromApproval ? $snapshotLevel : 0);
    $money = static fn (float $valor): string => 'R$ '.number_format($valor, 2, ',', '.');
    $shortLabel = static fn (string $label): string => str_starts_with($label, 'Manutenção ') ? substr($label, strlen('Manutenção ')) : $label;
    $termPerOption = static fn (string $campo): bool => (($offeredLayout[$campo]['modo'] ?? 'compartilhado') === 'por_opcao');
    $anyBenefits = collect($offeredLevels)->contains(static fn (array $level): bool => ! empty($level['condicoes_comerciais']['beneficios'] ?? []));
    $termsVary = $anyBenefits || $termPerOption('garantia') || $termPerOption('parcelamento') || $termPerOption('entrega_domicilio') || $termPerOption('formas_pagamento');

    // Comparativo: uma linha por item apresentado (união das opções), na
    // ordem em que apareceram; ✓/— por opção. Snapshot antigo (só nomes)
    // vira linhas sem qtd/unitário.
    $offeredRows = [];
    foreach ($offeredLevels as $level) {
        $nivel = (int) ($level['nivel'] ?? 0);
        $detalhe = is_array($level['itens_detalhe'] ?? null) ? $level['itens_detalhe'] : [];
        if ($detalhe !== []) {
            foreach ($detalhe as $item) {
                $key = 'id:'.(int) ($item['id'] ?? 0).':'.trim((string) ($item['descricao'] ?? ''));
                $offeredRows[$key] ??= [
                    'descricao' => trim((string) ($item['descricao'] ?? '')),
                    'tipo_item' => trim((string) ($item['tipo_item'] ?? '')),
                    'quantidade' => (float) ($item['quantidade'] ?? 0),
                    'valor_unitario' => (float) ($item['valor_unitario'] ?? 0),
                    'niveis' => [],
                ];
                $offeredRows[$key]['niveis'][] = $nivel;
            }
            continue;
        }
        foreach (is_array($level['itens'] ?? null) ? $level['itens'] : [] as $descricao) {
            $key = 'nome:'.trim((string) $descricao);
            $offeredRows[$key] ??= ['descricao' => trim((string) $descricao), 'tipo_item' => '', 'quantidade' => null, 'valor_unitario' => null, 'niveis' => []];
            $offeredRows[$key]['niveis'][] = $nivel;
        }
    }
    $offeredRows = array_values($offeredRows);
    $rowsHaveValues = collect($offeredRows)->contains(static fn (array $row): bool => $row['quantidade'] !== null);
    $approvalWhen = trim((string) ($offeredApproval['created_at'] ?? ''));
    $approvalHow = trim((string) ($offeredApproval['origem_label'] ?? ''));
    $approvalWho = trim((string) ($offeredApproval['usuario_nome'] ?? ''));
    $approvalByStaff = (string) ($offeredApproval['origem'] ?? '') === 'painel' && $approvalWho !== '';
@endphp
@if ($offeredLevels !== [])
    <section class="surface-card mb-4" id="opcoes" data-budget-offered-options data-budget-offered-origin="{{ $fromApproval ? 'aprovacao' : 'atual' }}">
        <div class="surface-card-header align-items-start mb-3">
            <div>
                <p class="desktop-eyebrow mb-2">Opções de manutenção</p>
                @if ($fromApproval)
                    <h2 class="surface-title fs-5 mb-1">
                        @if ($approvalIsCurrent)
                            O cliente escolheu a {{ $approvedLevelLabel !== '' ? $approvedLevelLabel : ('opção '.$chosenLevel) }}
                        @else
                            Histórico: o cliente havia escolhido a {{ $offeredApproval['nivel_label'] ?? ('opção '.$chosenLevel) }}
                        @endif
                    </h2>
                    @php
                        $approvalSentence = 'Estas foram as '.count($offeredLevels).' opções apresentadas ao cliente';
                        if ($approvalWhen !== '') {
                            $approvalSentence .= ' · aprovada em '.$approvalWhen.($approvalByStaff ? ' por '.$approvalWho : '').($approvalHow !== '' ? ' '.$approvalHow : '');
                        }
                    @endphp
                    <p class="surface-subtitle mb-0">
                        {{ $approvalSentence }}.
                        @if ($approvalIsCurrent)
                            Os itens do orçamento abaixo são o escopo contratado; as demais opções ficam aqui como registro do que foi oferecido.
                        @else
                            O orçamento foi alterado depois dessa decisão; o registro fica aqui para consulta.
                        @endif
                    </p>
                @else
                    <h2 class="surface-title fs-5 mb-1">O cliente escolhe uma destas opções na página de aprovação</h2>
                    <p class="surface-subtitle mb-0">Cada item aparece só nas opções marcadas para ele (colunas na lista de itens abaixo). Ao aprovar, o orçamento passa a conter só os itens da opção escolhida.</p>
                @endif
            </div>
        </div>

        <div class="desktop-grid desktop-grid-three">
            @foreach ($offeredLevels as $level)
                @php
                    $levelNumber = (int) ($level['nivel'] ?? 0);
                    $isChosen = $chosenLevel > 0 && $levelNumber === $chosenLevel;
                    $levelTerms = is_array($level['condicoes_comerciais'] ?? null) ? $level['condicoes_comerciais'] : [];
                    $levelPerks = [];
                    if ($termPerOption('garantia') && ($levelTerms['garantia_label'] ?? '') !== '') {
                        $levelPerks[] = 'Garantia de '.$levelTerms['garantia_label'];
                    }
                    if ($termPerOption('parcelamento') && ($levelTerms['parcelamento_texto'] ?? '') !== '') {
                        $levelPerks[] = rtrim((string) $levelTerms['parcelamento_texto'], '.');
                    }
                    if ($termPerOption('entrega_domicilio') && ! empty($levelTerms['entrega_domicilio'])) {
                        $levelPerks[] = (string) ($levelTerms['entrega_domicilio_label'] ?? 'Entrega no endereço');
                    }
                    if ($termPerOption('formas_pagamento') && ($levelTerms['formas_pagamento_texto'] ?? '') !== '') {
                        $levelPerks[] = 'Pagamento: '.$levelTerms['formas_pagamento_texto'];
                    }
                    foreach (is_array($levelTerms['beneficios'] ?? null) ? $levelTerms['beneficios'] : [] as $beneficio) {
                        $levelPerks[] = (string) $beneficio;
                    }
                @endphp
                <article class="summary-card {{ $fromApproval ? ($isChosen ? 'is-highlight' : 'budget-offered-not-chosen') : (! empty($level['recomendado']) ? 'is-highlight' : '') }}" data-budget-offered-card="{{ $levelNumber }}" data-chosen="{{ $isChosen ? '1' : '0' }}">
                    <span class="summary-card-eyebrow">{{ $level['label'] ?? '' }}{{ ! empty($level['recomendado']) ? ' · Recomendada' : '' }}</span>
                    <div class="summary-card-value">{{ $money((float) ($level['total'] ?? 0)) }}</div>
                    <div class="summary-card-meta">{{ (int) ($level['itens_count'] ?? 0) }} {{ (int) ($level['itens_count'] ?? 0) === 1 ? 'item' : 'itens' }}{{ ($level['subtitle'] ?? '') !== '' ? ' · ' . $level['subtitle'] : '' }}</div>
                    @if ($fromApproval)
                        <div class="mt-2">
                            @if ($isChosen)
                                <span class="desktop-chip desktop-chip-success"><i class="bi bi-check-circle"></i> {{ $approvalIsCurrent ? 'Aprovada pelo cliente' : 'Escolhida na época' }}</span>
                            @else
                                <span class="desktop-chip">Não escolhida</span>
                            @endif
                        </div>
                    @endif
                    @if ($levelPerks !== [])
                        <ul class="budget-offered-perks">
                            @foreach ($levelPerks as $perk)
                                <li>{{ $perk }}</li>
                            @endforeach
                        </ul>
                    @endif
                </article>
            @endforeach
        </div>

        @if (! $termsVary && $fromApproval)
            <p class="text-secondary small mt-3 mb-0">Mesmas condições comerciais em todas as opções.</p>
        @endif

        @if ($fromApproval && $offeredRows !== [])
            {{-- Pré-aprovação a tabela de itens já tem as colunas ✓/— (os itens
                 ainda estão todos lá); depois da poda o comparativo só existe
                 aqui, a partir do snapshot. --}}
            <div class="mt-4">
                <p class="fw-semibold mb-2">Comparativo do que foi oferecido</p>
                <div class="table-responsive">
                    <table class="table table-stack budget-offered-table mb-0" data-budget-offered-table>
                        <thead>
                            <tr>
                                <th scope="col">Item</th>
                                @if ($rowsHaveValues)
                                    <th scope="col" class="text-center">Qtd</th>
                                    <th scope="col" class="text-end">Unitário</th>
                                @endif
                                @foreach ($offeredLevels as $level)
                                    @php $levelNumber = (int) ($level['nivel'] ?? 0); @endphp
                                    <th scope="col" class="text-center {{ $chosenLevel === $levelNumber ? 'is-approved' : '' }}" data-budget-offered-col="{{ $levelNumber }}">
                                        {{ $shortLabel((string) ($level['label'] ?? '')) }}
                                        @if ($chosenLevel === $levelNumber)
                                            <span class="d-block small fw-normal">{{ $approvalIsCurrent ? 'aprovada' : 'escolhida' }}</span>
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($offeredRows as $row)
                                @php $inChosen = $chosenLevel > 0 && in_array($chosenLevel, $row['niveis'], true); @endphp
                                <tr class="{{ $fromApproval && ! $inChosen ? 'budget-offered-row-out' : '' }}">
                                    <td data-label="Item">
                                        {{ $row['descricao'] }}
                                        @if ($row['tipo_item'] !== '')
                                            <span class="d-block small text-secondary">{{ $row['tipo_item'] === 'peca' ? 'Peça' : ($row['tipo_item'] === 'servico' ? 'Serviço' : ucfirst($row['tipo_item'])) }}{{ $fromApproval && ! $inChosen ? ' · fora do escopo contratado' : '' }}</span>
                                        @endif
                                    </td>
                                    @if ($rowsHaveValues)
                                        <td class="text-center" data-label="Qtd">{{ $row['quantidade'] !== null ? number_format((float) $row['quantidade'], (float) $row['quantidade'] === floor((float) $row['quantidade']) ? 0 : 2, ',', '.') : '—' }}</td>
                                        <td class="text-end" data-label="Unitário">{{ $row['valor_unitario'] !== null ? $money((float) $row['valor_unitario']) : '—' }}</td>
                                    @endif
                                    @foreach ($offeredLevels as $level)
                                        @php
                                            $levelNumber = (int) ($level['nivel'] ?? 0);
                                            $included = in_array($levelNumber, $row['niveis'], true);
                                        @endphp
                                        <td class="text-center {{ $chosenLevel === $levelNumber ? 'is-approved' : '' }}" data-label="{{ $shortLabel((string) ($level['label'] ?? '')) }}" data-budget-offered-cell="{{ $levelNumber }}" data-included="{{ $included ? '1' : '0' }}">
                                            @if ($included)
                                                <i class="bi bi-check-lg text-success" aria-hidden="true"></i><span class="visually-hidden">Incluído</span>
                                            @else
                                                <span class="text-secondary" aria-hidden="true">—</span><span class="visually-hidden">Não incluído</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="row" {{ $rowsHaveValues ? 'colspan=3' : '' }}>Total da opção</th>
                                @foreach ($offeredLevels as $level)
                                    @php $levelNumber = (int) ($level['nivel'] ?? 0); @endphp
                                    <th class="text-center {{ $chosenLevel === $levelNumber ? 'is-approved' : '' }}" data-label="Total {{ $shortLabel((string) ($level['label'] ?? '')) }}">{{ $money((float) ($level['total'] ?? 0)) }}</th>
                                @endforeach
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @endif
    </section>
@endif
