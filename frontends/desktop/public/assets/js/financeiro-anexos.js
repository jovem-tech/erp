(function () {
    // Habilita o botão de enviar só depois que um arquivo é escolhido — mesma
    // lógica do form de anexo fiscal (fiscal/nota.blade.php). Cobre tanto o
    // modal da tela de detalhe (financeiro/show.blade.php) quanto o modal
    // único e compartilhado da listagem (financeiro/_quick_anexo_modal.blade.php).
    document.querySelectorAll('[data-form-anexo-financeiro]').forEach((form) => {
        const campo = form.querySelector('[data-input-anexo-financeiro]');
        const botao = form.querySelector('[data-botao-anexo-financeiro]');

        campo?.addEventListener('change', function () {
            if (botao instanceof HTMLButtonElement) {
                botao.disabled = this.files.length === 0;
            }
        });
    });

    // Modal único da listagem: cada linha só passa data-financeiro-id/
    // data-financeiro-descricao no botão que o abre — a action real é
    // montada aqui a partir do template de URL (route() só existe no
    // servidor, o id da linha só é conhecido no clique). Não existe na
    // página de detalhe — daí o `if`, em vez de um early-return que
    // impediria o bloco do preview (abaixo) de rodar ali.
    const quickModal = document.getElementById('anexoQuickModal');

    if (quickModal) {
        const form = quickModal.querySelector('[data-form-anexo-financeiro]');
        const titleEl = quickModal.querySelector('[data-anexo-quick-title]');
        const campo = quickModal.querySelector('[data-input-anexo-financeiro]');
        const botao = quickModal.querySelector('[data-botao-anexo-financeiro]');
        const urlTemplate = quickModal.dataset.urlTemplate || '';

        quickModal.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget;
            const financeiroId = trigger?.dataset.financeiroId || '';
            const financeiroDescricao = trigger?.dataset.financeiroDescricao || 'Anexar arquivo';

            if (form instanceof HTMLFormElement && financeiroId !== '') {
                form.action = urlTemplate.replace('__FINANCEIRO_ID__', financeiroId);
            }

            if (titleEl instanceof HTMLElement) {
                titleEl.textContent = financeiroDescricao;
            }
        });

        quickModal.addEventListener('hidden.bs.modal', () => {
            if (form instanceof HTMLFormElement) {
                form.reset();
            }

            if (campo instanceof HTMLInputElement) {
                campo.value = '';
            }

            if (botao instanceof HTMLButtonElement) {
                botao.disabled = true;
            }
        });
    }

    // Modal de "Ver anexos" da listagem: busca a lista por fetch() (rota
    // financeiro.anexos.index) e monta as linhas — evita ter que abrir
    // "Detalhes" só para conferir o que já está anexado. Cada linha aqui
    // ganha um botão-olho com os mesmos data-anexo-* do preview (abaixo);
    // como o listener do preview escuta o modal, não o gatilho, funciona
    // igual mesmo em botões criados depois do carregamento da página —
    // e a API de dados do Bootstrap (data-bs-toggle) também é delegada,
    // então não precisa de wiring extra aqui.
    const listModal = document.getElementById('anexosListModal');

    if (listModal) {
        const titleEl = listModal.querySelector('[data-anexos-list-title]');
        const bodyEl = listModal.querySelector('[data-anexos-list-body]');
        const urlTemplate = listModal.dataset.urlTemplate || '';

        const renderCarregando = () => {
            if (!(bodyEl instanceof HTMLElement)) return;
            bodyEl.innerHTML = '';
            const p = document.createElement('p');
            p.className = 'text-secondary mb-0';
            p.textContent = 'Carregando...';
            bodyEl.appendChild(p);
        };

        const renderErro = () => {
            if (!(bodyEl instanceof HTMLElement)) return;
            bodyEl.innerHTML = '';
            const p = document.createElement('p');
            p.className = 'text-danger mb-0';
            p.textContent = 'Não foi possível carregar os anexos agora. Tente novamente.';
            bodyEl.appendChild(p);
        };

        const renderVazio = () => {
            if (!(bodyEl instanceof HTMLElement)) return;
            bodyEl.innerHTML = '';
            const p = document.createElement('p');
            p.className = 'text-secondary mb-0';
            p.textContent = 'Nenhum arquivo anexado a este lançamento ainda.';
            bodyEl.appendChild(p);
        };

        const renderLista = (anexos) => {
            if (!(bodyEl instanceof HTMLElement)) return;
            bodyEl.innerHTML = '';

            if (anexos.length === 0) {
                renderVazio();
                return;
            }

            const ul = document.createElement('ul');
            ul.className = 'list-unstyled mb-0';

            anexos.forEach((anexo) => {
                const li = document.createElement('li');
                li.className = 'd-flex justify-content-between align-items-center py-2 border-bottom';

                const info = document.createElement('div');
                const nomeEl = document.createElement('span');
                nomeEl.className = 'fw-semibold';
                const icone = document.createElement('i');
                icone.className = 'bi bi-file-earmark-text me-1';
                nomeEl.appendChild(icone);
                nomeEl.appendChild(document.createTextNode(anexo.nome || 'Arquivo'));
                const metaEl = document.createElement('div');
                metaEl.className = 'small text-secondary';
                metaEl.textContent = anexo.tamanho_kb + ' KB · ' + anexo.uploaded_by + ' · ' + anexo.data;
                info.appendChild(nomeEl);
                if (anexo.management_status === 'pending') {
                    const statusEl = document.createElement('span');
                    statusEl.className = 'badge text-bg-warning ms-2';
                    statusEl.textContent = 'Sincronização pendente';
                    statusEl.title = 'O arquivo está seguro e será integrado automaticamente ao Gerenciador.';
                    nomeEl.appendChild(statusEl);
                } else if (anexo.management_status === 'integrity_error') {
                    const statusEl = document.createElement('span');
                    statusEl.className = 'badge text-bg-danger ms-2';
                    statusEl.textContent = 'Verificação necessária';
                    nomeEl.appendChild(statusEl);
                }
                info.appendChild(metaEl);

                const acoes = document.createElement('div');
                acoes.className = 'd-flex gap-2';

                const verBtn = document.createElement('button');
                verBtn.type = 'button';
                verBtn.className = 'btn btn-sm btn-outline-light';
                verBtn.title = 'Visualizar';
                verBtn.setAttribute('data-bs-toggle', 'modal');
                verBtn.setAttribute('data-bs-target', '#anexoPreviewModal');
                verBtn.dataset.anexoUrl = anexo.url;
                verBtn.dataset.anexoMime = anexo.mime;
                verBtn.dataset.anexoNome = anexo.nome;
                const olhoIcone = document.createElement('i');
                olhoIcone.className = 'bi bi-eye';
                verBtn.appendChild(olhoIcone);

                const abrirLink = document.createElement('a');
                abrirLink.href = anexo.url;
                abrirLink.target = '_blank';
                abrirLink.rel = 'noreferrer';
                abrirLink.className = 'btn btn-sm btn-outline-light';
                abrirLink.title = 'Abrir em nova aba';
                const externoIcone = document.createElement('i');
                externoIcone.className = 'bi bi-box-arrow-up-right';
                abrirLink.appendChild(externoIcone);

                acoes.appendChild(verBtn);
                acoes.appendChild(abrirLink);

                li.appendChild(info);
                li.appendChild(acoes);
                ul.appendChild(li);
            });

            bodyEl.appendChild(ul);
        };

        listModal.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget;
            const financeiroId = trigger?.dataset.financeiroId || '';
            const financeiroDescricao = trigger?.dataset.financeiroDescricao || 'Anexos';

            if (titleEl instanceof HTMLElement) {
                titleEl.textContent = financeiroDescricao;
            }

            renderCarregando();

            if (financeiroId === '') {
                renderErro();
                return;
            }

            const url = urlTemplate.replace('__FINANCEIRO_ID__', financeiroId);

            fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => (response.ok ? response.json() : Promise.reject()))
                .then((payload) => renderLista(Array.isArray(payload.anexos) ? payload.anexos : []))
                .catch(() => renderErro());
        });

        listModal.addEventListener('hidden.bs.modal', () => {
            if (bodyEl instanceof HTMLElement) {
                bodyEl.innerHTML = '';
            }
        });
    }

    // Modal de pré-visualização (financeiro/show.blade.php): o botão "olho"
    // de cada anexo passa data-anexo-url/mime/nome — monta um <iframe> para
    // PDF ou <img> para imagem dentro do modal, sem sair da página. Tipos
    // sem preview conhecido caem no aviso e o operador usa "Abrir em nova
    // aba" no rodapé.
    const previewModal = document.getElementById('anexoPreviewModal');

    if (previewModal) {
        const titleEl = previewModal.querySelector('[data-anexo-preview-title]');
        const bodyEl = previewModal.querySelector('[data-anexo-preview-body]');
        const openLink = previewModal.querySelector('[data-anexo-preview-open-link]');

        previewModal.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget;
            const url = trigger?.dataset.anexoUrl || '';
            const mime = trigger?.dataset.anexoMime || '';
            const nome = trigger?.dataset.anexoNome || 'Anexo';

            if (titleEl instanceof HTMLElement) {
                titleEl.textContent = nome;
            }

            if (openLink instanceof HTMLAnchorElement) {
                openLink.href = url;
            }

            if (!(bodyEl instanceof HTMLElement)) {
                return;
            }

            bodyEl.innerHTML = '';

            if (mime.startsWith('image/')) {
                const img = document.createElement('img');
                img.src = url;
                img.alt = nome;
                img.className = 'w-100';
                bodyEl.appendChild(img);
            } else if (mime === 'application/pdf') {
                const iframe = document.createElement('iframe');
                iframe.src = url;
                iframe.title = nome;
                iframe.style.width = '100%';
                iframe.style.height = '75vh';
                iframe.style.border = '0';
                bodyEl.appendChild(iframe);
            } else {
                const aviso = document.createElement('p');
                aviso.className = 'p-4 mb-0 text-secondary';
                aviso.textContent = 'Pré-visualização não disponível para este tipo de arquivo. Use "Abrir em nova aba".';
                bodyEl.appendChild(aviso);
            }
        });

        previewModal.addEventListener('hidden.bs.modal', () => {
            if (bodyEl instanceof HTMLElement) {
                bodyEl.innerHTML = '';
            }
        });
    }
})();
