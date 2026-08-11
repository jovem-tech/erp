(function () {
    const BAIXA_TARGET = '__baixa__';
    const DESTINO_FINAL = 'entregue_reparado_pago';

    // Fábrica de widget: cada chamada cria uma instância independente (state,
    // view, listeners próprios), presa a um `root` (elemento que contém os
    // nós marcados com data-os-map="..."). Existe pra poder reaproveitar
    // literalmente o mesmo mapa interativo tanto na página cheia
    // (`orders/map.blade.php`, root = .os-map-frame, auto-inicializada no
    // fim deste arquivo) quanto na aba "Mapa de status" do modal "Alterar
    // status da OS" (`_status_modal.blade.php`, inicializada sob demanda por
    // orders-status-modal.js — a MESMA instância é reaproveitada via
    // .refresh() a cada abertura do modal, já que o modal é uma partial
    // incluída uma vez por página, populada via AJAX pra OS diferentes).
    function createOsMapWidget(root, initialConfig) {
        const viewport = root.querySelector('[data-os-map="viewport"]');
        const canvas = root.querySelector('[data-os-map="canvas"]');
        const svg = canvas ? canvas.querySelector('svg') : null;

        if (!viewport || !canvas || !svg) return null;

        let config = initialConfig || {};

        const state = {
            statusAtual: '',
            isEncerrada: false,
            canEditStatus: false,
            canClose: false,
            statusCongelaPrazo: false,
            proximasEtapas: [],
            statusDisponiveis: [],
            path: [],
            etapaByCode: {},
            suggestedCodes: new Set(),
            clickableCodes: new Set(),
            closureCodeSet: new Set(),
        };

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
        if (portEl) portEl.classList.add('is-actionable');

        // Em tela cheia nativa (Fullscreen API), só o elemento em fullscreen (e
        // seus descendentes) é exibido — qualquer coisa fora dele (como o
        // container padrão do SweetAlert2, anexado a document.body) fica
        // invisível. Direciona o modal para dentro do elemento em fullscreen
        // quando ele existir; fora de tela cheia (ou no fallback de overlay
        // fixo, que não usa a Fullscreen API de verdade) o padrão (body) já
        // funciona normalmente.
        // Fora de tela cheia, se o mapa estiver dentro de um modal do Bootstrap
        // (aba "Mapa de status"), o diálogo precisa ser anexado ao modal: o
        // focus trap do Bootstrap devolve o foco para o modal a cada focusin,
        // o que impediria digitar na observação/no prazo do confirmMove().
        const swalTarget = () => document.fullscreenElement || root.closest('.modal') || document.body;

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

        // Recalcula todo o state derivado a partir de `config` — chamada na
        // criação do widget e a cada refresh()/refreshMap() (troca de OS ou
        // de status). `etapaByCode` vem do catálogo COMPLETO
        // (status_disponiveis, igual ao grid de chips do modal) — não só das
        // transições cadastradas — porque desde 2026-08-09 qualquer status
        // ativo fora de closureCodes() pode ser escolhido (ver
        // OrderWorkflowService::updateStatus() e o skill
        // sistema-erp-os-fluxo-fechamento). `proximasEtapas` continua
        // existindo só pra destacar visualmente a sugestão do catálogo de
        // transições (is-destination), não mais como filtro do que é clicável.
        const applyState = (newConfig) => {
            config = newConfig || config;

            state.statusAtual = String(config.statusAtual || '');
            state.isEncerrada = Boolean(config.isEncerrada);
            state.canEditStatus = Boolean(config.canEditStatus);
            state.canClose = Boolean(config.canClose);
            state.statusCongelaPrazo = Boolean(config.statusCongelaPrazo);
            state.proximasEtapas = Array.isArray(config.proximasEtapas) ? config.proximasEtapas : [];
            state.statusDisponiveis = Array.isArray(config.statusDisponiveis) ? config.statusDisponiveis : [];
            state.path = Array.isArray(config.path) ? config.path : [];

            // Os status de encerramento (grupo_macro 'encerrado') são
            // descartados aqui, na fonte: nunca podem ser aplicados fora do
            // fluxo de baixa (OrderClosureService::close(); o backend devolve
            // 'closure_status_requires_baixa_flow' se tentarem). Filtrar aqui
            // dentro — e não só em quem chama — mantém a invariante do skill
            // sistema-erp-os-fluxo-fechamento válida para qualquer chamador,
            // já que `status_disponiveis` é o catálogo COMPLETO e inclui eles.
            state.etapaByCode = {};
            state.statusDisponiveis.forEach((etapa) => {
                const code = String(etapa?.codigo || '').trim();
                if (!code) return;
                if (String(etapa?.grupo_macro || '').trim() === 'encerrado') return;
                state.etapaByCode[code] = etapa;
            });
            // Sem catálogo completo (não deveria acontecer), cai pra só as
            // etapas sugeridas em vez de travar o mapa inteiro como fechado.
            if (state.statusDisponiveis.length === 0) {
                state.proximasEtapas.forEach((etapa) => {
                    const code = String(etapa?.codigo || '').trim();
                    if (code) state.etapaByCode[code] = etapa;
                });
            }

            state.suggestedCodes = new Set(
                state.proximasEtapas.map((etapa) => String(etapa?.codigo || '').trim()).filter(Boolean)
            );

            state.clickableCodes = new Set();
            if (state.canEditStatus && !state.isEncerrada) {
                Object.keys(state.etapaByCode).forEach((code) => state.clickableCodes.add(code));
            }

            // Qualquer nó real do SVG que não está no catálogo não-baixa é,
            // por definição, um dos 5 status de encerramento (grupo_macro
            // 'encerrado') — nunca clicável direto, só pela porta "baixa".
            state.closureCodeSet = new Set(
                Object.keys(nodesByCode).filter((code) => !state.etapaByCode[code])
            );
            state.closureCodeSet.forEach((code) => nodesByCode[code]?.classList.add('is-closure'));
        };

        applyState(config);

        // ------------------------------------------------------------------
        // Decoração: base esmaecida + trajeto + posição atual + rota provável.
        // reset/apply são reexecutados inteiros a cada redecorate() — mais
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

            // Sugestão imediata do catálogo de transições (proximas_etapas) —
            // destaque visual mesmo fora da rota Dijkstra até reparo_concluido,
            // mesma classe is-destination usada pra rota sugerida.
            state.suggestedCodes.forEach((code) => nodesByCode[code]?.classList.add('is-destination'));
        };

        const redecorate = () => {
            resetDecoration();
            applyDecoration();
        };

        redecorate();

        // ------------------------------------------------------------------
        // Cliques: delegados no <svg> (não por nó) — assim uma atualização de
        // estado (refresh/refreshMap) não precisa desligar/religar listener
        // nenhum, só recalcular clickableCodes.
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
        // já ajustados pelo usuário; só recentraliza no novo nó atual. Usada
        // só quando config.onMoved não é informado (comportamento padrão da
        // página cheia); o modal "Alterar status" passa seu próprio onMoved
        // (fecha o modal e recarrega a página), então nunca chama isto.
        const refreshMap = async () => {
            const res = await fetch(String(config.mapDataUrl || ''), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('Não foi possível atualizar o mapa.');
            const data = await res.json();
            const order = data.order || {};

            applyState({
                ...config,
                statusAtual: order.status,
                isEncerrada: order.is_encerrada,
                canClose: Boolean(state.canEditStatus) && !Boolean(order.is_encerrada),
                statusCongelaPrazo: order.status_congela_prazo,
                proximasEtapas: order.proximas_etapas,
                statusDisponiveis: order.status_disponiveis,
                path: data.path,
            });

            // Pill de status e banner ficam no cabeçalho da página (fora de
            // .os-map-frame) na página cheia, e nem existem no modal — por
            // isso a busca é em `document`, não em `root` (o modal "Alterar
            // status" nunca chama refreshMap(), só a página cheia; ver
            // config.onMoved acima).
            const pillEl = document.querySelector('[data-os-map="status-pill"]');
            if (pillEl) {
                const label = (order.status_nome || '') !== '' ? order.status_nome : 'Sem status';
                const color = order.status_cor || '#64748b';
                pillEl.innerHTML = `<span class="status-pill" style="--status-color: ${color}"><span>${label}</span></span>`;
            }

            // Banner (encerrada / cancelada / nenhum).
            const bannerEl = document.querySelector('[data-os-map="banner"]');
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
            // Também fora de .os-map-frame na página cheia (ver nota acima).
            const trailEl = document.querySelector('[data-os-map="trail"]');
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

                if (typeof config.onMoved === 'function') {
                    config.onMoved(response, etapa);
                    return;
                }

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
                    // Navegação programática precisa se declarar ao guard de
                    // sessão do layout (app.blade.php): ele só detecta clique
                    // em <a>, submit e F5 sozinho. Sem isto o pagehide grava
                    // "navegador fechado" e a tela de baixa desloga o usuário
                    // assim que carrega (POST /logout automático).
                    window.erpMarkInternalNavigation?.();
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
                } else if (state.closureCodeSet.has(code)) {
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
            if (rect.width <= 0 || rect.height <= 0) return;
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

        root.querySelector('[data-os-map="zoom-in"]')?.addEventListener('click', () => {
            const rect = viewport.getBoundingClientRect();
            zoomAt(rect.left + rect.width / 2, rect.top + rect.height / 2, 1.25);
        });
        root.querySelector('[data-os-map="zoom-out"]')?.addEventListener('click', () => {
            const rect = viewport.getBoundingClientRect();
            zoomAt(rect.left + rect.width / 2, rect.top + rect.height / 2, 1 / 1.25);
        });
        root.querySelector('[data-os-map="zoom-reset"]')?.addEventListener('click', fitToViewport);
        root.querySelector('[data-os-map="center-current"]')?.addEventListener('click', centerOnCurrent);

        // ------------------------------------------------------------------
        // Tela cheia: Fullscreen API nativa (Esc sai de graça) com fallback
        // de overlay fixo; X no canto e refit do zoom ao entrar/sair.
        // ------------------------------------------------------------------
        const frame = viewport.closest('.os-map-frame');
        const fullscreenBtn = root.querySelector('[data-os-map="fullscreen"]');
        const exitFullscreenBtn = root.querySelector('[data-os-map="exit-fullscreen"]');

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
            if (!frame || (document.fullscreenElement && document.fullscreenElement !== frame)) return;
            frame.classList.toggle('is-fullscreen', Boolean(document.fullscreenElement));
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

        // View inicial: a página cheia (/os/{id}/mapa) abre centrada na posição
        // atual (scale mínimo 0.85) — é uma tela dedicada, o usuário já sabe
        // que está no mapa e quer ver a vizinhança da etapa atual. A aba do
        // modal abre com o fluxo INTEIRO à vista ('fit'): ali o objetivo é
        // justamente localizar a OS dentro do fluxo completo antes de escolher
        // para onde mover. Em ambos os casos a posição atual fica marcada pelo
        // .os-map-here pulsante, e os botões da toolbar alternam livremente.
        const applyInitialView = () => {
            fitToViewport();
            if (config.initialView !== 'fit' && currentNode) centerOnCurrent();
        };

        applyInitialView();

        return {
            // Reinicializa pra uma OS (possivelmente) diferente, reaproveitando
            // a mesma instância/listeners — usado pelo modal "Alterar status" a
            // cada abertura. Sempre reajusta zoom/posição (conteúdo novo),
            // diferente do refreshMap() interno (mesma OS, só status mudou —
            // preserva o zoom/pan que o usuário já tinha ajustado).
            refresh(newConfig) {
                applyState(newConfig);
                redecorate();
                applyInitialView();
            },
            fitToViewport,
            centerOnCurrent,
        };
    }

    window.DesktopOsMap = window.DesktopOsMap || {};
    window.DesktopOsMap.create = createOsMapWidget;

    // Auto-init do mapa em página cheia (orders/map.blade.php) — comportamento
    // inalterado; só passou a usar o mesmo widget genérico por baixo.
    if (window.__DESKTOP_OS_MAP) {
        const root = document.querySelector('.os-map-frame');
        if (root) createOsMapWidget(root, window.__DESKTOP_OS_MAP);
    }
})();
