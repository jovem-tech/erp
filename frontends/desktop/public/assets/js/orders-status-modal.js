(function () {
    const config = window.__DESKTOP_STATUS_MODAL || {};
    const statusContextUrlTemplate = String(config.statusContextUrlTemplate || '');
    const statusUpdateUrlTemplate = String(config.statusUpdateUrlTemplate || '');
    const proceduresUrlTemplate = String(config.proceduresUrlTemplate || '');
    const csrfToken = String(config.csrfToken || '');

    const buildContextUrl = (orderId) => statusContextUrlTemplate.replaceAll('__ORDER__', String(orderId));
    const buildUpdateUrl = (orderId) => statusUpdateUrlTemplate.replaceAll('__ORDER__', String(orderId));
    const buildProceduresUrl = (orderId) => proceduresUrlTemplate.replaceAll('__ORDER__', String(orderId));

    const modalEl = document.getElementById('orderStatusModal');
    if (!modalEl) return;

    const form = document.getElementById('orderStatusModalForm');
    const loadingEl = document.getElementById('orderStatusModalLoading');
    const contentEl = document.getElementById('orderStatusModalContent');
    const errorEl = document.getElementById('orderStatusModalError');
    const errorTextEl = document.getElementById('orderStatusModalErrorText');
    const submitBtn = document.getElementById('orderStatusModalSubmit');
    const selectEl = document.getElementById('orderStatusModalSelect');
    const chipGroupsEl = document.getElementById('orderStatusModalChipGroups');
    const proceduresEl = document.getElementById('orderStatusModalProcedures');
    const diagnosisEl = document.getElementById('orderStatusModalDiagnosis');
    const solutionEl = document.getElementById('orderStatusModalSolution');
    const proceduresSaveBtn = document.getElementById('orderStatusModalProceduresSave');
    const novoPrazoWrapper = document.getElementById('orderStatusModalNovoPrazoWrapper');
    const novoPrazoInput = document.getElementById('orderStatusModalNovoPrazo');

    let currentOrderId = null;
    let statusLabelsByCode = {};
    let statusMetaByCode = {};
    let phaseRankByGroup = {};
    let currentStatusCode = '';
    let statusCongelaPrazoAtual = false;

    // Rótulo em PT-BR de cada macrofase (os_status.grupo_macro). 'encerrado'
    // (baixa) nunca chega aqui — filtrado antes de montar a grade (ver
    // sistema-erp-os-fluxo-fechamento). 'cancelado' também não entra no mapa:
    // continua com sua própria seção fixa "Cancelar atendimento".
    const MACRO_PHASE_LABELS = {
        recepcao: 'Recepção',
        diagnostico: 'Diagnóstico',
        orcamento: 'Orçamento',
        execucao: 'Execução',
        qualidade: 'Qualidade',
        interrupcao: 'Em espera',
        concluido: 'Concluído',
        finalizado_sem_reparo: 'Finalizado sem reparo',
    };

    const macroPhaseLabel = (grupoMacro) => {
        const code = String(grupoMacro || '').trim();
        return MACRO_PHASE_LABELS[code] || (code !== '' ? code.charAt(0).toUpperCase() + code.slice(1).replace(/_/g, ' ') : 'Outra fase');
    };

    // Sugestão padrão: hoje + 7 dias, no formato aceito pelo <input type="date">.
    const suggestedNovoPrazo = () => {
        const data = new Date();
        data.setDate(data.getDate() + 7);
        const ano = data.getFullYear();
        const mes = String(data.getMonth() + 1).padStart(2, '0');
        const dia = String(data.getDate()).padStart(2, '0');
        return `${ano}-${mes}-${dia}`;
    };

    // Esconde/desabilita a seção de redefinição de prazo (input disabled não
    // entra no FormData do submit, então não precisa de guarda extra nesse caso).
    const hideNovoPrazoSection = () => {
        novoPrazoWrapper?.classList.add('d-none');
        if (novoPrazoInput) {
            novoPrazoInput.disabled = true;
            novoPrazoInput.required = false;
            novoPrazoInput.value = '';
        }
    };

    const setText = (id, text) => {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
    };

    const showState = (state) => {
        loadingEl?.classList.toggle('d-none', state !== 'loading');
        contentEl?.classList.toggle('d-none', state !== 'content');
        errorEl?.classList.toggle('d-none', state !== 'error');
    };

    const showToast = (message, type = 'success') => {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                toast: true,
                position: 'top-end',
                timer: 4000,
                timerProgressBar: true,
                showConfirmButton: false,
                icon: type,
                title: message,
                customClass: { popup: 'swal-desktop-toast' },
            });
        }
    };

    const statusLabel = (code) => {
        const trimmed = String(code || '').trim();
        if (trimmed === '') return '';
        return statusLabelsByCode[trimmed] || trimmed;
    };

    const formatHistoryDate = (value) => {
        const raw = String(value || '').trim();
        if (raw === '') return '';

        const parsed = new Date(raw);
        if (Number.isNaN(parsed.getTime())) return raw;

        return parsed.toLocaleString('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const buildProcedureHistoryItem = (item) => {
        const data = formatHistoryDate(item.created_at);
        const descricao = String(item.descricao || '');
        const autor = item.usuario?.nome ? String(item.usuario.nome) : '';

        return `
            <div class="os-status-history-item">
                ${data !== '' ? `<div class="os-status-history-item-date mb-1">${data}</div>` : ''}
                <div class="os-status-history-item-obs">${descricao}</div>
                ${autor !== '' ? `<div class="os-status-history-item-author">por ${autor}</div>` : ''}
            </div>
        `;
    };

    const renderProceduresHistory = (procedimentos) => {
        const historyEl = document.getElementById('orderStatusModalProceduresHistory');
        if (!historyEl) return;

        const items = Array.isArray(procedimentos) ? procedimentos : [];
        historyEl.innerHTML = items.length > 0
            ? items.map(buildProcedureHistoryItem).join('')
            : '<p class="text-muted small mb-0">Nenhum procedimento registrado ainda.</p>';
    };

    const buildHistoryItem = (item) => {
        const anterior = statusLabel(item.status_anterior);
        const novo = statusLabel(item.status_novo);
        const transicao = anterior && novo ? `${anterior} → ${novo}` : (novo || anterior || 'Movimentação');
        const data = formatHistoryDate(item.created_at);
        const obs = String(item.observacao || '');
        const autor = item.usuario?.nome ? String(item.usuario.nome) : '';

        return `
            <div class="os-status-history-item">
                <div class="os-status-history-item-header">
                    <span class="os-status-history-item-badge">${transicao}</span>
                    ${data !== '' ? `<span class="os-status-history-item-date">${data}</span>` : ''}
                </div>
                ${obs !== '' ? `<div class="os-status-history-item-obs">${obs}</div>` : ''}
                ${autor !== '' ? `<div class="os-status-history-item-author">por ${autor}</div>` : ''}
            </div>
        `;
    };

    // Uma etapa vira chip clicável. Antes o grupo (avançar/retornar/cancelar)
    // vinha só das transições cadastradas em os_status_transicoes a partir do
    // status atual; agora a grade mostra TODOS os status ativos (exceto os de
    // baixa) organizados por macrofase, porque na prática o técnico avança
    // várias etapas do atendimento antes de mexer no sistema — uma máquina de
    // estados rígida não reflete esse fluxo. Encerramentos (equipamento
    // entregue/devolvido/descartado) nunca aparecem aqui, só na tela de baixa
    // (grupo_macro='encerrado', ver skill sistema-erp-os-fluxo-fechamento).
    const buildChip = (etapa, modifierClass, { isCurrent = false, isSuggested = false } = {}) => {
        const code = String(etapa.codigo || '').trim();
        if (!code) return '';
        const nome = String(etapa.nome || code);
        const icone = String(etapa.icone || '').trim();
        const cor = String(etapa.cor || '').trim();
        const style = cor ? ` style="--status-color: ${cor}"` : '';
        const classes = [
            'os-status-chip',
            modifierClass,
            isCurrent ? 'is-current' : '',
            isSuggested ? 'is-suggested' : '',
        ].filter(Boolean).join(' ');

        return `
            <button type="button" class="${classes}" data-status-code="${code}"${style} title="${isCurrent ? 'Etapa atual' : (isSuggested ? 'Próxima etapa sugerida pelo fluxo padrão' : '')}">
                ${icone ? `<i class="bi ${icone}"></i>` : ''}
                <span>${nome}</span>
                ${isCurrent ? '<i class="bi bi-record-fill os-status-chip-current-dot"></i>' : ''}
            </button>
        `;
    };

    // Grade principal: todos os status ativos exceto baixa (grupo_macro
    // 'encerrado') e cancelamento (grupo_macro 'cancelado', que mantém sua
    // própria seção fixa "Cancelar atendimento" abaixo). Como o backend já
    // devolve status_disponiveis ordenado por ordem_fluxo, agrupar mantendo a
    // ordem de chegada já entrega as macrofases na sequência certa do fluxo,
    // sem precisar hardcodar a ordem aqui.
    const renderStatusGrid = (statusCatalog, proximasEtapas, currentCode) => {
        if (!chipGroupsEl) return;

        const suggested = new Set(
            (Array.isArray(proximasEtapas) ? proximasEtapas : [])
                .map((etapa) => String(etapa.codigo || '').trim())
                .filter(Boolean)
        );

        const groups = new Map();
        let cancelStatus = null;
        phaseRankByGroup = {};

        statusCatalog.forEach((etapa) => {
            const grupoMacro = String(etapa.grupo_macro || '').trim();
            if (grupoMacro === 'encerrado') return; // baixa: só pela tela de Encerramento.

            if (grupoMacro === 'cancelado') {
                cancelStatus = cancelStatus || etapa;
                return;
            }

            if (!groups.has(grupoMacro)) {
                phaseRankByGroup[grupoMacro] = groups.size;
                groups.set(grupoMacro, []);
            }
            groups.get(grupoMacro).push(etapa);
        });

        if (groups.size === 0 && !cancelStatus) {
            chipGroupsEl.innerHTML = '<p class="os-status-chip-empty">Nenhum status disponível para esta OS.</p>';
            return;
        }

        const phaseSections = Array.from(groups.entries()).map(([grupoMacro, etapas]) => {
            const chips = etapas.map((etapa) => buildChip(etapa, 'os-status-chip--fase', {
                isCurrent: String(etapa.codigo || '').trim() === currentCode,
                isSuggested: suggested.has(String(etapa.codigo || '').trim()),
            })).join('');

            return `
                <div class="os-status-phase-group">
                    <div class="os-status-chip-group-label">${macroPhaseLabel(grupoMacro)}</div>
                    <div class="os-status-chip-row">${chips}</div>
                </div>
            `;
        }).join('');

        const cancelSection = cancelStatus ? `
            <div class="os-status-phase-group os-status-phase-group--cancel">
                <div class="os-status-chip-group-label">Cancelar atendimento</div>
                <div class="os-status-chip-row">${buildChip(cancelStatus, 'os-status-chip--cancelar', {
                    isCurrent: String(cancelStatus.codigo || '').trim() === currentCode,
                })}</div>
            </div>
        ` : '';

        chipGroupsEl.innerHTML = `
            <div class="os-status-phase-grid">${phaseSections}</div>
            ${cancelSection}
        `;
    };

    // Clique no chip: só seleciona (preenche o <select> escondido e dispara
    // change), quem efetivamente aplica é o "Salvar status" no rodapé — mesmo
    // padrão que os antigos botões de ação rápida já usavam.
    chipGroupsEl?.addEventListener('click', (event) => {
        const chip = event.target.closest('.os-status-chip');
        if (!chip || !chipGroupsEl.contains(chip)) return;

        const code = chip.dataset.statusCode || '';
        if (!code || !selectEl) return;

        chipGroupsEl.querySelectorAll('.os-status-chip.is-selected').forEach((el) => {
            el.classList.remove('is-selected');
        });
        chip.classList.add('is-selected');

        selectEl.value = code;
        selectEl.dispatchEvent(new Event('change'));
    });

    const populateModal = (data) => {
        const numeroOs = String(data.numero_os || '');
        setText('orderStatusModalNumero', numeroOs);

        statusCongelaPrazoAtual = Boolean(data.status_congela_prazo);
        hideNovoPrazoSection();

        // Catálogo de status (código → nome/macrofase), usado pra traduzir o
        // histórico, montar a grade por macrofase e checar salto de fase
        // antes de salvar.
        statusLabelsByCode = {};
        statusMetaByCode = {};
        const statusCatalog = Array.isArray(data.status_disponiveis) ? data.status_disponiveis : [];
        statusCatalog.forEach((status) => {
            const code = String(status?.codigo || '').trim();
            if (code !== '') {
                statusLabelsByCode[code] = String(status?.nome || code);
                statusMetaByCode[code] = { grupo_macro: String(status?.grupo_macro || '').trim() };
            }
        });

        currentStatusCode = String(data.status || '').trim();

        // Cliente
        setText('orderStatusModalClientName', data.cliente_nome || '-');
        setText('orderStatusModalClientPhone', data.cliente_telefone ? `Telefone: ${data.cliente_telefone}` : 'Telefone: -');
        setText('orderStatusModalClientEmail', data.cliente_email ? `E-mail: ${data.cliente_email}` : 'E-mail: -');

        // Equipamento
        setText('orderStatusModalEquipName', data.equipamento_nome || '-');
        setText('orderStatusModalEquipType', data.equipamento_tipo_nome ? `Tipo: ${data.equipamento_tipo_nome}` : 'Tipo: -');
        setText('orderStatusModalEquipSerial', data.equipamento_numero_serie ? `Nº de série: ${data.equipamento_numero_serie}` : 'Nº de série: -');

        // Diagnóstico e solução (salvos junto com o status); o campo de
        // procedimentos sempre começa vazio, pois cada envio cria uma nova
        // entrada no histórico em vez de sobrescrever um valor único.
        if (proceduresEl) proceduresEl.value = '';
        if (diagnosisEl) diagnosisEl.value = String(data.diagnostico_tecnico || '');
        if (solutionEl) solutionEl.value = String(data.solucao_aplicada || '');
        renderProceduresHistory(data.procedimentos_historico);

        // Status atual
        const statusAtual = String(data.status_nome || '');
        setText('orderStatusModalCurrentHint', `Status atual da OS: ${statusAtual}.`);

        // Todos os status ativos, exceto os de baixa (grupo_macro='encerrado'
        // — só a tela de Encerramento pode aplicá-los). Sugestões do fluxo
        // padrão continuam vindo de proximas_etapas, só que agora só como
        // destaque visual na grade, não como filtro do que pode ser escolhido.
        const etapasDisponiveis = statusCatalog.filter(
            (etapa) => String(etapa?.grupo_macro || '').trim() !== 'encerrado'
        );
        const etapasSugeridas = Array.isArray(data.proximas_etapas) ? data.proximas_etapas : [];

        // Preenche o select escondido (fonte de verdade do form; os chips só
        // escrevem nele) e a grade de chips por macrofase.
        if (selectEl) {
            selectEl.innerHTML = '<option value="">Selecione um status</option>';
            etapasDisponiveis.forEach((etapa) => {
                const code = String(etapa.codigo || '').trim();
                if (!code) return;
                const opt = document.createElement('option');
                opt.value = code;
                opt.textContent = String(etapa.nome || code);
                opt.dataset.congelaPrazo = etapa.congela_prazo ? '1' : '0';
                opt.dataset.grupoMacro = String(etapa.grupo_macro || '').trim();
                selectEl.appendChild(opt);
            });
            selectEl.value = '';
        }

        renderStatusGrid(etapasDisponiveis, etapasSugeridas, currentStatusCode);

        setText('orderStatusModalTargetHint', 'Selecione um fluxo para continuar.');
        // "Salvar status" fica sempre liberado: também é usado para salvar
        // diagnóstico/solução sem necessariamente trocar o status da OS.
        if (submitBtn) submitBtn.disabled = false;

        // Histórico
        const historyEl = document.getElementById('orderStatusModalHistory');
        if (historyEl) {
            const historico = Array.isArray(data.historico) ? data.historico : [];
            if (historico.length > 0) {
                historyEl.innerHTML = historico.map(buildHistoryItem).join('');
            } else {
                historyEl.innerHTML = '<p class="text-muted small mb-0">Sem histórico recente.</p>';
            }
        }

        showState('content');
    };

    // Abre o modal e carrega os dados da OS via AJAX
    modalEl.addEventListener('show.bs.modal', async (event) => {
        const trigger = event.relatedTarget;
        currentOrderId = trigger?.dataset.orderId || '';

        if (!currentOrderId) {
            showState('error');
            if (errorTextEl) errorTextEl.textContent = 'ID da OS não informado.';
            return;
        }

        showState('loading');
        if (form) form.reset();
        if (submitBtn) submitBtn.disabled = true;

        try {
            const res = await fetch(buildContextUrl(currentOrderId), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!res.ok) {
                throw new Error('Erro ao carregar dados da OS.');
            }

            const data = await res.json();

            if (data.error) {
                throw new Error(data.error);
            }

            populateModal(data);
        } catch (err) {
            showState('error');
            if (errorTextEl) errorTextEl.textContent = err.message || 'Não foi possível carregar os dados da OS.';
        }
    });

    // Limpa o modal ao fechar
    modalEl.addEventListener('hidden.bs.modal', () => {
        currentOrderId = null;
        if (form) form.reset();
        showState('loading');
        if (submitBtn) submitBtn.disabled = true;
        setText('orderStatusModalNumero', '-');
        hideNovoPrazoSection();

        const statusTabBtn = document.getElementById('orderStatusModalTabStatusBtn');
        if (statusTabBtn && typeof bootstrap !== 'undefined') {
            bootstrap.Tab.getOrCreateInstance(statusTabBtn).show();
        }
    });

    // Ao mudar o select (o botão "Salvar status" já fica sempre liberado).
    // O select fica escondido (data-select2="false", ver desktop.js) e só é
    // alterado programaticamente pelo clique nos chips — um addEventListener
    // nativo já basta, não passa mais pela UI do Select2.
    const handleStatusSelectChange = () => {
        const selectedName = selectEl.selectedOptions[0]?.text || '';
        setText(
            'orderStatusModalTargetHint',
            selectEl.value ? `Fluxo selecionado: ${selectedName}.` : 'Selecione um fluxo para continuar.'
        );

        // A OS estava com o prazo congelado e o destino escolhido não congela
        // mais — é uma reabertura manual, então pede confirmação do novo prazo
        // (ver OrderStatus::DEADLINE_FREEZE_CODES no backend).
        const destinoCongelaPrazo = selectEl.selectedOptions[0]?.dataset.congelaPrazo === '1';
        const precisaRedefinirPrazo = statusCongelaPrazoAtual && selectEl.value !== '' && !destinoCongelaPrazo;

        if (precisaRedefinirPrazo) {
            novoPrazoWrapper?.classList.remove('d-none');
            if (novoPrazoInput) {
                novoPrazoInput.disabled = false;
                novoPrazoInput.required = true;
                if (!novoPrazoInput.value) novoPrazoInput.value = suggestedNovoPrazo();
            }
        } else {
            hideNovoPrazoSection();
        }
    };

    selectEl?.addEventListener('change', handleStatusSelectChange);

    // Salvar um novo procedimento executado (aba "Procedimentos"): cada clique
    // cria uma entrada nova no histórico, com data e técnico responsável.
    proceduresSaveBtn?.addEventListener('click', async () => {
        if (!currentOrderId) return;

        const descricao = proceduresEl?.value.trim() || '';
        if (descricao === '') {
            showToast('Descreva o procedimento executado antes de salvar.', 'error');
            return;
        }

        const originalHtml = proceduresSaveBtn.innerHTML;
        proceduresSaveBtn.disabled = true;
        proceduresSaveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';

        try {
            const res = await fetch(buildProceduresUrl(currentOrderId), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ descricao }),
            });

            const result = await res.json();

            if (!res.ok || result.error) {
                throw new Error(result.error || result.message || 'Erro ao salvar o procedimento.');
            }

            if (proceduresEl) proceduresEl.value = '';
            renderProceduresHistory(result.procedimentos_historico);
            showToast(result.message || 'Procedimento registrado com sucesso.', 'success');
        } catch (err) {
            showToast(err.message || 'Não foi possível salvar o procedimento. Tente novamente.', 'error');
        } finally {
            proceduresSaveBtn.disabled = false;
            proceduresSaveBtn.innerHTML = originalHtml;
        }
    });

    // Aviso (não bloqueia) quando o destino escolhido fica a mais de uma
    // macrofase de distância da fase atual — ex.: Recepção -> Qualidade sem
    // passar por Diagnóstico/Orçamento/Execução no meio. Decisão de produto
    // (2026-08-09): o backend passou a aceitar qualquer status não-baixa
    // (ver OrderWorkflowService::updateStatus()), então esta confirmação é a
    // única rede de segurança contra clique errado ao pular fases do fluxo.
    const confirmPhaseSkipIfNeeded = async () => {
        const targetGroup = selectEl?.selectedOptions[0]?.dataset.grupoMacro || '';
        const currentGroup = statusMetaByCode[currentStatusCode]?.grupo_macro || '';
        const currentRank = phaseRankByGroup[currentGroup];
        const targetRank = phaseRankByGroup[targetGroup];

        const ehSaltoDeFase = targetGroup !== '' && targetGroup !== currentGroup
            && Number.isFinite(currentRank) && Number.isFinite(targetRank)
            && Math.abs(targetRank - currentRank) > 1;

        if (!ehSaltoDeFase || typeof Swal === 'undefined') return true;

        const result = await Swal.fire({
            icon: 'warning',
            title: 'Pulando etapas do fluxo',
            html: `Isso muda a OS de <b>${macroPhaseLabel(currentGroup)}</b> direto para <b>${macroPhaseLabel(targetGroup)}</b>, sem passar pelas fases intermediárias. Confirma mesmo assim?`,
            showCancelButton: true,
            confirmButtonText: 'Confirmar mudança',
            cancelButtonText: 'Revisar seleção',
            reverseButtons: true,
        });

        return Boolean(result.isConfirmed);
    };

    // Submissão via AJAX
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (!currentOrderId) return;

        const novoPrazoVisivel = novoPrazoWrapper && !novoPrazoWrapper.classList.contains('d-none');
        if (novoPrazoVisivel && !novoPrazoInput?.value) {
            showToast('Informe o novo prazo de entrega para reabrir esta OS.', 'error');
            return;
        }

        if (!(await confirmPhaseSkipIfNeeded())) {
            return;
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';
        }

        const formData = new FormData(form);

        try {
            const res = await fetch(buildUpdateUrl(currentOrderId), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: formData,
            });

            const result = await res.json();

            if (!res.ok || result.error) {
                throw new Error(result.error || result.message || 'Erro ao atualizar o status.');
            }

            // Fecha o modal
            const bsModal = bootstrap.Modal.getInstance(modalEl);
            bsModal?.hide();

            showToast(result.message || `Status alterado para: ${result.status_nome || 'novo status'}.`, 'success');

            // Recarrega a página para refletir o novo status sem redirecionar
            setTimeout(() => location.reload(), 1000);
        } catch (err) {
            showToast(err.message || 'Não foi possível alterar o status. Tente novamente.', 'error');

            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Salvar status';
            }
        }
    });
})();
