@php
    $lancamento = $lancamento ?? [];
    $formMethod = strtoupper((string) ($formMethod ?? 'POST'));
    $tipo = old('tipo', $lancamento['tipo'] ?? 'receber');
    $status = old('status', $lancamento['status'] ?? 'pendente');
    $hasMovements = (int) ($resumo['total_movimentos'] ?? 0) > 0;
    $avulso = filter_var(old('avulso', $lancamento['avulso'] ?? false), FILTER_VALIDATE_BOOL);
@endphp

<section class="desktop-form-card">
    <div class="desktop-form-intro">
        <div class="desktop-form-intro-copy">
            <h2 class="surface-title mb-1">{{ $formTitle ?? 'Lançamento financeiro' }}</h2>
            <p class="surface-subtitle mb-0">
                {{ $formSubtitle ?? 'Título a receber ou a pagar, com classificação de DRE resolvida automaticamente pela categoria selecionada.' }}
            </p>
        </div>

        @if ($hasMovements)
            <span class="badge rounded-pill text-bg-warning">
                Já possui baixa registrada — tipo, cancelamento e redução de valor ficam bloqueados.
            </span>
        @endif
    </div>

    <form method="post" action="{{ $formAction }}" class="desktop-form-stack" id="financeiroForm">
        @csrf
        @if ($formMethod !== 'POST')
            @method($formMethod)
        @endif

        <div class="desktop-grid desktop-grid-three">
            <div>
                <label for="financeiroTipo">Tipo *</label>
                <select id="financeiroTipo" name="tipo" class="form-select" required @disabled($hasMovements)>
                    <option value="receber" @selected($tipo === 'receber')>A receber</option>
                    <option value="pagar" @selected($tipo === 'pagar')>A pagar</option>
                </select>
            </div>

            <div>
                <label for="financeiroCategoria">Categoria *</label>
                <input type="text" id="financeiroCategoria" name="categoria" class="form-control" list="financeiroCategoriaOptions" maxlength="50" value="{{ old('categoria', $lancamento['categoria'] ?? '') }}" placeholder="Ex.: Serviço, Aluguel, Energia" required>
                <datalist id="financeiroCategoriaOptions">
                    @foreach (($categorias ?? []) as $categoriaOption)
                        <option value="{{ $categoriaOption['nome'] ?? '' }}"></option>
                    @endforeach
                </datalist>
                <small class="text-muted d-block mt-1">A categoria define automaticamente o grupo e subgrupo do DRE (máx. 50 caracteres).</small>
            </div>

            <div>
                <label for="financeiroValor">Valor *</label>
                <input type="number" id="financeiroValor" name="valor" class="form-control" step="0.01" min="0.01" value="{{ old('valor', $lancamento['valor'] ?? '') }}" required>
            </div>
        </div>

        <div class="desktop-grid">
            <div>
                <label for="financeiroDescricao">Descrição *</label>
                <input type="text" id="financeiroDescricao" name="descricao" class="form-control" maxlength="255" value="{{ old('descricao', $lancamento['descricao'] ?? '') }}" placeholder="Ex.: OS OS20260001, Aluguel referente a junho..." required>
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-calendar-event"></i>
                <span>DATAS E STATUS</span>
            </div>

            <div class="desktop-grid desktop-grid-three">
                <div>
                    <label for="financeiroDataVencimento">Data de vencimento *</label>
                    <input type="date" id="financeiroDataVencimento" name="data_vencimento" class="form-control" value="{{ old('data_vencimento', $lancamento['data_vencimento'] ?? '') }}" required>
                </div>

                <div>
                    <label for="financeiroStatus">Status</label>
                    <select id="financeiroStatus" name="status" class="form-select">
                        <option value="pendente" @selected($status === 'pendente')>Pendente</option>
                        <option value="parcial" @selected($status === 'parcial') @disabled(! $hasMovements)>Parcial</option>
                        <option value="pago" @selected($status === 'pago')>Pago</option>
                        <option value="cancelado" @selected($status === 'cancelado') @disabled($hasMovements)>Cancelado</option>
                    </select>
                    <small class="text-muted d-block mt-1">Selecionar "Pago" sem baixa registrada gera a baixa total automaticamente.</small>
                </div>

                <div>
                    <label for="financeiroFormaPagamento">Forma de pagamento</label>
                    @php $formaPagamento = old('forma_pagamento', $lancamento['forma_pagamento'] ?? ''); @endphp
                    <select id="financeiroFormaPagamento" name="forma_pagamento" class="form-select">
                        <option value="" @selected($formaPagamento === '')>Não informado</option>
                        <option value="dinheiro" @selected($formaPagamento === 'dinheiro')>Dinheiro</option>
                        <option value="cartao_credito" @selected($formaPagamento === 'cartao_credito')>Cartão de crédito</option>
                        <option value="cartao_debito" @selected($formaPagamento === 'cartao_debito')>Cartão de débito</option>
                        <option value="pix" @selected($formaPagamento === 'pix')>Pix</option>
                        <option value="boleto" @selected($formaPagamento === 'boleto')>Boleto</option>
                        <option value="transferencia" @selected($formaPagamento === 'transferencia')>Transferência</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-link-45deg"></i>
                <span>VÍNCULOS</span>
            </div>

            <div class="form-check form-switch mb-3">
                <input type="hidden" name="avulso" value="{{ $hasMovements && $avulso ? 1 : 0 }}">
                <input
                    type="checkbox"
                    id="financeiroAvulso"
                    name="avulso"
                    class="form-check-input"
                    value="1"
                    @checked($avulso)
                    @disabled($hasMovements)
                >
                <label class="form-check-label fw-semibold" for="financeiroAvulso">Lançamento avulso</label>
                <small class="text-muted d-block">
                    Permite registrar pagamentos ou recebimentos simples sem ordem de serviço. O cliente é opcional.
                </small>
            </div>

            <div class="desktop-grid desktop-grid-three">
                <div>
                    <label for="financeiroOsId">OS vinculada (ID)</label>
                    <input type="number" id="financeiroOsId" name="os_id" class="form-control" min="1" value="{{ old('os_id', $lancamento['os_id'] ?? '') }}">
                    <small id="financeiroOsHelp" class="text-muted d-block mt-1">Lançamentos vinculados à OS não podem ser avulsos.</small>
                </div>

                <div>
                    <label for="financeiroClienteId">Cliente (ID)</label>
                    <input type="number" id="financeiroClienteId" name="cliente_id" class="form-control" min="1" value="{{ old('cliente_id', $lancamento['cliente_id'] ?? '') }}">
                    <small class="text-muted d-block mt-1">Opcional no avulso. Quando informado, o recebimento aparece no histórico financeiro do cliente.</small>
                </div>

                <div>
                    <label for="financeiroFornecedorId">Fornecedor (ID)</label>
                    <input type="number" id="financeiroFornecedorId" name="fornecedor_id" class="form-control" min="1" value="{{ old('fornecedor_id', $lancamento['fornecedor_id'] ?? '') }}">
                </div>
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-card-text"></i>
                <span>OBSERVAÇÕES</span>
            </div>

            <textarea name="observacoes" class="form-control" rows="3">{{ old('observacoes', $lancamento['observacoes'] ?? '') }}</textarea>
        </div>

        <div class="desktop-form-actions">
            <a href="{{ $cancelUrl }}" class="btn btn-outline-light">Cancelar</a>
            <button type="submit" class="btn btn-primary">{{ $submitLabel ?? 'Salvar lançamento' }}</button>
        </div>
    </form>
</section>

<script>
    (() => {
        const avulsoInput = document.getElementById('financeiroAvulso');
        const osInput = document.getElementById('financeiroOsId');
        const osHelp = document.getElementById('financeiroOsHelp');

        if (!avulsoInput || !osInput || !osHelp) {
            return;
        }

        const syncAvulsoState = () => {
            const isAvulso = avulsoInput.checked;

            if (isAvulso) {
                osInput.value = '';
            }

            osInput.disabled = isAvulso;
            osHelp.textContent = isAvulso
                ? 'OS desabilitada: lançamentos avulsos são sempre independentes de ordem de serviço.'
                : 'Lançamentos vinculados à OS não podem ser avulsos.';
        };

        avulsoInput.addEventListener('change', syncAvulsoState);
        osInput.addEventListener('input', () => {
            if (osInput.value.trim() !== '') {
                avulsoInput.checked = false;
                syncAvulsoState();
            }
        });

        syncAvulsoState();
    })();
</script>
