(function () {
    const config = window.__DESKTOP_STATUS_MODAL || {};
    const statusContextUrlTemplate = String(config.statusContextUrlTemplate || '');
    const statusUpdateUrlTemplate = String(config.statusUpdateUrlTemplate || '');
    const proceduresUrlTemplate = String(config.proceduresUrlTemplate || '');
    const mapDataUrlTemplate = String(config.mapDataUrlTemplate || '');
    const closureUrlTemplate = String(config.closureUrlTemplate || '');
    const csrfToken = String(config.csrfToken || '');

    const buildContextUrl = (orderId) => statusContextUrlTemplate.replaceAll('__ORDER__', String(orderId));
    const buildUpdateUrl = (orderId) => statusUpdateUrlTemplate.replaceAll('__ORDER__', String(orderId));
    const buildProceduresUrl = (orderId) => proceduresUrlTemplate.replaceAll('__ORDER__', String(orderId));
    const buildMapDataUrl = (orderId) => mapDataUrlTemplate.replaceAll('__ORDER__', String(orderId));
    const buildClosureUrl = (orderId) => closureUrlTemplate.replaceAll('__ORDER__', String(orderId));

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
    const mapFrameEl = document.getElementById('orderStatusModalMapFrame');
    const mapErrorEl = document.getElementById('orderStatusModalMapError');
    const mapTabBtn = document.getElementById('orderStatusModalTabMapBtn');
    const notifyEl = document.getElementById('orderStatusModalNotify');
    const notifyPreviewEl = document.getElementById('orderStatusModalNotifyPreview');
    const notifyPreviewTextEl = document.getElementById('orderStatusModalNotifyPreviewText');
    const notifyEditBtn = document.getElementById('orderStatusModalNotifyEdit');
    const notifyMessageEl = document.getElementById('orderStatusModalNotifyMessage');
    const observacaoEl = document.getElementById('orderStatusModalObservacao');

    let currentOrderId = null;
    let statusLabelsByCode = {};
    let statusMetaByCode = {};
    let phaseRankByGroup = {};
    let currentStatusCode = '';
    let statusCongelaPrazoAtual = false;
    let mapWidget = null;
    let pendingMapConfig = null;
    let numeroOsAtual = '';
    let mensagemClienteTemplate = '';
    // true depois que o operador edita o texto à mão: a partir daí a mensagem
    // não é mais regenerada sozinha ao trocar status/observação, para não
    // apagar o que ele escreveu.
    let mensagemClienteEditada = false;

    // Macrofases = o fluxo de andamento da OS, na ordem definida pelo usuário
    // (2026-08-10): Recepção > Diagnóstico > Orçamento > Em espera > Execução
    // > Qualidade > Concluído; depois as SAÍDAS do fluxo (sem reparo,
    // cancelado). Essa ordem é declarada aqui de propósito e NÃO é derivada de
    // os_status.ordem_fluxo: no banco 'interrupcao' (Em espera) tem ordem
    // 120-140, ou seja, cairia depois de Execução/Qualidade — o usuário quer
    // "Em espera" logo após Orçamento, porque é onde a OS costuma parar
    // esperando peça/pagamento antes de entrar em execução.
    //
    // 'encerrado' (baixa) nunca aparece aqui — filtrado antes (regra central
    // do skill sistema-erp-os-fluxo-fechamento: só pela tela de baixa).
    const MACRO_PHASES = [
        { code: 'recepcao', label: 'Recepção' },
        { code: 'diagnostico', label: 'Diagnóstico' },
        { code: 'orcamento', label: 'Orçamento' },
        { code: 'interrupcao', label: 'Em espera' },
        { code: 'execucao', label: 'Execução' },
        { code: 'qualidade', label: 'Qualidade' },
        { code: 'concluido', label: 'Concluído' },
    ];

    // Saídas do fluxo: a OS termina sem seguir para Concluído. Ficam num bloco
    // separado, depois de um divisor — não são etapas de progresso.
    const EXIT_PHASES = [
        { code: 'finalizado_sem_reparo', label: 'Sem reparo' },
        { code: 'cancelado', label: 'Cancelado' },
    ];

    const PHASE_LABELS = [...MACRO_PHASES, ...EXIT_PHASES].reduce((acc, phase) => {
        acc[phase.code] = phase.label;
        return acc;
    }, {});

    const macroPhaseLabel = (grupoMacro) => {
        const code = String(grupoMacro || '').trim();
        return PHASE_LABELS[code] || (code !== '' ? code.charAt(0).toUpperCase() + code.slice(1).replace(/_/g, ' ') : 'Outra fase');
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

    // Uma etapa vira um "card" clicável do fluxograma. A grade mostra TODOS os
    // status ativos (exceto os de baixa) porque na prática o técnico avança
    // várias etapas do atendimento antes de mexer no sistema — uma máquina de
    // estados rígida não reflete esse fluxo. Encerramentos (equipamento
    // entregue/devolvido/descartado) nunca aparecem aqui, só na tela de baixa
    // (grupo_macro='encerrado', ver skill sistema-erp-os-fluxo-fechamento).
    const buildStep = (etapa, { isCurrent = false, isSuggested = false } = {}) => {
        const code = String(etapa.codigo || '').trim();
        if (!code) return '';
        const nome = String(etapa.nome || code);
        const icone = String(etapa.icone || '').trim();
        const classes = [
            'os-flow-step',
            isCurrent ? 'is-current' : '',
            isSuggested ? 'is-suggested' : '',
        ].filter(Boolean).join(' ');

        const title = isCurrent
            ? 'Etapa atual da OS'
            : (isSuggested ? 'Próxima etapa sugerida pelo fluxo padrão' : '');

        return `
            <button type="button" class="${classes}" data-status-code="${code}" title="${title}">
                ${icone ? `<i class="bi ${icone}"></i>` : ''}
                <span class="os-flow-step-label">${nome}</span>
                ${isCurrent ? '<span class="os-flow-step-tag" data-role="tag">atual</span>' : ''}
            </button>
        `;
    };

    // Monta uma linha do fluxograma: faixa da macrofase à esquerda + as etapas
    // daquela fase fluindo para a direita, separadas por setas. Dentro da fase
    // a ordem vem de os_status.ordem_fluxo (ordem em que status_disponiveis já
    // chega do backend) — só a ordem ENTRE fases é declarada em MACRO_PHASES.
    const buildPhaseRow = (phase, etapas, currentCode, suggested) => {
        if (etapas.length === 0) return '';

        const steps = etapas.map((etapa, index) => {
            const step = buildStep(etapa, {
                isCurrent: String(etapa.codigo || '').trim() === currentCode,
                isSuggested: suggested.has(String(etapa.codigo || '').trim()),
            });
            const arrow = index < etapas.length - 1
                ? '<span class="os-flow-arrow" aria-hidden="true"></span>'
                : '';
            return step + arrow;
        }).join('');

        return `
            <div class="os-flow-row" data-phase="${phase.code}">
                <div class="os-flow-phase"><span>${phase.label}</span></div>
                <div class="os-flow-steps">${steps}</div>
            </div>
        `;
    };

    // Fluxograma principal: uma linha por macrofase, na ordem do fluxo real da
    // OS, seguida de um bloco separado com as saídas do fluxo.
    const renderStatusGrid = (statusCatalog, proximasEtapas, currentCode) => {
        if (!chipGroupsEl) return;

        const suggested = new Set(
            (Array.isArray(proximasEtapas) ? proximasEtapas : [])
                .map((etapa) => String(etapa.codigo || '').trim())
                .filter(Boolean)
        );

        // Agrupa por grupo_macro preservando a ordem de chegada (= ordem_fluxo).
        const byPhase = new Map();
        statusCatalog.forEach((etapa) => {
            const grupoMacro = String(etapa.grupo_macro || '').trim();
            if (grupoMacro === 'encerrado') return; // baixa: só pela tela de Encerramento.
            if (!byPhase.has(grupoMacro)) byPhase.set(grupoMacro, []);
            byPhase.get(grupoMacro).push(etapa);
        });

        // Rank usado pelo aviso de "pulo de fase" no submit: posição da fase no
        // fluxo declarado. Saídas ficam depois de todas as fases de progresso.
        phaseRankByGroup = {};
        [...MACRO_PHASES, ...EXIT_PHASES].forEach((phase, index) => {
            phaseRankByGroup[phase.code] = index;
        });

        const flowRows = MACRO_PHASES
            .map((phase) => buildPhaseRow(phase, byPhase.get(phase.code) || [], currentCode, suggested))
            .join('');

        const exitRows = EXIT_PHASES
            .map((phase) => buildPhaseRow(phase, byPhase.get(phase.code) || [], currentCode, suggested))
            .join('');

        // Fases fora do fluxo declarado (status novo no catálogo com um
        // grupo_macro ainda não mapeado): não somem da tela — vão para o fim,
        // com o rótulo derivado do próprio código.
        const knownPhases = new Set([...MACRO_PHASES, ...EXIT_PHASES].map((p) => p.code));
        const extraRows = Array.from(byPhase.keys())
            .filter((code) => !knownPhases.has(code))
            .map((code) => buildPhaseRow(
                { code, label: macroPhaseLabel(code) },
                byPhase.get(code) || [],
                currentCode,
                suggested
            ))
            .join('');

        if (flowRows === '' && exitRows === '' && extraRows === '') {
            chipGroupsEl.innerHTML = '<p class="os-status-chip-empty">Nenhum status disponível para esta OS.</p>';
            return;
        }

        chipGroupsEl.innerHTML = `
            <div class="os-flow">${flowRows}${extraRows}</div>
            ${exitRows !== '' ? `
                <div class="os-flow-exit">
                    <div class="os-flow-exit-title">Saída do fluxo</div>
                    <div class="os-flow">${exitRows}</div>
                </div>
            ` : ''}
        `;
    };

    // Clique no chip: só seleciona (preenche o <select> escondido e dispara
    // change), quem efetivamente aplica é o "Salvar status" no rodapé — mesmo
    // padrão que os antigos botões de ação rápida já usavam.
    chipGroupsEl?.addEventListener('click', (event) => {
        const chip = event.target.closest('.os-flow-step');
        if (!chip || !chipGroupsEl.contains(chip)) return;

        const code = chip.dataset.statusCode || '';
        if (!code || !selectEl) return;

        // Limpa a seleção anterior e devolve a etiqueta "atual" ao card da
        // etapa em que a OS realmente está (ele pode ter sido o selecionado).
        chipGroupsEl.querySelectorAll('.os-flow-step.is-selected').forEach((el) => {
            el.classList.remove('is-selected');
            const tag = el.querySelector('[data-role="tag"]');
            if (tag) {
                if (el.classList.contains('is-current')) {
                    tag.textContent = 'atual';
                } else {
                    tag.remove();
                }
            }
        });

        chip.classList.add('is-selected');

        // Etiqueta do card escolhido: "selecionado" (ou "atual • selecionado"
        // quando o operador reescolhe a própria etapa atual).
        let tag = chip.querySelector('[data-role="tag"]');
        if (!tag) {
            tag = document.createElement('span');
            tag.className = 'os-flow-step-tag';
            tag.dataset.role = 'tag';
            chip.appendChild(tag);
        }
        tag.textContent = chip.classList.contains('is-current') ? 'atual • selecionado' : 'selecionado';

        selectEl.value = code;
        selectEl.dispatchEvent(new Event('change'));
    });

    // ------------------------------------------------------------------
    // Mensagem ao cliente ("Notificar o cliente sobre esta mudança")
    // ------------------------------------------------------------------

    // Monta a mensagem padrão a partir do template que veio do backend
    // (OrderWorkflowService::CLIENT_STATUS_MESSAGE_TEMPLATE, repassado pelo
    // contexto do modal) — a frase NÃO é escrita aqui, para não divergir do
    // que o backend enviaria caso o campo chegue vazio.
    const buildDefaultClientMessage = () => {
        const statusNome = selectEl?.selectedOptions[0]?.text || statusLabelsByCode[currentStatusCode] || '';
        const template = mensagemClienteTemplate
            || 'Olá! O status da sua OS {numero_os} foi atualizado para: "{status}".';

        let texto = template
            .replaceAll('{numero_os}', numeroOsAtual)
            .replaceAll('{status}', statusNome);

        const observacao = (observacaoEl?.value || '').trim();
        if (observacao !== '') texto += ` ${observacao}`;

        return texto;
    };

    const renderNotifyPreview = () => {
        const ligado = Boolean(notifyEl?.checked);
        notifyPreviewEl?.classList.toggle('d-none', !ligado);
        if (notifyPreviewTextEl) {
            notifyPreviewTextEl.textContent = notifyMessageEl?.value || '';
        }
    };

    // Regera a mensagem quando o operador ainda não a editou à mão.
    const refreshClientMessage = () => {
        if (!notifyEl?.checked || mensagemClienteEditada) {
            renderNotifyPreview();
            return;
        }
        if (notifyMessageEl) notifyMessageEl.value = buildDefaultClientMessage();
        renderNotifyPreview();
    };

    // Modal de revisão: mostra a mensagem que será enviada e deixa editar.
    // Cancelar aqui desliga o switch — evita notificar sem o operador ter
    // conferido o texto.
    const openClientMessageDialog = async ({ desligarSeCancelar = false } = {}) => {
        if (typeof Swal === 'undefined') return true;

        const atual = notifyMessageEl?.value || buildDefaultClientMessage();

        const result = await Swal.fire({
            title: 'Mensagem ao cliente',
            html: `
                <p class="small text-muted mb-2 text-start">
                    Esta mensagem será enviada ao cliente por WhatsApp ao salvar o status.
                    Ajuste o texto se quiser adaptá-lo à situação.
                </p>
                <textarea id="orderStatusModalClientMessageInput" class="form-control" rows="5">${atual.replace(/</g, '&lt;')}</textarea>
                <p class="small text-muted mt-2 mb-0 text-start">
                    O texto enviado fica registrado no histórico da OS.
                </p>
            `,
            width: 620,
            // Sem `target` o SweetAlert2 é anexado ao <body>, FORA do modal do
            // Bootstrap — e o focus trap do modal devolve o foco para si a cada
            // focusin, impedindo digitar no textarea. Ancorar o diálogo dentro
            // do próprio modal resolve (verificado com Bootstrap real: sem
            // target o activeElement volta para o .modal).
            target: modalEl,
            showCancelButton: true,
            confirmButtonText: 'Usar esta mensagem',
            cancelButtonText: desligarSeCancelar ? 'Não notificar' : 'Cancelar',
            reverseButtons: true,
            preConfirm: () => {
                const texto = (document.getElementById('orderStatusModalClientMessageInput')?.value || '').trim();
                if (texto === '') {
                    Swal.showValidationMessage('A mensagem não pode ficar vazia.');
                    return false;
                }
                if (texto.length > 2000) {
                    Swal.showValidationMessage('A mensagem deve ter no máximo 2000 caracteres.');
                    return false;
                }
                return texto;
            },
        });

        if (!result.isConfirmed) {
            if (desligarSeCancelar && notifyEl) {
                notifyEl.checked = false;
                renderNotifyPreview();
            }
            return false;
        }

        if (notifyMessageEl) notifyMessageEl.value = result.value;
        mensagemClienteEditada = result.value !== buildDefaultClientMessage();
        renderNotifyPreview();
        return true;
    };

    // Ligar o switch abre o modal na hora, para o operador ver/ajustar o texto.
    notifyEl?.addEventListener('change', () => {
        if (notifyEl.checked) {
            if (notifyMessageEl && !mensagemClienteEditada) {
                notifyMessageEl.value = buildDefaultClientMessage();
            }
            renderNotifyPreview();
            openClientMessageDialog({ desligarSeCancelar: true });
            return;
        }
        renderNotifyPreview();
    });

    notifyEditBtn?.addEventListener('click', () => openClientMessageDialog());
    observacaoEl?.addEventListener('input', refreshClientMessage);

    const populateModal = (data, mapData) => {
        const numeroOs = String(data.numero_os || '');
        setText('orderStatusModalNumero', numeroOs);

        statusCongelaPrazoAtual = Boolean(data.status_congela_prazo);
        hideNovoPrazoSection();

        // Mensagem ao cliente: template vem do backend; estado começa zerado a
        // cada abertura (switch desligado, sem texto, sem edição manual).
        numeroOsAtual = numeroOs;
        mensagemClienteTemplate = String(data.mensagem_cliente_template || '');
        mensagemClienteEditada = false;
        if (notifyEl) notifyEl.checked = false;
        if (notifyMessageEl) notifyMessageEl.value = '';
        renderNotifyPreview();

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
        setText('orderStatusModalMapCurrentHint', `Status atual da OS: ${statusAtual}.`);

        // Config da aba "Mapa de status" — só é aplicada (create/refresh do
        // widget) quando a aba é exibida (shown.bs.tab), nunca aqui: o
        // container ainda está escondido (display:none) neste ponto, e
        // medir/decorar um SVG escondido dá dimensões erradas (ou falha em
        // alguns navegadores). Ver window.DesktopOsMap.create() em
        // orders-map.js.
        pendingMapConfig = mapData && mapData.order ? {
            statusAtual: currentStatusCode,
            isEncerrada: Boolean(mapData.order.is_encerrada),
            canEditStatus: Boolean(mapData.canEditStatus),
            canClose: Boolean(mapData.canEditStatus) && !Boolean(mapData.order.is_encerrada),
            statusCongelaPrazo: statusCongelaPrazoAtual,
            proximasEtapas: Array.isArray(data.proximas_etapas) ? data.proximas_etapas : [],
            statusDisponiveis: statusCatalog,
            path: Array.isArray(mapData.path) ? mapData.path : [],
            statusUpdateUrl: buildUpdateUrl(currentOrderId),
            mapDataUrl: buildMapDataUrl(currentOrderId),
            closureUrl: buildClosureUrl(currentOrderId),
            csrfToken,
            // Abre com o fluxo inteiro visível — o ponto da aba é localizar a
            // OS dentro do mapa completo (ver applyInitialView em orders-map.js).
            initialView: 'fit',
            // O mapa nunca fica "aberto pra continuar clicando" dentro do
            // modal (ao contrário da página cheia /os/{id}/mapa): mover a OS
            // aqui fecha o modal e recarrega a página, igual ao fluxo dos
            // chips + "Salvar status" — mesmo padrão de conclusão em todo o
            // resto do modal, em vez de um segundo jeito independente de
            // salvar. showToast do próprio widget já avisa o sucesso.
            onMoved: () => {
                const bsModal = bootstrap.Modal.getInstance(modalEl);
                bsModal?.hide();
                setTimeout(() => location.reload(), 1000);
            },
        } : null;

        // O frame do mapa (SVG + toolbar) nunca é recriado — só create() uma
        // vez e refresh() depois disso — pra não reanexar os listeners
        // globais de fullscreen/Esc a cada abertura do modal (vazamento de
        // listener). Erro de carregamento é um elemento irmão, alternado por
        // classe, não uma troca de innerHTML do frame.
        mapErrorEl?.classList.toggle('d-none', pendingMapConfig !== null);
        mapFrameEl?.classList.toggle('d-none', pendingMapConfig === null);

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
            // Mapa de status busca em paralelo com o contexto do form, mas
            // falha isolada: se o mapa não carregar, a aba "Status" continua
            // funcionando normalmente (só a aba "Mapa de status" mostra erro
            // — ver populateModal). Por isso o catch fica só no fetch do
            // mapa, não propaga pro try externo.
            const [contextRes, mapRes] = await Promise.all([
                fetch(buildContextUrl(currentOrderId), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                }),
                buildMapDataUrl(currentOrderId)
                    ? fetch(buildMapDataUrl(currentOrderId), {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    }).catch(() => null)
                    : Promise.resolve(null),
            ]);

            if (!contextRes.ok) {
                throw new Error('Erro ao carregar dados da OS.');
            }

            const data = await contextRes.json();

            if (data.error) {
                throw new Error(data.error);
            }

            const mapData = mapRes && mapRes.ok ? await mapRes.json().catch(() => null) : null;

            populateModal(data, mapData);
        } catch (err) {
            showState('error');
            if (errorTextEl) errorTextEl.textContent = err.message || 'Não foi possível carregar os dados da OS.';
        }
    });

    // Aba "Mapa de status": só cria/atualiza o widget quando a aba fica
    // visível de fato (o container começa display:none — medir/decorar um
    // SVG escondido dá dimensões erradas). Cria uma única vez por página
    // (evita reanexar os listeners globais de fullscreen/Esc a cada abertura
    // do modal) e reaproveita via refresh() nas aberturas seguintes,
    // possivelmente pra uma OS diferente.
    mapTabBtn?.addEventListener('shown.bs.tab', () => {
        if (!pendingMapConfig || !mapFrameEl || typeof window.DesktopOsMap === 'undefined') return;

        if (mapWidget) {
            mapWidget.refresh(pendingMapConfig);
            return;
        }

        mapWidget = window.DesktopOsMap.create(mapFrameEl, pendingMapConfig);
    });

    // Limpa o modal ao fechar
    modalEl.addEventListener('hidden.bs.modal', () => {
        currentOrderId = null;
        if (form) form.reset();
        showState('loading');
        if (submitBtn) submitBtn.disabled = true;
        setText('orderStatusModalNumero', '-');
        hideNovoPrazoSection();
        mensagemClienteEditada = false;
        if (notifyMessageEl) notifyMessageEl.value = '';
        renderNotifyPreview();

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

        // O nome do status entra na mensagem ao cliente — mantém em dia.
        refreshClientMessage();
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
            target: modalEl,
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

        // Notificação ligada: garante que existe um texto (o operador pode ter
        // ligado o switch e fechado o modal de revisão sem confirmar) e que o
        // campo escondido vai preenchido. Desligada: limpa, para não mandar
        // texto residual de uma edição que o operador acabou desfazendo.
        if (notifyEl?.checked) {
            if (notifyMessageEl && notifyMessageEl.value.trim() === '') {
                notifyMessageEl.value = buildDefaultClientMessage();
            }

            // O backend só notifica o cliente quando o status DE FATO muda
            // (ver OrderWorkflowService::updateStatus, guarda $statusChanged).
            // Salvar com o switch ligado sem trocar de status não enviaria
            // nada — avisa em vez de deixar o operador achar que enviou.
            const semTrocaDeStatus = selectEl?.value === '' || selectEl?.value === currentStatusCode;
            if (semTrocaDeStatus && typeof Swal !== 'undefined') {
                const aviso = await Swal.fire({
                    icon: 'warning',
                    target: modalEl,
                    title: 'O cliente não será notificado',
                    text: 'A mensagem só é enviada quando o status da OS muda, e nenhuma mudança de status foi selecionada. Deseja salvar assim mesmo?',
                    showCancelButton: true,
                    confirmButtonText: 'Salvar sem notificar',
                    cancelButtonText: 'Voltar e escolher a etapa',
                    reverseButtons: true,
                });

                if (!aviso.isConfirmed) return;
            }
        } else if (notifyMessageEl) {
            notifyMessageEl.value = '';
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
