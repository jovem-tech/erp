{{-- Cliente / Contato / Equipamento / OS vinculada. Extraído do hero para ser
     usado nos dois cabeçalhos: aberto (orçamento comum e passo 2) e dentro
     de "Ver detalhes do atendimento" (landing dos níveis de manutenção). --}}
<div class="meta-grid">
    <div class="meta-item">
        <span class="meta-label">Cliente</span>
        <div class="meta-value">{{ $budget['client_name'] !== '' ? $budget['client_name'] : 'Não informado' }}</div>
    </div>
    <div class="meta-item">
        <span class="meta-label">Contato</span>
        <div class="meta-value">{{ $budget['phone'] !== '' ? $budget['phone'] : 'Não informado' }}</div>
    </div>
    <div class="meta-item">
        <span class="meta-label">Equipamento</span>
        <div class="meta-value">{{ $budget['equipment_name'] !== '' ? $budget['equipment_name'] : 'Não informado' }}</div>
    </div>
    <div class="meta-item">
        <span class="meta-label">OS vinculada</span>
        <div class="meta-value">{{ $budget['order_number'] !== '' ? $budget['order_number'] : 'Sem vínculo' }}</div>
    </div>
</div>
