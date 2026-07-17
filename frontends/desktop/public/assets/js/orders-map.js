(function () {
    const config = window.__DESKTOP_OS_MAP || {};
    const viewport = document.getElementById('osMapViewport');
    const canvas = document.getElementById('osMapCanvas');
    const svg = canvas ? canvas.querySelector('svg') : null;

    if (!viewport || !canvas || !svg) return;

    const BAIXA_TARGET = '__baixa__';
    const DESTINO_FINAL = 'entregue_reparado_pago';
    const CLOSURE_CODES = ['entregue_reparado_pago', 'entregue_reparado_sem_custo', 'entregue_reparado_garantia', 'devolvido_sem_reparo', 'descartado'];

    // Estado mutável: trocar de status (inclusive em tela cheia) atualiza
    // isso via refreshMap() e redecora o mesmo SVG, sem recarregar a página
    // — um reload sempre sairia da tela cheia (é o navegador quem faz isso
    // em qualquer navegação, não tem como evitar de outro jeito).
    const state = {
        statusAtual: String(config.statusAtual || ''),
        isEncerrada: Boolean(config.isEncerrada),
        canEditStatus: Boolean(config.canEditStatus),
        canClose: Boolean(config.canClose),
        statusCongelaPrazo: Boolean(config.statusCongelaPrazo),
        proximasEtapas: Array.isArray(config.proximasEtapas) ? config.proximasEtapas : [],
        path: Array.isArray(config.path) ? config.path : [],
        etapaByCode: {},
        clickableCodes: new Set(),
    };

    const rebuildEtapaLookups = () => {
        state.etapaByCode = {};
        state.clickableCodes = new Set();
        state.proximasEtapas.forEach((etapa) => {
            const code = String(etapa.codigo || '');
            if (!code) return;
            state.etapaByCode[code] = etapa;
        });
        if (state.canEditStatus && !state.isEncerrada) {
            Object.keys(state.etapaByCode).forEach((code) => state.clickableCodes.add(code));
        }
    };
    rebuildEtapaLookups();

    // ------------------------------------------------------------------
    // Grafo a partir do próprio SVG (data-edge="origem:destino") — estrutura
    // fixa, calculada uma vez só; o que muda entre atualizações é o estado
    // (state) e as classes aplicadas em cima desses mesmos elementos.
    // ------------------------------------------------------------------
    const nodesByCode = {};
    svg.querySelectorAll('[data-status]').forEach((el) => {
        nodesByCode[el.dataset.status] = el;
    });

    const edgesByPair = {};
    const adjacency = {};
    svg.querySelectorAll('[data-edge]').forEach((el) => {
        const pair = String(el.dataset.edge || '');
        const [from, to] = pair.split(':');
        if (!from || !to) return;
        edgesByPair[pair] = el;
        (adjacency[from] = adjacency[from] || []).push({ to, kind: el.dataset.edgeKind || 'alt' });
    });

    const portEl = svg.querySelector('[data-port="baixa"]');

    CLOSURE_CODES.forEach((code) => nodesByCode[code]?.classList.add('is-closure'));
    if (portEl) portEl.classList.add('is-actionable');

    // Em tela cheia nativa (Fullscreen API), só o elemento em fullscreen (e
    // seus descendentes) é exibido — qualquer coisa fora dele (como o
    // container padrão do SweetAlert2, anexado a document.body) fica
    // invisível. Direciona o modal para dentro do elemento em fullscreen
    // quando ele existir; fora de tela cheia (ou no fallback de overlay
    // fixo, que não usa a Fullscreen API de verdade) o padrão (body) já
    // funciona normalmente.
    const swalTarget = () => document.fullscreenElement || document.body;

    const showToast = (message, type = 'success') => {
        if (typeof Swal === 'undefined') return;
        Swal.fire({
            toast: true,
            position: 'top-end',
            timer: 4500,
            timerProgressBar: true,
            showConfirmButton: false,
            icon: type,
            title: message,
            target: swalTarget(),
            customClass: { popup: 'swal-desktop-toast' },
        });
    };

    // ------------------------------------------------------------------
    // Decoração: base esmaecida + trajeto + posição atual + rota provável.
    // reset/apply são reexecutados inteiros a cada refreshMap() — mais
    // simples e robusto que remendar o que já estava decorado.
    // ------------------------------------------------------------------
    svg.classList.add('os-map--decorated');

    let currentNode = null;

    const resetDecoration = () => {
        svg.querySelectorAll('.is-visited, .is-current, .is-clickable, .is-destination').forEach((el) => {
            el.classList.remove('is-visited', 'is-current', 'is-clickable', 'is-destination');
        });
        svg.querySelectorAll('.is-traveled, .is-suggested').forEach((el) => {
            el.classList.remove('is-traveled', 'is-suggested');
        });
        portEl?.classList.remove('is-suggested');
        svg.querySelectorAll('.os-map-here').forEach((el) => el.remove());
        currentNode = null;
    };

    const markHere = (node) => {
        const box = node.getBBox();
        const here = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        here.setAttribute('cx', String(box.x + box.width - 4));
        here.setAttribute('cy', String(box.y + 4));
        here.setAttribute('r', '9');
        here.classList.add('os-map-here');
        svg.appendChild(here);
    };

    // Dijkstra até reparo_concluido preferindo o caminho feliz (main=1,
    // demais=5); a etapa final (baixa → entregue reparado e pago) é a aresta
    // roxa da porta, fora do catálogo de transições.
    const suggestRoute = () => {
        if (state.isEncerrada || !currentNode) return;

        const dist = { [state.statusAtual]: 0 };
        const prev = {};
        const queue = [state.statusAtual];

        while (queue.length > 0) {
            queue.sort((a, b) => (dist[a] ?? Infinity) - (dist[b] ?? Infinity));
            const node = queue.shift();
            if (node === 'reparo_concluido') break;

            (adjacency[node] || []).forEach((edge) => {
                if (edge.to === BAIXA_TARGET) return;
                const cost = (dist[node] ?? Infinity) + (edge.kind === 'main' ? 1 : 5);
                if (cost < (dist[edge.to] ?? Infinity)) {
                    dist[edge.to] = cost;
                    prev[edge.to] = node;
                    queue.push(edge.to);
                }
            });
        }

        if (!('reparo_concluido' in dist)) return;

        const routeNodes = [];
        let cursor = 'reparo_concluido';
        while (cursor !== undefined) {
            routeNodes.unshift(cursor);
            cursor = prev[cursor];
        }

        for (let i = 0; i < routeNodes.length - 1; i++) {
            const el = edgesByPair[`${routeNodes[i]}:${routeNodes[i + 1]}`];
            if (el) el.classList.add('is-suggested');
            const node = nodesByCode[routeNodes[i + 1]];
            if (node) node.classList.add('is-destination');
        }

        const baixaEdge = edgesByPair[`reparo_concluido:${BAIXA_TARGET}`];
        if (baixaEdge) baixaEdge.classList.add('is-suggested');
        portEl?.classList.add('is-suggested');
        if (nodesByCode[DESTINO_FINAL]) nodesByCode[DESTINO_FINAL].classList.add('is-destination');
    };

    const applyDecoration = () => {
        state.path.forEach((hop) => {
            const de = String(hop.de || '');
            const para = String(hop.para || '');
            if (de && edgesByPair[`${de}:${para}`]) {
                edgesByPair[`${de}:${para}`].classList.add('is-traveled');
            }
            if (de && nodesByCode[de]) nodesByCode[de].classList.add('is-visited');
            if (nodesByCode[para]) nodesByCode[para].classList.add('is-visited');
        });

        currentNode = nodesByCode[state.statusAtual] || null;

        if (!currentNode && state.statusAtual !== '') {
            // Status legado/desconhecido: painel lateral já mostra o código cru.
            showToast(`Status atual (${state.statusAtual}) não está no mapa do fluxo.`, 'warning');
        }

        if (currentNode) {
            currentNode.classList.add('is-current');
            markHere(currentNode);
        }

        suggestRoute();

        if (state.canEditStatus && !state.isEncerrada) {
            state.clickableCodes.forEach((code) => nodesByCode[code]?.classList.add('is-clickable'));
        }
    };

    const redecorate = () => {
        resetDecoration();
        applyDecoration();
    };

    redecorate();

    // ------------------------------------------------------------------
    // Cliques: delegados no <svg> (não por nó) — assim uma atualização de
    // estado (refreshMap) não precisa desligar/religar listener nenhum, só
    // recalcular clickableCodes.
    // ------------------------------------------------------------------
    const suggestedNovoPrazo = () => {
        const data = new Date();
        data.setDate(data.getDate() + 7);
        const ano = data.getFullYear();
        const mes = String(data.getMonth() + 1).padStart(2, '0');
        const dia = String(data.getDate()).padStart(2, '0');
        return `${ano}-${mes}-${dia}`;
    };

    const applyStatus = async (etapa, observacao, novoPrazo) => {
        const formData = new FormData();
        formData.append('status', etapa.codigo);
        if (observacao) formData.append('observacao', observacao);
        if (novoPrazo) formData.append('novo_prazo', novoPrazo);

        const res = await fetch(String(config.statusUpdateUrl || ''), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': String(config.csrfToken || ''),
            },
            body: formData,
        });

        const result = await res.json().catch(() => ({}));
        if (!res.ok || result.error) {
            throw new Error(result.error || result.message || 'Não foi possível mover a OS.');
        }

        return result;
    };

    // Busca o estado fresco do mapa (novo status, trajeto, próximas etapas)
    // e redecora o MESMO svg/DOM — sem location.reload(), que sairia da
    // tela cheia (navegação sempre encerra fullscreen). Mantém zoom/posição
    // já ajustados pelo usuário; só recentraliza no novo nó atual.
    const refreshMap = async () => {
        const res = await fetch(String(config.mapDataUrl || ''), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) throw new Error('Não foi possível atualizar o mapa.');
        const data = await res.json();
        const order = data.order || {};

        state.statusAtual = String(order.status || '');
        state.isEncerrada = Boolean(order.is_encerrada);
        state.canClose = Boolean(state.canEditStatus) && !state.isEncerrada;
        state.statusCongelaPrazo = Boolean(order.status_congela_prazo);
        state.proximasEtapas = Array.isArray(order.proximas_etapas) ? order.proximas_etapas : [];
        state.path = Array.isArray(data.path) ? data.path : [];
        rebuildEtapaLookups();

        // Pill de status do cabeçalho (mesmo markup de layouts/partials/status-pill.blade.php).
        const pillEl = document.getElementById('osMapStatusPill');
        if (pillEl) {
            const label = (order.status_nome || '') !== '' ? order.status_nome : 'Sem status';
            const color = order.status_cor || '#64748b';
            pillEl.innerHTML = `<span class="status-pill" style="--status-color: ${color}"><span>${label}</span></span>`;
        }

        // Banner (encerrada / cancelada / nenhum).
        const bannerEl = document.getElementById('osMapBanner');
        if (bannerEl) {
            if (state.isEncerrada) {
                bannerEl.innerHTML = `<div class="alert alert-info d-flex align-items-center gap-2">
                    <i class="bi bi-lock"></i>
                    <div>OS encerrada — o mapa é somente leitura. Para reabrir, use "Cancelar baixa" na tela da OS.</div>
                </div>`;
            } else if (state.statusAtual === 'cancelado') {
                bannerEl.innerHTML = `<div class="alert alert-warning d-flex align-items-center gap-2">
                    <i class="bi bi-info-circle"></i>
                    <div>OS cancelada — a única continuação possível é a reabertura (voltar para Triagem).</div>
                </div>`;
            } else {
                bannerEl.innerHTML = '';
            }
        }

        // Painel "Trajeto percorrido" — HTML já vem pronto do servidor
        // (orders._map_trail), evita duplicar a lógica de rótulo em JS.
        const trailEl = document.getElementById('osMapTrailContainer');
        if (trailEl && typeof data.trailHtml === 'string') {
            trailEl.innerHTML = data.trailHtml;
        }

        redecorate();
        if (currentNode) centerOnCurrent();
    };

    const confirmMove = (etapa) => {
        if (typeof Swal === 'undefined') return;

        const precisaPrazo = state.statusCongelaPrazo && !etapa.congela_prazo;
        const prazoHtml = precisaPrazo
            ? `<label class="form-label small mt-2 mb-1 d-block text-start">Novo prazo de entrega</label>
               <input type="date" id="osMapNovoPrazo" class="form-control" value="${suggestedNovoPrazo()}">`
            : '';

        Swal.fire({
            title: `Mover para "${etapa.nome || etapa.codigo}"?`,
            html: `
                <label class="form-label small mb-1 d-block text-start">Observações (opcional)</label>
                <textarea id="osMapObservacao" class="form-control" rows="3"
                    placeholder="Registre contexto da mudança ou combinados com o cliente."></textarea>
                ${prazoHtml}
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Mover OS',
            cancelButtonText: 'Cancelar',
            target: swalTarget(),
            preConfirm: () => {
                const observacao = document.getElementById('osMapObservacao')?.value || '';
                const novoPrazo = document.getElementById('osMapNovoPrazo')?.value || '';
                if (precisaPrazo && !novoPrazo) {
                    Swal.showValidationMessage('Informe o novo prazo de entrega para sair de um status com prazo congelado.');
                    return false;
                }
                return { observacao, novoPrazo };
            },
        }).then(async (result) => {
            if (!result.isConfirmed || !result.value) return;

            let response;
            try {
                response = await applyStatus(etapa, result.value.observacao, result.value.novoPrazo);
            } catch (err) {
                showToast(err.message || 'Não foi possível mover a OS. Tente novamente.', 'error');
                return;
            }

            showToast(response.message || `Status alterado para: ${etapa.nome || etapa.codigo}.`, 'success');

            try {
                await refreshMap();
            } catch (err) {
                // O status já mudou no servidor; só o refresh visual falhou.
                showToast('Status alterado, mas não foi possível atualizar o mapa automaticamente. Recarregue a página.', 'warning');
            }
        });
    };

    // Encerramentos e a porta da baixa: nunca mudam status daqui.
    const explainBaixa = () => {
        if (typeof Swal === 'undefined') return;
        Swal.fire({
            title: 'Encerramento é pela baixa da OS',
            text: 'Os status de encerramento (entregue, devolvido, descartado) só são aplicados pela tela de baixa, que faz a conferência financeira.',
            icon: 'info',
            showCancelButton: state.canClose,
            confirmButtonText: state.canClose ? 'Ir para a baixa da OS' : 'Entendi',
            cancelButtonText: 'Fechar',
            target: swalTarget(),
        }).then((result) => {
            if (state.canClose && result.isConfirmed) {
                window.location.href = String(config.closureUrl || '#');
            }
        });
    };

    svg.addEventListener('click', (event) => {
        const node = event.target.closest('[data-status]');
        const port = event.target.closest('[data-port="baixa"]');

        if (node) {
            const code = node.dataset.status;
            if (state.clickableCodes.has(code)) {
                confirmMove(state.etapaByCode[code]);
            } else if (CLOSURE_CODES.includes(code)) {
                explainBaixa();
            }
            return;
        }

        if (port) explainBaixa();
    });

    // ------------------------------------------------------------------
    // Pan / zoom
    // ------------------------------------------------------------------
    const MAP_W = 1780;
    const MAP_H = 1560;
    const view = { x: 0, y: 0, scale: 1 };

    const applyTransform = () => {
        canvas.style.transform = `translate(${view.x}px, ${view.y}px) scale(${view.scale})`;
    };

    const clampScale = (scale) => Math.min(2.5, Math.max(0.25, scale));

    const fitToViewport = () => {
        const rect = viewport.getBoundingClientRect();
        view.scale = clampScale(Math.min(rect.width / MAP_W, rect.height / MAP_H));
        view.x = (rect.width - MAP_W * view.scale) / 2;
        view.y = (rect.height - MAP_H * view.scale) / 2;
        applyTransform();
    };

    const zoomAt = (clientX, clientY, factor) => {
        const rect = viewport.getBoundingClientRect();
        const px = clientX - rect.left;
        const py = clientY - rect.top;
        const newScale = clampScale(view.scale * factor);
        const ratio = newScale / view.scale;
        view.x = px - (px - view.x) * ratio;
        view.y = py - (py - view.y) * ratio;
        view.scale = newScale;
        applyTransform();
    };

    const centerOnCurrent = () => {
        if (!currentNode) return;
        const box = currentNode.getBBox();
        const rect = viewport.getBoundingClientRect();
        view.scale = clampScale(Math.max(view.scale, 0.85));
        view.x = rect.width / 2 - (box.x + box.width / 2) * view.scale;
        view.y = rect.height / 2 - (box.y + box.height / 2) * view.scale;
        applyTransform();
    };

    viewport.addEventListener('wheel', (event) => {
        event.preventDefault();
        zoomAt(event.clientX, event.clientY, event.deltaY < 0 ? 1.12 : 1 / 1.12);
    }, { passive: false });

    let panning = null;
    viewport.addEventListener('pointerdown', (event) => {
        // Não inicia pan sobre elementos clicáveis (deixa o click acontecer).
        if (event.target.closest('.os-map-node.is-clickable, .os-map-node.is-closure, .os-map-port, .os-map-toolbar, .os-map-close')) {
            return;
        }
        // Sem isso, arrastar sobre um <text> do SVG dispara seleção de texto
        // nativa do navegador (rouba o gesto do pan) mesmo com user-select:
        // none no CSS — alguns navegadores só respeitam de fato com o
        // preventDefault aqui.
        event.preventDefault();
        panning = { startX: event.clientX, startY: event.clientY, baseX: view.x, baseY: view.y };
        viewport.classList.add('is-panning');
        viewport.setPointerCapture(event.pointerId);
    });

    viewport.addEventListener('pointermove', (event) => {
        if (!panning) return;
        view.x = panning.baseX + (event.clientX - panning.startX);
        view.y = panning.baseY + (event.clientY - panning.startY);
        applyTransform();
    });

    const endPan = () => {
        panning = null;
        viewport.classList.remove('is-panning');
    };
    viewport.addEventListener('pointerup', endPan);
    viewport.addEventListener('pointercancel', endPan);

    document.getElementById('osMapZoomIn')?.addEventListener('click', () => {
        const rect = viewport.getBoundingClientRect();
        zoomAt(rect.left + rect.width / 2, rect.top + rect.height / 2, 1.25);
    });
    document.getElementById('osMapZoomOut')?.addEventListener('click', () => {
        const rect = viewport.getBoundingClientRect();
        zoomAt(rect.left + rect.width / 2, rect.top + rect.height / 2, 1 / 1.25);
    });
    document.getElementById('osMapZoomReset')?.addEventListener('click', fitToViewport);
    document.getElementById('osMapCenterCurrent')?.addEventListener('click', centerOnCurrent);

    // ------------------------------------------------------------------
    // Tela cheia: Fullscreen API nativa (Esc sai de graça) com fallback
    // de overlay fixo; X no canto e refit do zoom ao entrar/sair.
    // ------------------------------------------------------------------
    const frame = viewport.closest('.os-map-frame');
    const fullscreenBtn = document.getElementById('osMapFullscreen');
    const exitFullscreenBtn = document.getElementById('osMapExitFullscreen');

    const refitAfterResize = () => {
        // Espera o layout assentar nas novas dimensões antes de recalcular.
        requestAnimationFrame(() => {
            fitToViewport();
            if (currentNode) centerOnCurrent();
        });
    };

    const isFullscreen = () => Boolean(document.fullscreenElement) || frame?.classList.contains('is-fullscreen-overlay');

    const enterFullscreen = () => {
        if (!frame) return;
        if (frame.requestFullscreen) {
            frame.requestFullscreen().catch(() => {
                frame.classList.add('is-fullscreen', 'is-fullscreen-overlay');
                refitAfterResize();
            });
            return;
        }
        frame.classList.add('is-fullscreen', 'is-fullscreen-overlay');
        refitAfterResize();
    };

    const exitFullscreen = () => {
        if (document.fullscreenElement) {
            document.exitFullscreen?.();
            return;
        }
        frame?.classList.remove('is-fullscreen', 'is-fullscreen-overlay');
        refitAfterResize();
    };

    fullscreenBtn?.addEventListener('click', () => (isFullscreen() ? exitFullscreen() : enterFullscreen()));
    exitFullscreenBtn?.addEventListener('click', exitFullscreen);

    // Fullscreen API: sincroniza a classe (o Esc nativo dispara só este evento).
    document.addEventListener('fullscreenchange', () => {
        frame?.classList.toggle('is-fullscreen', Boolean(document.fullscreenElement));
        refitAfterResize();
    });

    // Esc sai da tela cheia. No fullscreen nativo o navegador já garante isso
    // sozinho; o handler explícito cobre também o modo fallback (overlay) e
    // qualquer ambiente onde o atalho nativo não dispare.
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isFullscreen()) {
            exitFullscreen();
        }
    });

    fitToViewport();
    if (currentNode) centerOnCurrent();
})();
