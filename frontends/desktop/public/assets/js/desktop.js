const DesktopUi = (() => {
    const sidebar = document.getElementById('desktopSidebar');
    const main = document.getElementById('desktopMain');
    const overlay = document.getElementById('desktopSidebarOverlay');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarToggleIcon = sidebarToggle?.querySelector('i');
    const mobileSidebarToggle = document.getElementById('mobileSidebarToggle');
    const collapseStorageKey = 'desktop-sidebar-collapsed';

    const searchForm = document.querySelector('[data-desktop-search-form]');
    const searchInput = searchForm?.querySelector('[data-desktop-search-input]');
    const searchScopeValue = searchForm?.querySelector('[data-desktop-search-scope-value]');
    const searchScopeLabel = searchForm?.querySelector('[data-desktop-search-scope-label]');
    const searchResults = searchForm?.querySelector('[data-desktop-search-results]');
    const searchSuggestUrl = searchForm?.dataset.suggestUrl || '';

    const notificationRoot = document.querySelector('[data-desktop-notification-root]');
    const notificationSummaryUrl = notificationRoot?.dataset.notificationSummaryUrl || '';
    const notificationList = notificationRoot?.querySelector('[data-desktop-notification-list]');
    const notificationUnread = notificationRoot?.querySelector('[data-desktop-notification-unread]');
    const notificationBadge = notificationRoot?.querySelector('[data-desktop-notification-badge]');

    const pageLoaderRoot = document.querySelector('[data-desktop-page-loader]');
    const pageLoaderMessage = pageLoaderRoot?.querySelector('.desktop-page-loader-copy span');

    let searchTimer = null;
    let searchAbortController = null;
    let notificationSummaryPromise = null;
    let pageLoaderTimer = null;
    let pageLoaderVisible = false;

    const SENSITIVE_KEY_PATTERN = /token|senha|password|secret|authoriza|cookie|cpf|cnpj|cart[aã]o|api[_-]?key/i;

    const sanitizeForLog = (value, depth = 0) => {
        if (depth > 4 || value === null || value === undefined) {
            return value;
        }

        if (value instanceof Error) {
            return {
                name: value.name,
                message: value.message,
                status: value.status,
                stack: value.stack,
            };
        }

        if (Array.isArray(value)) {
            return value.slice(0, 20).map((item) => sanitizeForLog(item, depth + 1));
        }

        if (typeof value === 'object') {
            const result = {};
            Object.entries(value).forEach(([key, val]) => {
                result[key] = SENSITIVE_KEY_PATTERN.test(key) ? '[REDACTED]' : sanitizeForLog(val, depth + 1);
            });
            return result;
        }

        if (typeof value === 'string' && value.length > 500) {
            return `${value.slice(0, 500)}... [truncated]`;
        }

        return value;
    };

    // Ponto unico de log de erros: qualquer falha (JS, rede, promise) passa por
    // aqui e sai sanitizada no console, sem vazar tokens/senhas/dados pessoais.
    const logError = (context, error, extra = {}) => {
        console.error(
            `[Desktop][${context}]`,
            sanitizeForLog({
                message: error?.message || String(error),
                status: error?.status,
                ...extra,
            }),
            sanitizeForLog(error)
        );
    };

    window.addEventListener('error', (event) => {
        logError('window.onerror', event.error || event.message, {
            source: event.filename,
            line: event.lineno,
            column: event.colno,
        });
    });

    window.addEventListener('unhandledrejection', (event) => {
        logError('unhandledrejection', event.reason);
    });

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const hidePageLoader = () => {
        if (!(pageLoaderRoot instanceof HTMLElement)) {
            return;
        }

        if (pageLoaderTimer !== null) {
            window.clearTimeout(pageLoaderTimer);
            pageLoaderTimer = null;
        }

        pageLoaderVisible = false;
        pageLoaderRoot.classList.remove('is-visible');
        pageLoaderRoot.hidden = true;
        pageLoaderRoot.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('is-page-transitioning');
    };

    const showPageLoader = (message = 'Preparando a próxima tela...') => {
        if (!(pageLoaderRoot instanceof HTMLElement)) {
            return;
        }

        if (pageLoaderVisible) {
            if (pageLoaderMessage instanceof HTMLElement && message !== '') {
                pageLoaderMessage.textContent = message;
            }

            return;
        }

        pageLoaderVisible = true;

        if (pageLoaderMessage instanceof HTMLElement && message !== '') {
            pageLoaderMessage.textContent = message;
        }

        pageLoaderRoot.hidden = false;
        pageLoaderRoot.setAttribute('aria-hidden', 'false');
        document.body.classList.add('is-page-transitioning');

        window.requestAnimationFrame(() => {
            pageLoaderRoot.classList.add('is-visible');
        });
    };

    const isSameOriginUrl = (url) => {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch {
            return false;
        }
    };

    const shouldHandleLinkNavigation = (link, event) => {
        if (!(link instanceof HTMLAnchorElement) || event.defaultPrevented) {
            return false;
        }

        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return false;
        }

        if (link.dataset.noPageLoader === 'true' || link.closest('[data-no-page-loader="true"]')) {
            return false;
        }

        if (link.hasAttribute('download') || (link.target && link.target !== '_self')) {
            return false;
        }

        const href = (link.getAttribute('href') ?? '').trim();
        if (href === '' || href === '#' || href.startsWith('javascript:')) {
            return false;
        }

        if (!isSameOriginUrl(href)) {
            return false;
        }

        const targetUrl = new URL(link.href, window.location.href);
        if (targetUrl.href === window.location.href || (
            targetUrl.pathname === window.location.pathname
            && targetUrl.search === window.location.search
            && targetUrl.hash !== ''
        )) {
            return false;
        }

        return true;
    };

    const shouldHandleFormNavigation = (form, event) => {
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) {
            return false;
        }

        if (form.dataset.noPageLoader === 'true' || form.closest('[data-no-page-loader="true"]')) {
            return false;
        }

        if (form.target && form.target !== '_self') {
            return false;
        }

        return form.method !== 'dialog';
    };

    const clearCollapsedSidebarPopoverStyle = (submenu) => {
        if (!(submenu instanceof HTMLElement)) {
            return;
        }

        ['top', 'left', 'width', 'max-width', 'max-height', 'overflow-y'].forEach((property) => {
            submenu.style.removeProperty(property);
        });
    };

    const syncCollapsedSidebarGroupPopovers = () => {
        if (!(sidebar instanceof HTMLElement)) {
            return;
        }

        const collapsed = sidebar.classList.contains('is-collapsed');

        sidebar.querySelectorAll('.desktop-nav-sublist').forEach((submenu) => {
            if (!(submenu instanceof HTMLElement)) {
                return;
            }

            if (!collapsed) {
                clearCollapsedSidebarPopoverStyle(submenu);
            }
        });

        if (!collapsed) {
            return;
        }

        const sidebarRect = sidebar.getBoundingClientRect();
        const margin = 8;

        sidebar.querySelectorAll('.desktop-nav-group').forEach((group) => {
            if (!(group instanceof HTMLElement)) {
                return;
            }

            const submenu = group.querySelector('.desktop-nav-sublist');
            const trigger = group.querySelector('.desktop-nav-group-head');

            if (!(submenu instanceof HTMLElement) || !(trigger instanceof HTMLElement) || !group.classList.contains('is-open')) {
                clearCollapsedSidebarPopoverStyle(submenu);
                return;
            }

            const triggerRect = trigger.getBoundingClientRect();
            const preferredWidth = submenu.getBoundingClientRect().width || submenu.scrollWidth || 220;
            const maxWidth = Math.max(220, window.innerWidth - (margin * 2));
            const width = Math.min(Math.max(preferredWidth, 220), maxWidth);
            const popupHeight = Math.min(submenu.scrollHeight || 0, window.innerHeight - (margin * 2));
            const left = Math.max(
                margin,
                Math.min(sidebarRect.right + 8, window.innerWidth - width - margin),
            );
            const top = Math.max(
                margin,
                Math.min(triggerRect.top, window.innerHeight - popupHeight - margin),
            );

            submenu.style.left = `${Math.round(left)}px`;
            submenu.style.top = `${Math.round(top)}px`;
            submenu.style.width = `${Math.round(width)}px`;
            submenu.style.maxWidth = `${Math.round(maxWidth)}px`;
            submenu.style.maxHeight = `${Math.max(140, Math.round(window.innerHeight - (margin * 2)))}px`;
            submenu.style.overflowY = 'auto';
        });
    };

    const closeCollapsedSidebarPopovers = () => {
        if (!(sidebar instanceof HTMLElement) || !sidebar.classList.contains('is-collapsed')) {
            return;
        }

        sidebar.querySelectorAll('.desktop-nav-group.is-open').forEach((group) => {
            if (!(group instanceof HTMLElement)) {
                return;
            }

            group.classList.remove('is-open');

            const toggle = group.querySelector('[data-desktop-nav-group-toggle]');
            if (toggle instanceof HTMLElement) {
                toggle.setAttribute('aria-expanded', 'false');
            }

            clearCollapsedSidebarPopoverStyle(group.querySelector('.desktop-nav-sublist'));
        });
    };

    const renderNotificationBadge = (unreadCount) => {
        if (!(notificationBadge instanceof HTMLElement)) {
            return;
        }

        if (unreadCount > 0) {
            notificationBadge.textContent = unreadCount > 9 ? '9+' : String(unreadCount);
            notificationBadge.classList.remove('d-none');
            return;
        }

        notificationBadge.textContent = '';
        notificationBadge.classList.add('d-none');
    };

    const renderNotificationEmptyState = (message) => {
        if (!(notificationList instanceof HTMLElement)) {
            return;
        }

        notificationList.innerHTML = `
            <div class="desktop-notification-empty">
                ${escapeHtml(message)}
            </div>
        `;
    };

    const renderNotificationSummary = (summary) => {
        if (!(notificationList instanceof HTMLElement)) {
            return;
        }

        const items = Array.isArray(summary?.items) ? summary.items : [];
        const unreadCount = Math.max(0, Number(summary?.unread_count ?? 0));

        if (notificationUnread instanceof HTMLElement) {
            notificationUnread.textContent = unreadCount > 0
                ? `${unreadCount} não lidas`
                : '0 não lidas';
        }

        renderNotificationBadge(unreadCount);

        if (items.length === 0) {
            renderNotificationEmptyState('Nenhuma notificação disponível.');
            return;
        }

        notificationList.innerHTML = items.map((notification) => {
            const unreadClass = notification?.lida_em ? 'is-read' : 'is-unread';
            const icon = escapeHtml(notification?.icone || 'bi bi-bell');
            const title = escapeHtml(notification?.titulo || 'Notificação');
            const body = escapeHtml(notification?.corpo || '');
            const humanTime = escapeHtml(notification?.criada_em_humano || 'Agora');
            const url = escapeHtml(notification?.url || '#');

            return `
                <a href="${url}" class="desktop-notification-item ${unreadClass}">
                    <span class="desktop-notification-icon">
                        <i class="${icon}"></i>
                    </span>

                    <span class="desktop-notification-copy">
                        <strong>${title}</strong>
                        <small>${body}</small>
                        <span>${humanTime}</span>
                    </span>
                </a>
            `;
        }).join('');
    };

    const setNotificationLoadingState = () => {
        if (notificationRoot instanceof HTMLElement) {
            notificationRoot.dataset.notificationsLoading = '1';
        }

        if (notificationUnread instanceof HTMLElement) {
            notificationUnread.textContent = 'Carregando resumo...';
        }

        renderNotificationEmptyState('Carregando notificações...');
    };

    const setNotificationErrorState = () => {
        if (notificationUnread instanceof HTMLElement) {
            notificationUnread.textContent = 'Resumo indisponível';
        }

        renderNotificationEmptyState('Não foi possível carregar as notificações agora.');
    };

    const loadNotificationSummary = () => {
        if (!(notificationRoot instanceof HTMLElement) || notificationSummaryUrl === '') {
            return Promise.resolve(null);
        }

        if (notificationSummaryPromise !== null) {
            return notificationSummaryPromise;
        }

        setNotificationLoadingState();

        notificationSummaryPromise = fetch(notificationSummaryUrl, {
            headers: {
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (response.status === 401) {
                    window.location.href = '/login';
                    return null;
                }

                if (!response.ok) {
                    throw new Error('Não foi possível carregar o resumo de notificações.');
                }

                return response.json();
            })
            .then((payload) => {
                if (payload === null) {
                    return null;
                }

                const summary = payload?.data || payload;
                renderNotificationSummary(summary);

                if (notificationRoot instanceof HTMLElement) {
                    notificationRoot.dataset.notificationsLoaded = '1';
                }

                return summary;
            })
            .catch((error) => {
                logError('notifications', error, {
                    url: notificationSummaryUrl,
                });
                setNotificationErrorState();
                return null;
            })
            .finally(() => {
                if (notificationRoot instanceof HTMLElement) {
                    notificationRoot.dataset.notificationsLoading = '0';
                }

                notificationSummaryPromise = null;
            });

        return notificationSummaryPromise;
    };

    const initNotifications = () => {
        if (!(notificationRoot instanceof HTMLElement) || notificationSummaryUrl === '') {
            return;
        }

        const scheduleLoad = () => {
            if (notificationRoot.dataset.notificationsLoaded === '1' || notificationRoot.dataset.notificationsLoading === '1') {
                return;
            }

            loadNotificationSummary();
        };

        notificationRoot.addEventListener('show.bs.dropdown', scheduleLoad);

        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(scheduleLoad, { timeout: 1200 });
            return;
        }

        window.setTimeout(scheduleLoad, 500);
    };

    const initPageTransitions = () => {
        if (!(pageLoaderRoot instanceof HTMLElement)) {
            return;
        }

        hidePageLoader();

        document.addEventListener('click', (event) => {
            const target = event.target;
            const link = target instanceof Element ? target.closest('a[href]') : null;

            if (!(link instanceof HTMLAnchorElement) || !shouldHandleLinkNavigation(link, event)) {
                return;
            }

            event.preventDefault();

            showPageLoader(link.dataset.pageLoaderMessage || 'Abrindo a próxima tela...');

            if (pageLoaderTimer !== null) {
                window.clearTimeout(pageLoaderTimer);
            }

            pageLoaderTimer = window.setTimeout(() => {
                window.location.assign(link.href);
            }, 60);
        });

        document.addEventListener('submit', (event) => {
            const form = event.target;

            if (!(form instanceof HTMLFormElement) || !shouldHandleFormNavigation(form, event)) {
                return;
            }

            event.preventDefault();

            showPageLoader(form.dataset.pageLoaderMessage || 'Enviando dados...');

            if (pageLoaderTimer !== null) {
                window.clearTimeout(pageLoaderTimer);
            }

            pageLoaderTimer = window.setTimeout(() => {
                form.submit();
            }, 60);
        });

        window.addEventListener('pageshow', hidePageLoader);
        window.addEventListener('pagehide', hidePageLoader);
        window.addEventListener('beforeunload', () => {
            if (!(pageLoaderRoot instanceof HTMLElement)) {
                return;
            }

            pageLoaderRoot.hidden = false;
            pageLoaderRoot.classList.add('is-visible');
            pageLoaderRoot.setAttribute('aria-hidden', 'false');
            document.body.classList.add('is-page-transitioning');
        });
    };

    const initSidebar = () => {
        if (!sidebar || !main) {
            return;
        }

        // Paginas com sidebar "is-hidden" (ex.: /os, /os/criar) usam um modelo
        // de estado proprio, aberto/fechado via hamburguer. O modo "recolhido"
        // (icon-only, persistido em localStorage) e de outras paginas e nao
        // deve ser reaplicado aqui, senao as classes is-collapsed/is-expanded
        // entram em conflito com is-hidden/is-full e deixam uma folga vazia
        // no canto esquerdo.
        const isHiddenLayout = sidebar.classList.contains('is-hidden');

        const syncSidebarToggleState = () => {
            if (!sidebarToggle) {
                return;
            }

            const collapsed = sidebar.classList.contains('is-collapsed');

            sidebarToggle.setAttribute('aria-label', collapsed ? 'Expandir navegacao' : 'Recolher navegacao');
            sidebarToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

            if (sidebarToggleIcon instanceof HTMLElement) {
                sidebarToggleIcon.classList.toggle('bi-chevron-left', !collapsed);
                sidebarToggleIcon.classList.toggle('bi-chevron-right', collapsed);
            }
        };

        if (!isHiddenLayout && localStorage.getItem(collapseStorageKey) === '1') {
            sidebar.classList.add('is-collapsed');
            main.classList.add('is-expanded');
        }

        syncSidebarToggleState();

        sidebarToggle?.addEventListener('click', () => {
            if (isHiddenLayout) {
                return;
            }

            sidebar.classList.toggle('is-collapsed');
            main.classList.toggle('is-expanded');
            localStorage.setItem(collapseStorageKey, sidebar.classList.contains('is-collapsed') ? '1' : '0');
            syncSidebarToggleState();
            window.requestAnimationFrame(syncCollapsedSidebarGroupPopovers);
        });

        const closeMobileSidebar = () => {
            sidebar.classList.remove('is-open');
            overlay?.classList.remove('is-open');
            mobileSidebarToggle?.setAttribute('aria-expanded', 'false');
        };

        const openMobileSidebar = () => {
            sidebar.classList.add('is-open');
            overlay?.classList.add('is-open');
            mobileSidebarToggle?.setAttribute('aria-expanded', 'true');
        };

        mobileSidebarToggle?.addEventListener('click', () => {
            if (sidebar.classList.contains('is-open')) {
                closeMobileSidebar();
            } else {
                openMobileSidebar();
            }
        });

        overlay?.addEventListener('click', closeMobileSidebar);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
                closeMobileSidebar();
            }
        });
    };

    const initSidebarGroups = () => {
        const sidebarNav = sidebar?.querySelector('.desktop-nav');

        document.querySelectorAll('[data-desktop-nav-group-toggle]').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const group = toggle.closest('.desktop-nav-group');
                if (!(group instanceof HTMLElement)) {
                    return;
                }

                if (sidebar instanceof HTMLElement && sidebar.classList.contains('is-collapsed')) {
                    sidebar.querySelectorAll('.desktop-nav-group.is-open').forEach((openGroup) => {
                        if (!(openGroup instanceof HTMLElement) || openGroup === group) {
                            return;
                        }

                        openGroup.classList.remove('is-open');
                        const openToggle = openGroup.querySelector('[data-desktop-nav-group-toggle]');
                        if (openToggle instanceof HTMLElement) {
                            openToggle.setAttribute('aria-expanded', 'false');
                        }

                        clearCollapsedSidebarPopoverStyle(openGroup.querySelector('.desktop-nav-sublist'));
                    });
                }

                const isOpen = group.classList.toggle('is-open');
                toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

                if (isOpen) {
                    syncCollapsedSidebarGroupPopovers();
                } else {
                    clearCollapsedSidebarPopoverStyle(group.querySelector('.desktop-nav-sublist'));
                }
            });
        });

        sidebarNav?.addEventListener('scroll', syncCollapsedSidebarGroupPopovers, { passive: true });
        window.addEventListener('resize', syncCollapsedSidebarGroupPopovers);
        document.addEventListener('click', (event) => {
            if (!(sidebar instanceof HTMLElement) || !sidebar.classList.contains('is-collapsed')) {
                return;
            }

            const target = event.target;
            if (!(target instanceof Node) || sidebar.contains(target)) {
                return;
            }

            closeCollapsedSidebarPopovers();
        });

        syncCollapsedSidebarGroupPopovers();
    };

    const initConfigSubtabs = () => {
        document.querySelectorAll('[data-config-subtab]').forEach((button) => {
            button.addEventListener('click', () => {
                const shell = button.closest('.desktop-config-tabs-shell') || button.closest('.surface-card') || document;
                const activeKey = button.getAttribute('data-config-subtab') || '';
                const subtabs = shell.querySelectorAll('[data-config-subtab]');
                const subpanels = shell.querySelectorAll('[data-config-subpanel]');

                subtabs.forEach((tab) => {
                    tab.classList.toggle('is-active', tab.getAttribute('data-config-subtab') === activeKey);
                });

                subpanels.forEach((panel) => {
                    panel.classList.toggle('is-active', panel.getAttribute('data-config-subpanel') === activeKey);
                });
            });
        });
    };

    const initPasswordToggles = () => {
        document.querySelectorAll('[data-password-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const inputId = button.getAttribute('data-password-toggle');
                const input = inputId ? document.getElementById(inputId) : null;

                if (!(input instanceof HTMLInputElement)) {
                    return;
                }

                const visible = input.type === 'text';
                input.type = visible ? 'password' : 'text';
                button.innerHTML = visible ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
            });
        });
    };

    const initFlash = () => {
        if (typeof Swal === 'undefined' || typeof window.__DESKTOP_FLASH !== 'object') {
            return;
        }

        const priority = ['error', 'warning', 'success', 'info'];

        for (const key of priority) {
            const message = window.__DESKTOP_FLASH[key];
            if (!message) {
                continue;
            }

            Swal.fire({
                toast: true,
                position: 'top-end',
                timer: key === 'error' ? 6500 : 4200,
                timerProgressBar: true,
                showConfirmButton: false,
                icon: key,
                title: message,
                customClass: {
                    popup: 'swal-desktop-toast',
                },
            });

            break;
        }
    };

    const initConfirmForms = () => {
        document.querySelectorAll('form[data-confirm]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (form.dataset.confirmed === '1') {
                    return;
                }

                event.preventDefault();

                Swal.fire({
                    title: form.getAttribute('data-confirm-title') || 'Confirmar ação',
                    text: form.getAttribute('data-confirm') || 'Deseja continuar com esta operação?',
                    icon: form.getAttribute('data-confirm-icon') || 'warning',
                    showCancelButton: true,
                    confirmButtonText: form.getAttribute('data-confirm-button') || 'Sim, continuar',
                    cancelButtonText: 'Cancelar',
                    reverseButtons: true,
                }).then((result) => {
                    if (!result.isConfirmed) {
                        return;
                    }

                    form.dataset.confirmed = '1';
                    form.requestSubmit();
                });
            });
        });
    };

    const fillModal = (trigger) => {
        const modalSelector = trigger.getAttribute('data-modal-fill');
        if (!modalSelector) {
            return;
        }

        const modal = document.querySelector(modalSelector);
        if (!(modal instanceof HTMLElement)) {
            return;
        }

        const formAction = trigger.getAttribute('data-form-action');
        const form = modal.querySelector('form');
        if (form && formAction) {
            form.setAttribute('action', formAction);
        }

        Object.entries(trigger.dataset).forEach(([key, value]) => {
            if (!key.startsWith('field')) {
                return;
            }

            const fieldName = key.replace('field', '');
            const normalized = fieldName.charAt(0).toLowerCase() + fieldName.slice(1);
            const input = modal.querySelector(`[data-field="${normalized}"]`);

            if (!(input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement || input instanceof HTMLSelectElement)) {
                return;
            }

            if (input.type === 'checkbox') {
                input.checked = value === '1' || value === 'true';
                return;
            }

            input.value = value ?? '';
        });
    };

    const initModalFillers = () => {
        document.querySelectorAll('[data-modal-fill]').forEach((button) => {
            button.addEventListener('click', () => fillModal(button));
        });
    };

    const select2Language = {
        errorLoading: () => 'Os resultados não puderam ser carregados.',
        inputTooLong: (args) => {
            const overChars = args.input.length - args.maximum;
            const suffix = overChars === 1 ? 'caractere' : 'caracteres';
            return `Apague ${overChars} ${suffix}`;
        },
        inputTooShort: (args) => {
            const remaining = args.minimum - args.input.length;
            const suffix = remaining === 1 ? 'caractere' : 'caracteres';
            return `Digite mais ${remaining} ${suffix}`;
        },
        loadingMore: () => 'Carregando mais resultados…',
        maximumSelected: (args) => {
            const suffix = args.maximum === 1 ? 'item' : 'itens';
            return `Você só pode selecionar ${args.maximum} ${suffix}`;
        },
        noResults: () => 'Nenhum resultado encontrado',
        searching: () => 'Buscando…',
        removeAllItems: () => 'Remover todos os itens',
    };

    const getSelect2DropdownParent = (select, $) => {
        const modal = select.closest('.modal');
        if (modal) {
            return $(modal);
        }

        const offcanvas = select.closest('.offcanvas');
        if (offcanvas) {
            return $(offcanvas);
        }

        return $(document.body);
    };

    const initSelect2 = (container = document, force = false) => {
        if (typeof window.jQuery === 'undefined' || !window.jQuery.fn || typeof window.jQuery.fn.select2 !== 'function') {
            return;
        }

        const $ = window.jQuery;
        const scope = container instanceof Document || container instanceof Element ? container : document;
        const selector = 'select.form-select:not([data-native-select="true"]):not([data-select2="false"])';
        const selects = [];

        if (scope instanceof HTMLSelectElement && scope.matches(selector)) {
            selects.push(scope);
        }

        scope.querySelectorAll(selector).forEach((select) => {
            if (select instanceof HTMLSelectElement) {
                selects.push(select);
            }
        });

        selects.forEach((select) => {
            if (!(select instanceof HTMLSelectElement)) {
                return;
            }

            if (select.dataset.select2Ready === '1') {
                if (!force) {
                    return;
                }

                try {
                    $(select).select2('destroy');
                } catch (error) {
                    console.error('[Desktop][Select2] Falha ao destruir instância antes de reinicializar.', {
                        elementId: select.id || null,
                        name: select.name || null,
                        error,
                    });
                }

                delete select.dataset.select2Ready;
            }

            const emptyOption = select.querySelector('option[value=""]');
            const placeholder = select.dataset.select2Placeholder
                || emptyOption?.textContent?.trim()
                || select.getAttribute('placeholder')
                || '';
            const allowClear = select.dataset.select2AllowClear === 'true'
                || (select.dataset.select2AllowClear !== 'false' && emptyOption !== null);

            $(select).select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: placeholder !== '' ? placeholder : undefined,
                allowClear,
                dropdownParent: getSelect2DropdownParent(select, $),
                minimumResultsForSearch: 0,
                language: select2Language,
            });

            select.dataset.select2Ready = '1';
        });
    };

    const refreshSelect2 = (container = document) => {
        initSelect2(container, true);
    };

    document.addEventListener('shown.bs.modal', (event) => {
        if (event.target instanceof HTMLElement) {
            refreshSelect2(event.target);
        }
    });

    document.addEventListener('shown.bs.offcanvas', (event) => {
        if (event.target instanceof HTMLElement) {
            refreshSelect2(event.target);
        }
    });

    document.addEventListener('shown.bs.tab', (event) => {
        if (event.target instanceof HTMLElement) {
            const targetSelector = event.target.getAttribute('data-bs-target')
                || event.target.getAttribute('href')
                || '';
            const targetPanel = targetSelector !== '' ? document.querySelector(targetSelector) : null;

            refreshSelect2(targetPanel instanceof HTMLElement ? targetPanel : event.target);
        }
    });

    const initOsPreviewModals = () => {
        document.querySelectorAll('[data-os-modal-url]').forEach((trigger) => {
            trigger.addEventListener('click', (event) => {
                event.preventDefault();

                const previewUrl = trigger.getAttribute('data-os-modal-url') || '';
                const fullUrl = trigger.getAttribute('data-os-open-full-url') || previewUrl;
                const title = trigger.getAttribute('data-os-modal-title') || 'Pré-visualização';
                const modalId = trigger.getAttribute('data-os-modal-id') || 'dashboardOsModal';
                const modalElement = document.getElementById(modalId);
                const frame = document.getElementById('dashboardOsModalFrame');
                const loading = document.getElementById('dashboardOsModalLoading');
                const titleElement = document.getElementById('dashboardOsModalTitle');
                const fullLink = document.getElementById('dashboardOsModalOpenFull');

                if (!(modalElement instanceof HTMLElement) || !(frame instanceof HTMLIFrameElement)) {
                    if (fullUrl !== '') {
                        window.open(fullUrl, '_blank', 'noopener,noreferrer');
                    }

                    return;
                }

                if (titleElement instanceof HTMLElement) {
                    titleElement.textContent = title;
                }

                if (fullLink instanceof HTMLAnchorElement) {
                    fullLink.href = fullUrl;
                }

                if (loading instanceof HTMLElement) {
                    loading.hidden = false;
                }

                frame.hidden = false;
                frame.onload = () => {
                    if (loading instanceof HTMLElement) {
                        loading.hidden = true;
                    }
                };
                frame.src = previewUrl;

                if (typeof bootstrap !== 'undefined' && typeof bootstrap.Modal === 'function') {
                    bootstrap.Modal.getOrCreateInstance(modalElement).show();
                } else if (fullUrl !== '') {
                    window.open(fullUrl, '_blank', 'noopener,noreferrer');
                }

                modalElement.addEventListener('hidden.bs.modal', () => {
                    frame.src = 'about:blank';
                    if (loading instanceof HTMLElement) {
                        loading.hidden = false;
                    }
                }, { once: true });
            });
        });
    };

    const hideSearchResults = () => {
        if (!(searchResults instanceof HTMLElement)) {
            return;
        }

        searchResults.hidden = true;
        searchResults.innerHTML = '';
    };

    const renderSearchResults = (data) => {
        if (!(searchResults instanceof HTMLElement)) {
            return;
        }

        const sections = Array.isArray(data?.sections) ? data.sections : [];
        const total = Number(data?.total ?? 0);

        if (sections.length === 0) {
            searchResults.innerHTML = `
                <div class="desktop-search-empty">
                    <i class="bi bi-search"></i>
                    <strong>Nenhum resultado encontrado</strong>
                    <span>Tente outro termo ou ajuste o escopo da busca.</span>
                </div>
            `;
            searchResults.hidden = false;
            searchResults.dataset.total = String(total);
            return;
        }

        const html = sections.map((section) => {
            const items = Array.isArray(section.items) ? section.items : [];

            return `
                <div class="desktop-search-group">
                    <div class="desktop-search-group-head">
                        <strong><i class="bi ${escapeHtml(section.icon || 'bi-grid')} me-2"></i>${escapeHtml(section.label || 'Resultados')}</strong>
                        <span>${items.length}</span>
                    </div>
                    <div class="desktop-search-group-items">
                        ${items.map((item) => `
                            <a href="${escapeHtml(item.url || '#')}" class="desktop-search-item">
                                <span class="desktop-search-item-icon"><i class="bi ${escapeHtml(item.icon || 'bi-grid')}"></i></span>
                                <span class="desktop-search-item-copy">
                                    <strong>${escapeHtml(item.label || 'Resultado')}</strong>
                                    <small>${escapeHtml(item.subtitle || '')}</small>
                                </span>
                                <i class="bi bi-arrow-right-short"></i>
                            </a>
                        `).join('')}
                    </div>
                </div>
            `;
        }).join('');

        searchResults.innerHTML = html;
        searchResults.hidden = false;
        searchResults.dataset.total = String(total);
    };

    const performSearchPreview = () => {
        if (!(searchInput instanceof HTMLInputElement) || !(searchResults instanceof HTMLElement)) {
            return;
        }

        const query = searchInput.value.trim();
        const scope = searchScopeValue instanceof HTMLInputElement ? searchScopeValue.value : 'tudo';

        if (query.length < 2 || searchSuggestUrl === '') {
            hideSearchResults();
            return;
        }

        if (searchAbortController) {
            searchAbortController.abort();
        }

        searchAbortController = new AbortController();

        searchResults.hidden = false;
        searchResults.innerHTML = `
            <div class="desktop-search-loading">
                <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                <span>Buscando resultados...</span>
            </div>
        `;

        fetch(`${searchSuggestUrl}?q=${encodeURIComponent(query)}&scope=${encodeURIComponent(scope)}`, {
            headers: {
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            signal: searchAbortController.signal,
        })
            .then((response) => {
                if (response.status === 401) {
                    window.location.href = '/login';
                    return null;
                }

                if (response.status === 403) {
                    throw new Error('Você não tem permissão para usar a busca.');
                }

                if (!response.ok) {
                    throw new Error('Não foi possível carregar a busca.');
                }

                return response.json();
            })
            .then((payload) => {
                if (payload === null) {
                    return;
                }

                renderSearchResults(payload.data || payload);
            })
            .catch((error) => {
                if (error.name === 'AbortError') {
                    return;
                }

                renderSearchResults({
                    sections: [],
                    total: 0,
                });
            });
    };

    const initSearchAutocomplete = () => {
        if (!(searchForm instanceof HTMLFormElement) || !(searchInput instanceof HTMLInputElement)) {
            return;
        }

        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(performSearchPreview, 220);
        });

        searchInput.addEventListener('focus', () => {
            if (searchInput.value.trim().length >= 2) {
                performSearchPreview();
            }
        });

        searchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                hideSearchResults();
            }
        });

        searchForm.addEventListener('submit', () => {
            hideSearchResults();
        });

        document.addEventListener('click', (event) => {
            if (!searchForm.contains(event.target)) {
                hideSearchResults();
            }
        });

        searchForm.querySelectorAll('[data-desktop-search-scope-option]').forEach((button) => {
            button.addEventListener('click', () => {
                const value = button.getAttribute('data-desktop-search-scope-option') || 'tudo';
                const label = button.textContent?.trim() || 'Tudo';

                if (searchScopeValue instanceof HTMLInputElement) {
                    searchScopeValue.value = value;
                }

                if (searchScopeLabel instanceof HTMLElement) {
                    searchScopeLabel.textContent = label;
                }

                searchInput.focus();
                performSearchPreview();
            });
        });
    };

    const initPhotoFallbacks = () => {
        document.querySelectorAll('[data-photo-fallback]').forEach((img) => {
            img.addEventListener('error', () => {
                const frame = img.closest('a') ?? img;
                frame.classList.add('d-none');
                frame.nextElementSibling?.classList.remove('d-none');
            }, { once: true });
        });
    };

    const init = () => {
        initSidebar();
        initSidebarGroups();
        initConfigSubtabs();
        initPasswordToggles();
        initFlash();
        initNotifications();
        initConfirmForms();
        initPageTransitions();
        initModalFillers();
        initSelect2();
        initOsPreviewModals();
        initSearchAutocomplete();
        initPhotoFallbacks();
    };

    return { init, refreshSelect2, logError, sanitizeForLog };
})();

window.DesktopUi = DesktopUi;

document.addEventListener('DOMContentLoaded', DesktopUi.init);
