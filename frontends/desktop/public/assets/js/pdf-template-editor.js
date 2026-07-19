/**
 * Editor de blocos do motor central de templates PDF.
 *
 * Estado = um único objeto schema (pagina/cabecalho/corpo/rodape) espelhando
 * o schema_json do backend. Nada de código livre: cada bloco expõe campos
 * estruturados; a validação dura acontece no publish (backend, allowlist de
 * variáveis) — aqui só montamos o JSON e damos feedback.
 */
(function () {
    'use strict';

    const config = window.__PDF_TEMPLATE_EDITOR;
    if (!config || !config.templateId) return;

    const AREAS = ['cabecalho', 'corpo', 'rodape'];

    // Catálogo de blocos: rótulo + campos estruturados do formulário.
    // kind: text | textarea | number | checkbox | select | json
    const BLOCK_DEFS = {
        titulo: { label: 'Título', fields: [
            { key: 'texto', label: 'Texto', kind: 'textarea' },
            { key: 'alinhamento', label: 'Alinhamento', kind: 'select', options: ['esquerda', 'centro', 'direita'] },
        ] },
        subtitulo: { label: 'Subtítulo', fields: [
            { key: 'texto', label: 'Texto', kind: 'textarea' },
            { key: 'alinhamento', label: 'Alinhamento', kind: 'select', options: ['esquerda', 'centro', 'direita'] },
        ] },
        cabecalho_secao: { label: 'Cabeçalho de seção', fields: [
            { key: 'texto', label: 'Texto', kind: 'text' },
        ] },
        paragrafo: { label: 'Parágrafo', fields: [
            { key: 'texto', label: 'Texto', kind: 'textarea' },
            { key: 'alinhamento', label: 'Alinhamento', kind: 'select', options: ['esquerda', 'centro', 'direita'] },
        ] },
        texto_rico: { label: 'Texto formatado (HTML restrito)', fields: [
            { key: 'html', label: 'HTML (sanitizado no servidor: sem scripts, links ou imagens externas)', kind: 'textarea', rows: 10 },
        ] },
        campo: { label: 'Campo rótulo → valor', fields: [
            { key: 'rotulo', label: 'Rótulo', kind: 'text' },
            { key: 'valor', label: 'Valor', kind: 'text' },
        ] },
        grade_campos: { label: 'Grade de campos', fields: [
            { key: 'colunas', label: 'Pares por linha (1-4)', kind: 'number', min: 1, max: 4 },
            { key: 'campos', label: 'Campos (lista JSON de {"rotulo","valor"})', kind: 'json', rows: 8 },
        ] },
        colunas: { label: 'Colunas (até 3)', fields: [
            { key: 'larguras', label: 'Larguras percentuais (JSON; devem totalizar 100)', kind: 'json', rows: 2 },
            { key: 'colunas', label: 'Colunas (JSON: de 1 a 3 listas de blocos)', kind: 'json', rows: 12 },
        ] },
        tabela: { label: 'Tabela de dados', fields: [
            { key: 'fonte', label: 'Fonte de dados (coleção; vazio = linhas estáticas)', kind: 'select', options: [''], dynamicOptions: 'colecoes' },
            { key: 'colunas', label: 'Colunas (JSON de {"campo","rotulo","formato","alinhamento","largura"})', kind: 'json', rows: 6 },
            { key: 'linhas_estaticas', label: 'Linhas estáticas (JSON: lista de listas de células; só sem fonte)', kind: 'json', rows: 4 },
            { key: 'totais', label: 'Totais (JSON de {"rotulo","variavel","formato","destaque"})', kind: 'json', rows: 3 },
            { key: 'vazio_texto', label: 'Texto quando vazio', kind: 'text' },
            { key: 'repetir_cabecalho', label: 'Repetir cabeçalho em novas páginas', kind: 'checkbox' },
        ] },
        tabela_totais: { label: 'Tabela de totais', fields: [
            { key: 'linhas', label: 'Linhas (JSON de {"rotulo","variavel","formato","destaque"})', kind: 'json', rows: 5 },
        ] },
        lista: { label: 'Lista', fields: [
            { key: 'fonte', label: 'Fonte de dados (coleção; vazio = itens estáticos)', kind: 'select', options: [''], dynamicOptions: 'colecoes' },
            { key: 'campo', label: 'Campo da coleção', kind: 'text' },
            { key: 'itens_estaticos', label: 'Itens estáticos (JSON: lista de textos)', kind: 'json', rows: 4 },
            { key: 'estilo', label: 'Estilo', kind: 'select', options: ['topicos', 'numerada'] },
            { key: 'vazio_texto', label: 'Texto quando vazio', kind: 'text' },
        ] },
        imagem: { label: 'Imagem autorizada', fields: [
            { key: 'token', label: 'Token interno', kind: 'select', options: ['((logo_empresa))', '((foto_equipamento_principal))'], dynamicOptions: 'tokens_imagem' },
            { key: 'largura_max', label: 'Largura máxima (px)', kind: 'number', min: 24, max: 400 },
            { key: 'alinhamento', label: 'Alinhamento', kind: 'select', options: ['esquerda', 'centro', 'direita'] },
        ] },
        fotos_entrada: { label: 'Galeria de fotos de entrada (até 4)', fields: [] },
        divisor: { label: 'Linha divisória', fields: [
            { key: 'espessura', label: 'Espessura (px)', kind: 'number', min: 1, max: 6 },
        ] },
        espacador: { label: 'Espaçamento', fields: [
            { key: 'altura_mm', label: 'Altura (mm)', kind: 'number', min: 1, max: 60 },
        ] },
        assinatura: { label: 'Área de assinatura', fields: [
            {
                key: 'rotulos',
                index: 0,
                label: 'Assinatura do responsável (técnico ou usuário emissor)',
                kind: 'signature_label',
                placeholder: '{{ os.tecnico_nome }} - Técnico responsável',
                help: 'Use {{ documento.usuario }} - Responsável pela emissão quando não houver técnico vinculado.',
            },
            {
                key: 'rotulos',
                index: 1,
                label: 'Assinatura do cliente',
                kind: 'signature_label',
                placeholder: '{{ cliente.nome }} - Cliente',
            },
            { key: 'linha_data', label: 'Incluir linha de data', kind: 'checkbox' },
        ] },
        observacoes: { label: 'Caixa de observações', fields: [
            { key: 'texto', label: 'Texto', kind: 'textarea' },
        ] },
        quebra_pagina: { label: 'Quebra de página', fields: [] },
        condicional: { label: 'Bloco condicional', fields: [
            { key: 'se', label: 'Condição (JSON: {"variavel","operador","valor"})', kind: 'json', rows: 3 },
            { key: 'blocos', label: 'Blocos internos (JSON: lista de blocos)', kind: 'json', rows: 8 },
        ] },
    };

    const state = {
        schema: config.schema || { pagina: {}, cabecalho: [], corpo: [], rodape: [] },
        draftUpdatedAt: config.draftUpdatedAt || null,
        selected: null, // { area, index }
        lastFocusedInput: null,
        busy: false,
    };

    AREAS.forEach((area) => {
        if (!Array.isArray(state.schema[area])) state.schema[area] = [];
    });

    const statusEl = document.getElementById('pdfeStatus');
    const configPanel = document.getElementById('pdfeConfigPanel');
    const configHint = document.getElementById('pdfeConfigHint');
    const previewFrame = document.getElementById('pdfePreviewFrame');

    function showStatus(kind, message, detalhes) {
        if (!statusEl) return;
        statusEl.className = 'alert mb-3 alert-' + (kind === 'ok' ? 'success' : kind === 'warn' ? 'warning' : 'danger');
        statusEl.textContent = message;
        if (Array.isArray(detalhes) && detalhes.length > 0) {
            const list = document.createElement('ul');
            list.className = 'mb-0 mt-2';
            detalhes.forEach((erro) => {
                const item = document.createElement('li');
                item.textContent = String(erro);
                list.appendChild(item);
            });
            statusEl.appendChild(list);
        }
        statusEl.classList.remove('d-none');
        statusEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function blockSummary(block) {
        const def = BLOCK_DEFS[block.tipo] || { label: block.tipo };
        const signatureLabels = block.tipo === 'assinatura' && Array.isArray(block.rotulos)
            ? block.rotulos.filter(Boolean).join(' • ')
            : '';
        const text = String(signatureLabels || block.texto || block.rotulo || block.html || block.fonte || '').replace(/\s+/g, ' ').trim();
        return { title: def.label, hint: text.length > 64 ? text.slice(0, 64) + '…' : text };
    }

    function renderAreas() {
        AREAS.forEach((area) => {
            const container = document.querySelector('[data-pdfe-area="' + area + '"]');
            if (!container) return;
            container.innerHTML = '';

            const blocks = state.schema[area];
            if (blocks.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'list-group-item text-secondary';
                empty.textContent = 'Nenhum bloco nesta área.';
                container.appendChild(empty);
                return;
            }

            blocks.forEach((block, index) => {
                const summary = blockSummary(block);
                const item = document.createElement('div');
                const isSelected = state.selected && state.selected.area === area && state.selected.index === index;
                item.className = 'list-group-item d-flex justify-content-between align-items-center gap-2' + (isSelected ? ' active' : '');

                const label = document.createElement('button');
                label.type = 'button';
                label.className = 'btn btn-link text-start flex-grow-1 p-0 text-decoration-none' + (isSelected ? ' text-white' : '');
                label.innerHTML = '<strong>' + summary.title + '</strong>'
                    + (summary.hint ? ' <small class="' + (isSelected ? 'text-white-50' : 'text-secondary') + '">' + escapeHtml(summary.hint) + '</small>' : '')
                    + (Array.isArray(block.visivel_em) && block.visivel_em.length === 1
                        ? ' <span class="badge text-bg-info">' + block.visivel_em[0] + '</span>'
                        : '');
                label.addEventListener('click', () => selectBlock(area, index));
                item.appendChild(label);

                if (config.canEdit) {
                    const actions = document.createElement('div');
                    actions.className = 'btn-group btn-group-sm';
                    actions.appendChild(iconButton('bi-arrow-up', 'Mover para cima', () => moveBlock(area, index, -1)));
                    actions.appendChild(iconButton('bi-arrow-down', 'Mover para baixo', () => moveBlock(area, index, 1)));
                    actions.appendChild(iconButton('bi-trash', 'Remover bloco', () => removeBlock(area, index)));
                    item.appendChild(actions);
                }

                container.appendChild(item);
            });
        });
    }

    function iconButton(icon, title, onClick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-soft';
        button.title = title;
        button.innerHTML = '<i class="bi ' + icon + '"></i>';
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            onClick();
        });
        return button;
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    }

    function cleanHeadingMarkup(value) {
        return String(value ?? '')
            .replace(/^\s*#{1,6}\s*/, '')
            .replace(/^\*\*(.+)\*\*$/, '$1')
            .trim();
    }

    function isUppercaseHeading(value) {
        const text = cleanHeadingMarkup(value);
        const letters = text.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ]/g, '');

        return text.length >= 3
            && text.length <= 160
            && letters.length >= 3
            && text === text.toLocaleUpperCase('pt-BR');
    }

    /**
     * Converte texto jurídico/comercial colado em blocos declarativos seguros.
     * Nenhum HTML é criado: o backend continua responsável por escapar a saída.
     */
    function buildStructuredBlocks(rawText) {
        const normalized = String(rawText ?? '')
            .replace(/\r\n?/g, '\n')
            .replace(/\u00a0/g, ' ')
            .replace(/[ \t]+$/gm, '')
            .trim();

        if (normalized === '') return [];

        const blocks = [];
        let paragraphLines = [];
        let listItems = [];
        let listStyle = 'topicos';

        const flushParagraph = () => {
            const text = paragraphLines.join(' ').replace(/\s+/g, ' ').trim();
            if (text !== '') {
                blocks.push({ tipo: 'paragrafo', texto: text, alinhamento: 'esquerda' });
            }
            paragraphLines = [];
        };

        const flushList = () => {
            if (listItems.length > 0) {
                blocks.push({ tipo: 'lista', itens_estaticos: listItems, estilo: listStyle });
            }
            listItems = [];
            listStyle = 'topicos';
        };

        normalized.split('\n').forEach((rawLine) => {
            const line = rawLine.trim();

            if (line === '') {
                flushParagraph();
                flushList();
                return;
            }

            const cleanLine = cleanHeadingMarkup(line);
            const numberedSection = cleanLine.match(/^(\d{1,2})[.)]\s+(.+)$/u);
            if (numberedSection && isUppercaseHeading(numberedSection[2])) {
                flushParagraph();
                flushList();
                blocks.push({ tipo: 'cabecalho_secao', texto: cleanLine });
                return;
            }

            if (isUppercaseHeading(cleanLine)) {
                flushParagraph();
                flushList();
                blocks.push(blocks.length === 0
                    ? { tipo: 'subtitulo', texto: cleanLine, alinhamento: 'esquerda' }
                    : { tipo: 'cabecalho_secao', texto: cleanLine });
                return;
            }

            const listItem = cleanLine.match(/^(?:(\d+)[.)]|([A-Za-z])[)]|[-•▪])\s+(.+)$/u);
            if (listItem) {
                flushParagraph();
                const nextStyle = listItem[1] ? 'numerada' : 'topicos';
                if (listItems.length > 0 && listStyle !== nextStyle) flushList();
                listStyle = nextStyle;
                listItems.push(listItem[3].trim());
                return;
            }

            flushList();
            paragraphLines.push(cleanLine);
        });

        flushParagraph();
        flushList();

        return blocks;
    }

    function appendParagraphOrganizer(block, area, index) {
        const helper = document.createElement('div');
        helper.className = 'border rounded-3 bg-light p-3';

        const title = document.createElement('div');
        title.className = 'fw-bold mb-1';
        title.textContent = 'Texto longo colado?';
        helper.appendChild(title);

        const description = document.createElement('p');
        description.className = 'small text-secondary mb-2';
        description.textContent = 'Separe automaticamente títulos, seções numeradas, parágrafos e listas.';
        helper.appendChild(description);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline-primary btn-sm';
        button.innerHTML = '<i class="bi bi-magic me-1"></i> Organizar texto automaticamente';
        button.disabled = !config.canEdit;
        button.addEventListener('click', () => {
            const structured = buildStructuredBlocks(block.texto);
            if (structured.length <= 1) {
                showStatus('warn', 'Não foram encontrados títulos, listas ou parágrafos separados. Mantenha uma linha em branco entre os parágrafos.');
                return;
            }

            const currentTotal = AREAS.reduce((total, key) => total + state.schema[key].length, 0);
            if (currentTotal - 1 + structured.length > 120) {
                showStatus('warn', 'O texto produziria mais de 120 blocos. Reduza ou agrupe o conteúdo antes de organizar.');
                return;
            }

            state.schema[area].splice(index, 1, ...structured);
            state.selected = { area, index };
            renderAreas();
            renderConfigPanel();
            showStatus('ok', 'Texto organizado em ' + structured.length + ' blocos. Revise a estrutura e atualize a prévia antes de publicar.');
        });
        helper.appendChild(button);
        configPanel.appendChild(helper);
    }

    function selectBlock(area, index) {
        state.selected = { area, index };
        renderAreas();
        renderConfigPanel();
    }

    function moveBlock(area, index, delta) {
        const blocks = state.schema[area];
        const target = index + delta;
        if (target < 0 || target >= blocks.length) return;
        const [moved] = blocks.splice(index, 1);
        blocks.splice(target, 0, moved);
        if (state.selected && state.selected.area === area && state.selected.index === index) {
            state.selected.index = target;
        }
        renderAreas();
    }

    function removeBlock(area, index) {
        if (!window.confirm('Remover este bloco?')) return;
        state.schema[area].splice(index, 1);
        if (state.selected && state.selected.area === area) state.selected = null;
        renderAreas();
        renderConfigPanel();
    }

    function addBlock(area, tipo) {
        if (!BLOCK_DEFS[tipo]) return;
        const block = { tipo };
        if (tipo === 'grade_campos') { block.colunas = 2; block.campos = [{ rotulo: 'Rótulo', valor: 'Valor' }]; }
        if (tipo === 'colunas') { block.larguras = [50, 50]; block.colunas = [[], []]; }
        if (tipo === 'tabela') { block.colunas = [{ campo: 'descricao', rotulo: 'Descrição' }]; block.repetir_cabecalho = true; }
        if (tipo === 'condicional') { block.se = { variavel: '', operador: 'preenchido' }; block.blocos = []; }
        if (tipo === 'fotos_entrada') { block.visivel_em = ['a4']; }
        if (tipo === 'assinatura') {
            block.rotulos = [
                '{{ os.tecnico_nome }} - Técnico responsável',
                '{{ cliente.nome }} - Cliente',
            ];
            block.linha_data = true;
            block.visivel_em = ['a4'];
        }
        state.schema[area].push(block);
        selectBlock(area, state.schema[area].length - 1);
    }

    function renderConfigPanel() {
        if (!configPanel) return;
        configPanel.innerHTML = '';

        if (!state.selected) {
            if (configHint) configHint.textContent = 'Selecione um bloco na estrutura ao lado.';
            return;
        }

        const { area, index } = state.selected;
        const block = state.schema[area][index];
        if (!block) return;

        const def = BLOCK_DEFS[block.tipo] || { label: block.tipo, fields: [] };
        if (configHint) configHint.textContent = def.label + ' — área ' + area + ', posição ' + (index + 1) + '.';

        def.fields.forEach((field) => {
            configPanel.appendChild(buildField(block, field));
        });

        if (block.tipo === 'paragrafo') {
            appendParagraphOrganizer(block, area, index);
        }

        // Visibilidade por formato — comum a todos os blocos.
        const visibility = document.createElement('div');
        visibility.innerHTML = '<label class="form-label fw-semibold">Visível em</label>';
        const select = document.createElement('select');
        select.className = 'form-select';
        select.dataset.select2 = 'false';
        [['ambos', 'A4 e 80mm'], ['a4', 'Somente A4'], ['80mm', 'Somente 80mm']].forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            select.appendChild(option);
        });
        const current = Array.isArray(block.visivel_em) && block.visivel_em.length === 1 ? block.visivel_em[0] : 'ambos';
        select.value = current;
        select.disabled = !config.canEdit;
        select.addEventListener('change', () => {
            if (select.value === 'ambos') delete block.visivel_em;
            else block.visivel_em = [select.value];
            renderAreas();
        });
        visibility.appendChild(select);
        configPanel.appendChild(visibility);
    }

    function buildField(block, field) {
        const wrapper = document.createElement('div');
        const label = document.createElement('label');
        label.className = 'form-label fw-semibold';
        label.textContent = field.label;
        wrapper.appendChild(label);

        let input;

        if (field.kind === 'select') {
            input = document.createElement('select');
            input.className = 'form-select';
            input.dataset.select2 = 'false';
            let options = field.options || [];
            if (field.dynamicOptions === 'colecoes') {
                options = [''].concat(((config.metadata || {}).colecoes || []).map((colecao) => colecao.nome));
            }
            if (field.dynamicOptions === 'tokens_imagem') {
                options = ((config.metadata || {}).tokens_imagem || ['logo_empresa', 'foto_equipamento_principal'])
                    .map((token) => '((' + String(token).replace(/^\(\(|\)\)$/g, '') + '))');
            }
            options.forEach((optionValue) => {
                const option = document.createElement('option');
                option.value = optionValue;
                option.textContent = optionValue === '' ? '(nenhuma)' : optionValue;
                input.appendChild(option);
            });
            input.value = String(block[field.key] ?? '');
            input.addEventListener('change', () => {
                if (input.value === '') delete block[field.key];
                else block[field.key] = input.value;
                renderAreas();
            });
        } else if (field.kind === 'signature_label') {
            input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control';
            input.placeholder = field.placeholder || '';

            const index = Number(field.index || 0);
            const currentLabels = Array.isArray(block[field.key]) ? block[field.key] : [];
            input.value = String(currentLabels[index] ?? '');
            input.addEventListener('input', () => {
                const labels = Array.isArray(block[field.key])
                    ? block[field.key].slice(0, 2).map((value) => String(value ?? ''))
                    : [];
                while (labels.length <= index) labels.push('');
                labels[index] = input.value;
                while (labels.length > 0 && labels[labels.length - 1].trim() === '') labels.pop();
                if (labels.length === 0) delete block[field.key];
                else block[field.key] = labels;
            });
            input.addEventListener('change', renderAreas);
        } else if (field.kind === 'checkbox') {
            wrapper.classList.add('form-check', 'form-switch');
            input = document.createElement('input');
            input.type = 'checkbox';
            input.className = 'form-check-input';
            input.checked = Boolean(block[field.key]);
            input.addEventListener('change', () => { block[field.key] = input.checked; });
            label.classList.add('form-check-label');
            wrapper.insertBefore(input, label);
        } else if (field.kind === 'json') {
            input = document.createElement('textarea');
            input.className = 'form-control font-monospace';
            input.rows = field.rows || 5;
            input.value = block[field.key] !== undefined ? JSON.stringify(block[field.key], null, 2) : '';
            input.addEventListener('change', () => {
                const raw = input.value.trim();
                if (raw === '') { delete block[field.key]; input.classList.remove('is-invalid'); renderAreas(); return; }
                try {
                    block[field.key] = JSON.parse(raw);
                    input.classList.remove('is-invalid');
                    renderAreas();
                } catch (error) {
                    input.classList.add('is-invalid');
                    showStatus('warn', 'JSON inválido no campo "' + field.label + '" — a alteração não foi aplicada.');
                }
            });
        } else if (field.kind === 'number') {
            input = document.createElement('input');
            input.type = 'number';
            input.className = 'form-control';
            if (field.min !== undefined) input.min = field.min;
            if (field.max !== undefined) input.max = field.max;
            input.value = block[field.key] ?? '';
            input.addEventListener('change', () => {
                if (input.value === '') delete block[field.key];
                else block[field.key] = Number(input.value);
            });
        } else if (field.kind === 'textarea') {
            input = document.createElement('textarea');
            input.className = 'form-control';
            input.rows = field.rows || 3;
            input.value = String(block[field.key] ?? '');
            input.addEventListener('input', () => { block[field.key] = input.value; });
            input.addEventListener('change', renderAreas);
        } else {
            input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control';
            input.value = String(block[field.key] ?? '');
            input.addEventListener('input', () => { block[field.key] = input.value; });
            input.addEventListener('change', renderAreas);
        }

        input.disabled = !config.canEdit;
        input.addEventListener('focus', () => { state.lastFocusedInput = input; });
        wrapper.appendChild(input);

        if (field.help) {
            const help = document.createElement('div');
            help.className = 'form-text';
            help.textContent = field.help;
            wrapper.appendChild(help);
        }

        return wrapper;
    }

    // ----- Seletores de "adicionar bloco" por área -----
    AREAS.forEach((area) => {
        const select = document.querySelector('[data-pdfe-add-select="' + area + '"]');
        const button = document.querySelector('[data-pdfe-add-btn="' + area + '"]');
        if (!select || !button) return;

        Object.entries(BLOCK_DEFS).forEach(([tipo, def]) => {
            const option = document.createElement('option');
            option.value = tipo;
            option.textContent = def.label;
            select.appendChild(option);
        });

        button.addEventListener('click', () => addBlock(area, select.value));
    });

    // ----- Variáveis -----
    const variablePicker = document.getElementById('pdfeVariablePicker');
    if (variablePicker) {
        (((config.metadata || {}).variaveis) || []).forEach((variavel) => {
            const option = document.createElement('option');
            option.value = variavel.caminho;
            option.textContent = variavel.caminho + ' (' + variavel.tipo + ')';
            variablePicker.appendChild(option);
        });
    }

    const variableInsert = document.getElementById('pdfeVariableInsert');
    if (variableInsert) {
        variableInsert.addEventListener('click', () => {
            const input = state.lastFocusedInput;
            const token = '{{ ' + (variablePicker ? variablePicker.value : '') + ' }}';
            if (!input || input.disabled || !('value' in input)) {
                showStatus('warn', 'Clique primeiro no campo de texto onde a variável deve entrar.');
                return;
            }
            const start = input.selectionStart ?? input.value.length;
            const end = input.selectionEnd ?? input.value.length;
            input.value = input.value.slice(0, start) + token + input.value.slice(end);
            input.dispatchEvent(new Event('input'));
            input.dispatchEvent(new Event('change'));
            input.focus();
        });
    }

    // ----- Ações -----
    async function requestJson(url, method, payload) {
        const response = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': config.csrf,
            },
            body: payload ? JSON.stringify(payload) : undefined,
        });
        const data = await response.json().catch(() => ({}));
        return { status: response.status, data };
    }

    function extractErrors(data) {
        const detalhes = (data && (data.detalhes || (data.error && data.error.details))) || {};
        return detalhes.erros || detalhes['erros'] || [];
    }

    const saveButton = document.getElementById('pdfeBtnSave');
    if (saveButton) {
        saveButton.addEventListener('click', async () => {
            if (state.busy) return;
            state.busy = true;
            saveButton.disabled = true;
            try {
                const { status, data } = await requestJson(config.routes.draft, 'PUT', {
                    schema: state.schema,
                    updated_at: state.draftUpdatedAt,
                });

                if (status === 200 && data.success) {
                    state.draftUpdatedAt = (data.rascunho && data.rascunho.updated_at) || state.draftUpdatedAt;
                    showStatus('ok', 'Rascunho salvo. Publique quando quiser que novas emissões usem esta versão.');
                } else if (status === 409) {
                    showStatus('error', 'Outra pessoa alterou este rascunho enquanto você editava. Recarregue a página para continuar (suas alterações locais serão perdidas).');
                } else {
                    showStatus('error', (data && data.message) || 'Falha ao salvar o rascunho.', extractErrors(data));
                }
            } catch (error) {
                showStatus('error', 'Falha de comunicação ao salvar o rascunho.');
            } finally {
                state.busy = false;
                saveButton.disabled = false;
            }
        });
    }

    const publishButton = document.getElementById('pdfeBtnPublish');
    if (publishButton) {
        publishButton.addEventListener('click', async () => {
            if (state.busy) return;
            if (!window.confirm('Publicar o rascunho atual? Novas emissões passarão a usar esta versão imediatamente.')) return;
            state.busy = true;
            publishButton.disabled = true;
            try {
                // Publica sempre o que está na tela: salva o rascunho antes.
                const save = await requestJson(config.routes.draft, 'PUT', {
                    schema: state.schema,
                    updated_at: state.draftUpdatedAt,
                });
                if (save.status === 409) {
                    showStatus('error', 'Conflito de edição: recarregue a página antes de publicar.');
                    return;
                }
                if (save.status !== 200 || !save.data.success) {
                    showStatus('error', (save.data && save.data.message) || 'Falha ao salvar antes de publicar.', extractErrors(save.data));
                    return;
                }
                state.draftUpdatedAt = (save.data.rascunho && save.data.rascunho.updated_at) || state.draftUpdatedAt;

                const { status, data } = await requestJson(config.routes.publish, 'POST');
                if (status === 200 && data.success) {
                    showStatus('ok', 'Template publicado. Recarregando…');
                    window.setTimeout(() => window.location.reload(), 900);
                } else {
                    showStatus('error', (data && data.message) || 'Falha ao publicar.', extractErrors(data));
                }
            } catch (error) {
                showStatus('error', 'Falha de comunicação ao publicar.');
            } finally {
                state.busy = false;
                publishButton.disabled = false;
            }
        });
    }

    async function loadPreview(payload) {
        if (!previewFrame) return;
        try {
            const response = await fetch(config.routes.preview, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/pdf, application/json',
                    'X-CSRF-TOKEN': config.csrf,
                },
                body: JSON.stringify(payload),
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                showStatus('error', (data && data.message) || 'Falha ao gerar a prévia.', extractErrors(data));
                return;
            }

            const blob = await response.blob();
            previewFrame.src = URL.createObjectURL(blob);
            showStatus('ok', 'Prévia atualizada.');
        } catch (error) {
            showStatus('error', 'Falha de comunicação ao gerar a prévia.');
        }
    }

    function previewPayloadBase() {
        const formato = document.getElementById('pdfePreviewFormat');
        const entidade = document.getElementById('pdfePreviewEntity');
        const payload = { formato: formato ? formato.value : 'a4' };
        const entidadeId = entidade && entidade.value !== '' ? parseInt(entidade.value, 10) : 0;
        if (entidadeId > 0) payload.entidade_id = entidadeId;
        return payload;
    }

    const previewButton = document.getElementById('pdfeBtnPreview');
    if (previewButton) {
        previewButton.addEventListener('click', () => {
            loadPreview(Object.assign(previewPayloadBase(), { schema: state.schema }));
        });
    }

    document.querySelectorAll('[data-pdfe-preview-version]').forEach((button) => {
        button.addEventListener('click', () => {
            loadPreview(Object.assign(previewPayloadBase(), { versao: parseInt(button.dataset.pdfePreviewVersion, 10) }));
        });
    });

    document.querySelectorAll('[data-pdfe-restore]').forEach((button) => {
        button.addEventListener('click', async () => {
            const versao = parseInt(button.dataset.pdfeRestore, 10);
            if (!window.confirm('Restaurar a v' + versao + ' como novo rascunho?')) return;
            const { status, data } = await requestJson(config.routes.restoreBase + '/' + versao + '/restaurar', 'POST');
            if (status === 200 && data.success) {
                showStatus('ok', 'Versão restaurada como rascunho. Recarregando…');
                window.setTimeout(() => window.location.reload(), 900);
            } else {
                showStatus('error', (data && data.message) || 'Falha ao restaurar a versão.');
            }
        });
    });

    renderAreas();
    renderConfigPanel();
})();
