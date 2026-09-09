(function () {
    // Limite de passos da rota provavel. Protege contra caminhada longa demais
    // num catalogo grande; a parada normal e chegar num status de saida/
    // encerramento ou ficar sem amostra suficiente.
    const ROUTE_MAX_STEPS = 12;

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
            flowStats: {},
            transicoesCatalogo: [],
            showCatalog: false,
            etapaByCode: {},
            suggestedCodes: new Set(),
            clickableCodes: new Set(),
            closureCodeSet: new Set(),
        };

        // ------------------------------------------------------------------
        // Nós do desenho. O SVG agora é GERADO do catálogo vivo
        // (App\Support\OrderFlowMapLayout), então todo status ativo tem card —
        // inclusive um criado agora na tela "Status de OS".
        //
        // As ARESTAS não existem mais no SVG. Até 09/09/2026 elas eram 73
        // polilinhas roteadas à mão no gerador Python, e uma aresta só podia
        // ser pintada de "percorrida" se já existisse ali: um salto real fora
        // do catálogo de transições (ex.: aguardando_reparo -> reparo_concluido,
        // que o backend aceita desde 09/08/2026) simplesmente não aparecia.
        // Agora as arestas são desenhadas em runtime, a partir das caixas dos
        // cards, então qualquer par de nós pode ser ligado.
        // ------------------------------------------------------------------
        const nodesByCode = {};
        svg.querySelectorAll('[data-status]').forEach((el) => {
            nodesByCode[el.dataset.status] = el;
        });

        const edgeLayer = svg.querySelector('[data-os-map-layer="edges"]');

        const portEl = svg.querySelector('[data-port="baixa"]');
        if (portEl) portEl.classList.add('is-actionable');

        // Caixa de um card/porta em coordenadas do viewBox. getBBox() já era
        // usado por markHere(); aqui é a base de todo o roteamento.
        const boxOf = (el) => {
            if (!el) return null;
            const b = el.getBBox();
            return { x: b.x, y: b.y, w: b.width, h: b.height, cx: b.x + b.width / 2, cy: b.y + b.height / 2 };
        };

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
            state.flowStats = config.flowStats && typeof config.flowStats === 'object' ? config.flowStats : {};

            // Catálogo de transições para o overlay opcional — vem junto das
            // estatísticas. É só material de leitura: não trava o que pode ser
            // escolhido (o backend não valida transição desde 09/08/2026).
            state.transicoesCatalogo = Array.isArray(state.flowStats.catalogo_transicoes)
                ? state.flowStats.catalogo_transicoes
                : [];

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
            portEl?.classList.remove('is-suggested');
            svg.querySelectorAll('.os-map-here').forEach((el) => el.remove());
            // As arestas são recriadas do zero a cada redecorate() — são
            // geradas, não fazem parte do desenho base.
            if (edgeLayer) edgeLayer.replaceChildren();
            // Geometria dos corredores é estável enquanto o SVG for o mesmo,
            // mas o modal cria o widget com o SVG ainda escondido: a primeira
            // medição pode vir zerada. Recalcula a cada redecorate().
            corridors = null;
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

        // ------------------------------------------------------------------
        // Roteador ortogonal por CORREDORES LIVRES.
        //
        // O layout (App\Support\OrderFlowMapLayout) põe as raias numa grade
        // uniforme: mesma largura, mesmo passo, todas começando na mesma
        // margem. Isso cria duas famílias de faixas garantidamente vazias —
        // os vãos verticais entre raias vizinhas e os vãos horizontais entre
        // as linhas de raias. Toda seta anda por elas.
        //
        // A primeira versão ligava as caixas pelo ponto médio e cortava por
        // cima dos cards que estivessem no caminho. Aqui a rota sai do card
        // até o vão ao lado da própria raia, desce/sobe por esse vão até um
        // canal horizontal livre, atravessa, e só então entra no destino.
        // ------------------------------------------------------------------
        const SVG_NS = 'http://www.w3.org/2000/svg';

        // Calculado sob demanda: getBBox() só devolve valor útil depois do
        // SVG estar no layout (dentro do modal ele nasce escondido).
        let corridors = null;

        const buildCorridors = () => {
            const laneBoxes = [];
            svg.querySelectorAll('.os-map-lane').forEach((el) => {
                const b = boxOf(el);
                if (b) laneBoxes.push(b);
            });

            const cards = [];
            svg.querySelectorAll('[data-status]').forEach((el) => {
                const b = boxOf(el);
                if (b) cards.push(b);
            });

            const portBox = portEl ? boxOf(portEl) : null;
            const obstacles = portBox ? cards.concat([portBox]) : cards.slice();

            if (laneBoxes.length === 0) {
                return { gutters: [], channels: [], crossings: [], cards, obstacles };
            }

            // Vãos verticais: um à esquerda da primeira coluna de raias, um
            // entre cada par de colunas, um à direita da última.
            const laneW = laneBoxes[0].w;
            const xs = [...new Set(laneBoxes.map((l) => Math.round(l.x)))].sort((a, b) => a - b);
            const gap = xs.length > 1 ? Math.max(12, xs[1] - (xs[0] + laneW)) : 34;

            const gutters = [xs[0] - gap / 2];
            xs.forEach((x, i) => {
                gutters.push(i < xs.length - 1 ? (x + laneW + xs[i + 1]) / 2 : x + laneW + gap / 2);
            });

            // Canais horizontais: acima da primeira linha de raias, entre as
            // linhas, e abaixo da última.
            const rowsByY = new Map();
            laneBoxes.forEach((l) => {
                const key = Math.round(l.y);
                const row = rowsByY.get(key) || { top: l.y, bottom: l.y + l.h };
                row.bottom = Math.max(row.bottom, l.y + l.h);
                rowsByY.set(key, row);
            });
            const rows = [...rowsByY.values()].sort((a, b) => a.top - b.top);

            const channels = [rows[0].top - 22];
            rows.forEach((row, i) => {
                channels.push(i < rows.length - 1 ? (row.bottom + rows[i + 1].top) / 2 : row.bottom + 22);
            });

            // Além dos canais entre linhas de raias, toda folga vertical entre
            // obstáculos serve de travessia. Sem isso, uma seta de Execução
            // até Concluído só podia contornar as raias por cima, virando um
            // "U" gigante; agora ela corta rente, por baixo dos cards de
            // Qualidade, que é o caminho curto e legível.
            const edges = [];
            obstacles.forEach((o) => edges.push(o.y, o.y + o.h));
            edges.sort((a, b) => a - b);

            const crossings = new Set(channels);
            for (let i = 0; i < edges.length - 1; i++) {
                if (edges[i + 1] - edges[i] >= 26) {
                    crossings.add((edges[i] + edges[i + 1]) / 2);
                }
            }

            return { gutters, channels, crossings: [...crossings].sort((a, b) => a - b), cards, obstacles };
        };

        const nearestTo = (values, target) => values.reduce(
            (best, v) => (Math.abs(v - target) < Math.abs(best - target) ? v : best),
            values[0]
        );

        // Vão livre imediatamente ao lado da caixa, no sentido pedido.
        const gutterBeside = (box, towardRight) => {
            const all = corridors.gutters;
            if (all.length === 0) return towardRight ? box.x + box.w + 20 : box.x - 20;

            const candidates = towardRight
                ? all.filter((x) => x > box.x + box.w)
                : all.filter((x) => x < box.x);

            if (candidates.length === 0) return nearestTo(all, box.cx);

            return towardRight ? Math.min(...candidates) : Math.max(...candidates);
        };

        const sameBox = (a, b) => a.x === b.x && a.y === b.y && a.w === b.w && a.h === b.h;

        // Existe obstáculo entre as duas caixas, na faixa vertical em x? Se
        // não, dá para descer reto. Olha TODOS os obstáculos, não só cards:
        // a porta da baixa fica entre a raia de saídas e a de encerramento,
        // exatamente na coluna dos cards, e uma reta de "Reparo Recusado" até
        // "Entregue - Reparado e Pago" passava por cima dela.
        const blockedBetween = (from, to, x) => {
            const lo = Math.min(from.cy, to.cy);
            const hi = Math.max(from.cy, to.cy);

            return corridors.obstacles.some((o) => {
                if (sameBox(o, from) || sameBox(o, to)) return false;

                return o.x - 4 < x && x < o.x + o.w + 4
                    && o.y < hi - 1 && lo + 1 < o.y + o.h;
            });
        };

        // Canal horizontal que atravessa de xa a xb sem esbarrar em card nem
        // na porta da baixa. A margem cobre o deslocamento por aresta.
        const pickChannel = (xa, xb, midY) => {
            const lo = Math.min(xa, xb);
            const hi = Math.max(xa, xb);
            const M = 20;

            const free = corridors.crossings.filter((y) => !corridors.obstacles.some(
                (o) => o.y - M < y && y < o.y + o.h + M && o.x - M < hi && lo < o.x + o.w + M
            ));

            return nearestTo(free.length > 0 ? free : corridors.channels, midY);
        };

        const CORNER_R = 12;

        // Monta o "d" da polilinha com os cantos arredondados. Descarta pontos
        // consecutivos iguais no caminho — raias vizinhas na mesma altura
        // geravam um segmento de comprimento zero no meio.
        const pathOf = (points) => {
            const pts = points.filter(
                (p, i, all) => i === 0 || p[0] !== all[i - 1][0] || p[1] !== all[i - 1][1]
            );

            if (pts.length < 3) {
                return pts.map(([x, y], i) => `${i === 0 ? 'M' : 'L'} ${x} ${y}`).join(' ');
            }

            const round1 = (n) => Math.round(n * 10) / 10;
            let d = `M ${round1(pts[0][0])} ${round1(pts[0][1])}`;

            for (let i = 1; i < pts.length - 1; i++) {
                const [ax, ay] = pts[i - 1];
                const [px, py] = pts[i];
                const [bx, by] = pts[i + 1];

                const dIn = Math.hypot(px - ax, py - ay);
                const dOut = Math.hypot(bx - px, by - py);
                const r = Math.min(CORNER_R, dIn / 2, dOut / 2);

                if (r < 1) {
                    d += ` L ${round1(px)} ${round1(py)}`;

                    continue;
                }

                const inX = px - ((px - ax) / dIn) * r;
                const inY = py - ((py - ay) / dIn) * r;
                const outX = px + ((bx - px) / dOut) * r;
                const outY = py + ((by - py) / dOut) * r;

                d += ` L ${round1(inX)} ${round1(inY)}`
                    + ` Q ${round1(px)} ${round1(py)} ${round1(outX)} ${round1(outY)}`;
            }

            const last = pts[pts.length - 1];

            return `${d} L ${round1(last[0])} ${round1(last[1])}`;
        };

        const routeBetween = (from, to, slot) => {
            if (!corridors) corridors = buildCorridors();

            // Deslocamento por aresta, para setas paralelas não se cobrirem.
            // Teto de 12px: do centro do vão até a borda do card há ~39px.
            const jitter = ((slot % 5) - 2) * 6;

            const sameColumn = Math.abs(from.cx - to.cx) < 2;
            const straightX = from.cx + ((slot % 3) - 1) * 8;

            // Mesma coluna e nada no meio: reto pelo vão entre os cards.
            if (sameColumn && !blockedBetween(from, to, straightX)) {
                const y1 = to.cy > from.cy ? from.y + from.h : from.y;
                const y2 = to.cy > from.cy ? to.y : to.y + to.h;

                return pathOf([[straightX, y1], [straightX, y2]]);
            }

            // Mesma coluna com algo no meio: contorna pelo vão ao lado.
            if (sameColumn) {
                const g = gutterBeside(from, true) + jitter;

                return pathOf([
                    [from.x + from.w, from.cy], [g, from.cy],
                    [g, to.cy], [to.x + to.w, to.cy],
                ]);
            }

            const goingRight = to.cx > from.cx;
            const x1 = goingRight ? from.x + from.w : from.x;
            const x2 = goingRight ? to.x : to.x + to.w;
            const gA = gutterBeside(from, goingRight) + jitter;
            const gB = gutterBeside(to, !goingRight) + jitter;

            // Raias vizinhas: os dois lados caem no mesmo vão, então a rota
            // é sair, descer/subir nele e entrar — sem canal horizontal.
            if (Math.abs(gA - gB) < 1) {
                return pathOf([
                    [x1, from.cy], [gA, from.cy], [gA, to.cy], [x2, to.cy],
                ]);
            }

            const channel = pickChannel(gA, gB, (from.cy + to.cy) / 2) + jitter;

            return pathOf([
                [x1, from.cy], [gA, from.cy], [gA, channel],
                [gB, channel], [gB, to.cy], [x2, to.cy],
            ]);
        };

        // Camadas cujo traço assume a cor do card de DESTINO, para dar para
        // seguir o percurso e saber em que fase a OS entrou sem ler rótulo:
        // Triagem -> Aguardando Peça sai amarelo, a cor de "Em espera".
        //
        // A rota provável fica de fora de propósito: ela é estatística (medida
        // do histórico), e mantê-la azul fixa preserva a leitura de "o que
        // aconteceu" contra "o que costuma acontecer". Baixa e catálogo também
        // ficam fora — são regra e material de leitura, não etapas.
        //
        // DECISÃO EXPLÍCITA DO USUÁRIO (09/09/2026): usa a cor LITERAL do card,
        // sem escurecer nem contornar em tempo de desenho. Onde a cor não dava
        // conta como linha, quem mudou foi a PALETA: "Em espera" era #FFD400
        // (1.43:1 sobre o branco, sumia) e virou #B8860B. Ainda ficam abaixo de
        // 3:1 `orcamento`, `diagnostico` e `qualidade` — ver o docblock de
        // OrderStatusMacroGroups::flowAccent(). Não "corrigir" aqui: se alguma
        // cor precisar de ajuste, o ajuste é na paleta, para o traço continuar
        // idêntico ao card.
        const PHASE_COLORED = new Set(['traveled', 'next']);

        // Desenha uma aresta na camada [data-os-map-layer="edges"].
        // `kind` casa com as classes/markers definidos no partial do SVG.
        const drawEdge = (fromEl, toEl, kind, slot, title) => {
            if (!edgeLayer || !fromEl || !toEl || fromEl === toEl) return null;

            const from = boxOf(fromEl);
            const to = boxOf(toEl);
            if (!from || !to) return null;

            const path = document.createElementNS(SVG_NS, 'path');
            path.setAttribute('d', routeBetween(from, to, slot));
            path.setAttribute('fill', 'none');
            path.classList.add('os-map-edge', `is-${kind}`);

            // A cor sai do próprio nó de destino (data-cor), e não de uma
            // consulta por macrofase: assim a linha nunca diverge do card,
            // inclusive quando o usuário inventa um grupo_macro novo (o campo
            // é texto livre) e o card cai na cor padrão.
            const cor = PHASE_COLORED.has(kind) ? String(toEl.dataset?.cor || '').trim() : '';

            if (cor) {
                // Inline vence a cor da regra .os-map-edge.is-*, que continua
                // valendo como fallback (a porta da baixa não tem data-cor).
                path.style.stroke = cor;
                path.setAttribute(
                    'marker-end',
                    `url(#osMapArrowFase${kind === 'next' ? 'Next' : ''}-${cor.replace('#', '')})`
                );
            } else {
                path.setAttribute('marker-end', `url(#osMapArrow${kind.charAt(0).toUpperCase()}${kind.slice(1)})`);
            }

            if (title) {
                const t = document.createElementNS(SVG_NS, 'title');
                t.textContent = title;
                path.appendChild(t);
            }

            edgeLayer.appendChild(path);

            return path;
        };

        // ------------------------------------------------------------------
        // Rota provável MEDIDA. Antes era um Dijkstra sobre o catálogo
        // congelado `os_status_transicoes`, mirando um alvo fixo
        // (`reparo_concluido`) e terminando num destino fixo
        // (`entregue_reparado_pago`) — um "caminho feliz" declarado à mão em
        // 2026-07. Agora caminha pela frequência real das transições
        // (OrderFlowStatisticsService), que vem em config.flowStats.
        // ------------------------------------------------------------------
        const probableRoute = () => {
            const stats = state.flowStats || {};
            const freq = stats.transicoes || {};
            const minSample = Number(stats.amostra_minima || 3);
            const stop = new Set([
                ...(Array.isArray(stats.codigos_encerramento) ? stats.codigos_encerramento : []),
                ...(Array.isArray(stats.codigos_saida) ? stats.codigos_saida : []),
            ]);

            const hops = [];
            const visited = new Set([state.statusAtual]);
            let cursor = state.statusAtual;

            while (hops.length < ROUTE_MAX_STEPS) {
                const destinos = (freq[cursor] || {}).destinos || [];

                // Já ordenado por frequência no backend; pula quem já foi
                // visitado porque o histórico real tem ciclos de verdade
                // (retrabalho, reabertura, cancelar baixa).
                const next = destinos.find((d) => !visited.has(d.para) && d.n >= minSample);
                if (!next) break;

                hops.push({ de: cursor, para: next.para, n: next.n, pct: next.pct });
                visited.add(next.para);
                cursor = next.para;

                if (stop.has(cursor)) break;
            }

            return hops;
        };

        // Sem dado medido saindo da etapa atual, a sugestão cai para o
        // catálogo de transições (`proximas_etapas`) — congelado desde
        // 23/08/2026, mas melhor que nada quando o histórico é curto.
        const drawProbableRoute = () => {
            if (state.isEncerrada || !currentNode) return;

            const hops = probableRoute();
            let slot = 0;

            hops.forEach((hop) => {
                const fromEl = nodesByCode[hop.de];
                const toEl = nodesByCode[hop.para];
                drawEdge(fromEl, toEl, 'route', slot++, `rota provável: ${hop.pct}% das OS (${hop.n})`);
                if (toEl) toEl.classList.add('is-destination');
            });

            const last = hops.length > 0 ? hops[hops.length - 1].para : state.statusAtual;

            // A baixa parte de qualquer etapa aberta; o mapa mostra a porta
            // como continuação natural do fim da rota.
            if (portEl && !state.closureCodeSet.has(last)) {
                drawEdge(nodesByCode[last], portEl, 'baixa', 0, 'encerramento: só pela baixa da OS');
                portEl.classList.add('is-suggested');
            }
        };

        // Overlay do catálogo de transições (desligado por padrão). Mostra as
        // setas que existem em os_status_transicoes — úteis como leitura do
        // fluxo desenhado, mas congeladas: o editor da matriz saiu do desktop
        // em 23/08/2026 e o backend não valida transição desde 09/08/2026.
        const drawCatalogOverlay = () => {
            let slot = 0;
            state.transicoesCatalogo.forEach(({ de, para }) => {
                drawEdge(nodesByCode[de], nodesByCode[para], 'catalog', slot++, 'transição cadastrada no catálogo');
            });
        };

        const applyDecoration = () => {
            // 1. Trajeto percorrido — SEMPRE desenhado, inclusive quando o
            // salto não existe no catálogo de transições. Era exatamente esse
            // o bug de cronologia: a OS 3654 foi aguardando_reparo ->
            // reparo_concluido (salto que o backend aceita desde 09/08/2026,
            // mas que não está em os_status_transicoes) e o mapa não mostrava
            // linha nenhuma, porque procurava uma seta pré-desenhada.
            let slot = 0;
            state.path.forEach((hop) => {
                const de = String(hop.de || '');
                const para = String(hop.para || '');

                if (de && nodesByCode[de] && nodesByCode[para]) {
                    const quando = String(hop.em || '').slice(0, 10).split('-').reverse().join('/');
                    drawEdge(nodesByCode[de], nodesByCode[para], 'traveled', slot++, quando !== '' ? `percorrido em ${quando}` : 'percorrido');
                }

                if (de && nodesByCode[de]) nodesByCode[de].classList.add('is-visited');
                if (nodesByCode[para]) nodesByCode[para].classList.add('is-visited');
            });

            currentNode = nodesByCode[state.statusAtual] || null;

            if (!currentNode && state.statusAtual !== '') {
                // Todo status ATIVO tem card desde que o desenho passou a ser
                // gerado do catálogo; sobrar aqui significa status desativado
                // (ou legado) em que uma OS antiga ficou parada.
                showToast(`Status atual (${state.statusAtual}) não está mais no catálogo ativo — o mapa não tem card para ele.`, 'warning');
            }

            if (currentNode) {
                currentNode.classList.add('is-current');
                markHere(currentNode);
            }

            // 2. Rota provável, medida do histórico real.
            drawProbableRoute();

            // 3. Próximas etapas sugeridas pelo catálogo de transições, saindo
            // do nó atual. Continua sendo só destaque: qualquer status ativo
            // não-baixa pode ser escolhido (decisão de 09/08/2026).
            if (currentNode && !state.isEncerrada) {
                let nextSlot = 0;
                state.suggestedCodes.forEach((code) => {
                    const target = nodesByCode[code];
                    if (!target || code === state.statusAtual) return;
                    target.classList.add('is-destination');
                    drawEdge(currentNode, target, 'next', nextSlot++, 'próxima etapa sugerida pelo catálogo');
                });
            }

            if (state.canEditStatus && !state.isEncerrada) {
                state.clickableCodes.forEach((code) => nodesByCode[code]?.classList.add('is-clickable'));
            }

            // 4. Overlay opcional: o catálogo inteiro de transições. Fica
            // desligado por padrão — são sugestões congeladas desde que o
            // editor da matriz saiu do desktop (23/08/2026), não regra.
            if (state.showCatalog) drawCatalogOverlay();
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
                flowStats: data.flowStats,
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
        // Dimensões vêm do viewBox do SVG gerado — o desenho agora cresce ou
        // encolhe conforme o catálogo, então constantes fixas (eram 1780x1560,
        // do artefato Python) quebrariam o zoom/fit a cada mudança de status.
        const viewBox = (svg.getAttribute('viewBox') || '0 0 1780 1560').split(/\s+/).map(Number);
        const MAP_W = viewBox[2] || 1780;
        const MAP_H = viewBox[3] || 1560;
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

        // Liga/desliga o overlay do catálogo de transições. Fica desligado por
        // padrão: são as setas cadastradas em os_status_transicoes, congeladas
        // desde 23/08/2026 — leitura do fluxo desenhado, não do que a OS pode
        // fazer (o backend aceita qualquer status ativo não-baixa).
        const catalogBtn = root.querySelector('[data-os-map="toggle-catalog"]');
        catalogBtn?.addEventListener('click', () => {
            state.showCatalog = !state.showCatalog;
            catalogBtn.classList.toggle('is-active', state.showCatalog);
            catalogBtn.setAttribute('aria-pressed', state.showCatalog ? 'true' : 'false');
            redecorate();
        });

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
