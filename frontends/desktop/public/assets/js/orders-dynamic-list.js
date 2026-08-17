(function () {
    const form = document.getElementById('osFilterPanel');
    const searchInput = document.getElementById('search');
    const perPageSelect = document.querySelector('[data-os-per-page]');
    const container = document.getElementById('osTableContainer');
    const resultsChip = document.getElementById('osResultsCount');

    if (!(form instanceof HTMLFormElement) || !(container instanceof HTMLElement)) {
        return;
    }

    const SEARCH_DEBOUNCE_MS = 400;

    let searchTimer = null;
    let abortController = null;

    const buildUrlFromForm = () => {
        const params = new URLSearchParams(new FormData(form));
        // Toda troca via busca/itens-por-pagina invalida a pagina atual — sem
        // isso o usuario podia ficar preso numa pagina 5 que nao existe mais
        // depois de um filtro mais restritivo.
        params.delete('page');

        return window.location.pathname + '?' + params.toString();
    };

    // Fecha dropdowns de linha abertos (o menu fica portado no fim do <body>
    // enquanto aberto — ver initDropdowns() em desktop.js) antes de descartar
    // o HTML do container, senao o menu portado vira orfao (perde o toggle
    // que o controlava, mas continua no <body>).
    const closeOpenRowDropdowns = () => {
        container.querySelectorAll('[data-bs-toggle="dropdown"]').forEach((toggle) => {
            if (window.bootstrap?.Dropdown) {
                window.bootstrap.Dropdown.getInstance(toggle)?.hide();
            }
        });
    };

    const setLoading = (isLoading) => {
        container.classList.toggle('is-os-table-loading', isLoading);
    };

    const refreshContainerBehaviors = () => {
        if (window.DesktopUi?.refreshDropdowns) {
            window.DesktopUi.refreshDropdowns(container);
        }
        if (window.DesktopUi?.refreshPhotoFallbacks) {
            window.DesktopUi.refreshPhotoFallbacks(container);
        }

        // orders-batch-closure.js / orders-status-batch.js reconsultam
        // .order-select e #osSelectAll ao vivo, mas precisam recalcular a
        // contagem/estado dos botoes em lote depois que as linhas somem.
        document.dispatchEvent(new CustomEvent('os-table:refreshed'));
    };

    const loadTable = (url, { pushState = true } = {}) => {
        abortController?.abort();
        abortController = new AbortController();

        setLoading(true);
        closeOpenRowDropdowns();

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: abortController.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Falha ao carregar a listagem de OS.');
                }

                return response.json();
            })
            .then((payload) => {
                if (typeof payload?.html !== 'string') {
                    throw new Error('Resposta inesperada da listagem de OS.');
                }

                container.innerHTML = payload.html;
                refreshContainerBehaviors();

                if (resultsChip instanceof HTMLElement && typeof payload.total === 'number') {
                    resultsChip.textContent = payload.total.toLocaleString('pt-BR') + ' resultados';
                }

                if (pushState) {
                    history.pushState({ osTableUrl: url }, '', url);
                }
            })
            .catch((error) => {
                if (error.name === 'AbortError') {
                    return;
                }

                // Falha de rede, sessao expirada (redirect vira HTML, nao JSON) ou
                // qualquer outra coisa inesperada: cai pra navegacao normal em vez
                // de deixar a tabela quebrada/parada no meio de um fetch.
                window.erpMarkInternalNavigation?.();
                window.location.assign(url);
            })
            .finally(() => {
                setLoading(false);
            });
    };

    searchInput?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadTable(buildUrlFromForm()), SEARCH_DEBOUNCE_MS);
    });

    perPageSelect?.addEventListener('change', () => {
        clearTimeout(searchTimer);
        loadTable(buildUrlFromForm());
    });

    container.addEventListener('click', (event) => {
        const link = event.target instanceof HTMLElement ? event.target.closest('a.page-link') : null;
        if (!(link instanceof HTMLAnchorElement) || link.closest('.disabled')) {
            return;
        }

        const href = link.getAttribute('href') || '';
        if (href === '' || href === '#') {
            return;
        }

        event.preventDefault();
        loadTable(link.href);
    });

    window.addEventListener('popstate', () => {
        loadTable(window.location.href, { pushState: false });
    });
})();
