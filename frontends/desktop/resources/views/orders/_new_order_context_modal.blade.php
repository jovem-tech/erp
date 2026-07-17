@php
    $modalClient = is_array($order['cliente'] ?? null) ? $order['cliente'] : [];
    $modalEquipment = is_array($order['equipamento'] ?? null) ? $order['equipamento'] : [];
    $modalClientName = trim((string) ($modalClient['nome_razao'] ?? ''));
    $modalEquipmentSummary = trim((string) ($modalEquipment['resumo_tecnico'] ?? ''));
    $modalEquipmentIdentity = implode(' · ', array_values(array_filter([
        trim((string) ($modalEquipment['tipo_nome'] ?? '')),
        trim((string) ($modalEquipment['marca_nome'] ?? '')),
        trim((string) ($modalEquipment['modelo_nome'] ?? '')),
    ], static fn (string $value): bool => $value !== '')));
    $modalEquipmentLabel = $modalEquipmentSummary !== ''
        ? $modalEquipmentSummary
        : ($modalEquipmentIdentity !== ''
            ? $modalEquipmentIdentity
            : 'o equipamento desta OS');
@endphp

<div class="modal fade" id="newOrderFromOrderModal" tabindex="-1" aria-labelledby="newOrderFromOrderModalLabel" aria-describedby="newOrderFromOrderModalDescription" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <p class="desktop-eyebrow mb-1">Nova ordem de serviço</p>
                    <h2 class="modal-title fs-5" id="newOrderFromOrderModalLabel">
                        {{ $modalClientName !== '' ? $modalClientName : 'Cliente desta OS' }}
                    </h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <p id="newOrderFromOrderModalDescription" class="mb-3">
                    Esta nova OS será para o mesmo equipamento ou para um equipamento novo?
                </p>

                <div class="d-grid gap-3">
                    <a href="{{ $newOrderSameEquipmentUrl }}" class="btn btn-primary text-start p-3" data-new-order-choice="same-equipment">
                        <span class="d-block fw-bold"><i class="bi bi-arrow-repeat me-2"></i>Mesmo equipamento</span>
                        <small class="d-block mt-1 opacity-75">Preencher cliente e {{ $modalEquipmentLabel }}.</small>
                    </a>

                    <a href="{{ $newOrderClientUrl }}" class="btn btn-outline-light text-start p-3" data-new-order-choice="new-equipment">
                        <span class="d-block fw-bold"><i class="bi bi-laptop me-2"></i>Equipamento novo</span>
                        <small class="d-block mt-1 text-secondary">Preencher somente o cliente; o equipamento será escolhido ou cadastrado na próxima tela.</small>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
