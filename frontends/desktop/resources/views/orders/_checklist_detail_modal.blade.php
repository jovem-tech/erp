{{--
    Modal de leitura do resultado completo do checklist de entrada da OS —
    aberto pelo botão "Ver checklist" no card "Defeito e Solução"
    (orders/show.blade.php). Só é incluído quando $checklist existe; os dados
    já vêm prontos em $order['checklist'] (mapEntryChecklist() no backend),
    sem necessidade de requisição adicional.
--}}
@php
    $checklistStatusLabels = [
        'ok' => 'OK',
        'discrepancia' => 'Discrepância',
        'nao_verificado' => 'Não verificado',
    ];
    $checklistRespostas = $checklist['respostas'] ?? [];
@endphp
<div class="modal fade" id="checklistDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-ui-checks me-2"></i>Checklist de entrada</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="desktop-chip">{{ ucfirst(str_replace('_', ' ', $checklist['status'] ?? 'rascunho')) }}</span>
                    <span class="desktop-chip">{{ $checklist['total_itens'] ?? 0 }} itens</span>
                    @if (($checklist['total_discrepancias'] ?? 0) > 0)
                        <span class="os-next-step">{{ $checklist['total_discrepancias'] }} discrepância(s)</span>
                    @else
                        <span class="desktop-chip">Sem discrepâncias</span>
                    @endif
                </div>

                @if (($checklist['resumo_texto'] ?? '') !== '')
                    <p class="surface-subtitle">{{ $checklist['resumo_texto'] }}</p>
                @endif

                @if ($checklistRespostas !== [])
                    <div class="table-responsive">
                        <table class="table table-stack align-middle">
                            <thead>
                            <tr>
                                <th>Item</th>
                                <th>Status</th>
                                <th>Observação</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($checklistRespostas as $resposta)
                                @php
                                    $respostaStatus = (string) ($resposta['status'] ?? '');
                                @endphp
                                <tr>
                                    <td data-label="Item">{{ ($resposta['descricao_item'] ?? '') !== '' ? $resposta['descricao_item'] : 'Item sem descrição' }}</td>
                                    <td data-label="Status">
                                        <span class="desktop-chip {{ $respostaStatus === 'discrepancia' ? 'desktop-chip-warning' : ($respostaStatus === 'ok' ? 'desktop-chip-success' : '') }}">
                                            {{ $checklistStatusLabels[$respostaStatus] ?? ucfirst(str_replace('_', ' ', $respostaStatus)) }}
                                        </span>
                                    </td>
                                    <td data-label="Observação">{{ ($resposta['observacao'] ?? '') !== '' ? $resposta['observacao'] : '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="os-info-table-empty mb-0">Nenhum item de checklist registrado.</p>
                @endif

                @if (($checklist['observacoes_estado'] ?? '') !== '')
                    <div class="mt-3">
                        <h4 class="os-panel-title mb-1">Observações do estado</h4>
                        <p class="mb-0">{{ $checklist['observacoes_estado'] }}</p>
                    </div>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>
