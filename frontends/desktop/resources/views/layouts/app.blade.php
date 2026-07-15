@php
    $desktopFlash = [
        'success' => session('success'),
        'error' => session('error'),
        'warning' => session('warning'),
        'info' => session('info'),
    ];
    $desktopSidebarHidden = $desktopSidebarHidden ?? request()->routeIs('orders.index', 'orders.create');
    $desktopSidebarCollapsed = $desktopSidebarCollapsed ?? false;
    $desktopEmbedded = (bool) ($desktopEmbedded ?? $embedded ?? request()->boolean('embedded'));
@endphp
<!DOCTYPE html>
<html lang="pt-BR" @if(session('desktop_theme') && session('desktop_theme') !== 'default') data-theme="{{ session('desktop_theme') }}"@endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'Desktop ERP' }} | Sistema ERP</title>
    <meta name="description" content="Painel administrativo do Sistema ERP: ordens de serviço, financeiro, estoque e clientes.">
    <link href="{{ asset('assets/fonts/plus-jakarta-sans/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/bootstrap-icons/bootstrap-icons.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/sweetalert2/sweetalert2.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/select2/select2.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/libs/select2-bootstrap-5-theme/select2-bootstrap-5-theme.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/desktop.css') }}?v={{ filemtime(public_path('assets/css/desktop.css')) }}" rel="stylesheet">
    @if (session('desktop_theme') && session('desktop_theme') !== 'default')
        <link href="{{ asset('assets/css/themes/' . e(session('desktop_theme')) . '.css') }}" rel="stylesheet">
    @endif
    @yield('styles')
</head>
<body class="desktop-body {{ $desktopEmbedded ? 'desktop-body-embedded' : '' }}">
    @if ($desktopEmbedded)
        <main class="desktop-embedded-shell">
            @include('layouts.partials.flash')
            @yield('content')
        </main>
    @else
        <div class="desktop-shell">
            @include('layouts.partials.sidebar')

            <div class="desktop-main {{ $desktopSidebarHidden ? 'is-full' : ($desktopSidebarCollapsed ? 'is-expanded' : '') }}" id="desktopMain">
                @include('layouts.partials.navbar')

                <main class="desktop-content">
                    @include('layouts.partials.flash')
                    @yield('content')
                </main>

                @php
                    $footerVersion = data_get($desktopSystemFooter, 'version', config('app.version', '3.0.0'));
                    $footerCopyright = data_get($desktopSystemFooter, 'copyright', '(c) ' . date('Y') . ' ' . config('app.name', 'Sistema ERP Desktop'));
                    $footerDevelopedBy = data_get($desktopSystemFooter, 'developed_by', 'Jovem Tech');
                @endphp

                <footer class="desktop-system-footer" aria-label="Rodape institucional do sistema">
                    <div class="desktop-system-footer-inner">
                        <div class="desktop-system-footer-meta">
                            <span class="desktop-version-pill" title="Versao atual do sistema">v{{ $footerVersion }}</span>
                            <span class="desktop-system-footer-credit">Desenvolvido por {{ $footerDevelopedBy }}</span>
                            <span class="desktop-system-footer-copyright">{{ $footerCopyright }}</span>
                        </div>
                    </div>
                </footer>
            </div>
        </div>
    @endif

    <div class="desktop-page-loader" data-desktop-page-loader hidden aria-hidden="true">
        <div class="desktop-page-loader-card" role="status" aria-live="polite" aria-busy="true">
            <span class="spinner-border text-primary desktop-page-loader-spinner" aria-hidden="true"></span>
            <div class="desktop-page-loader-copy">
                <strong>Carregando página</strong>
                <span>Preparando a próxima tela...</span>
            </div>
        </div>
    </div>

    <script>
        window.__DESKTOP_FLASH = {{ \Illuminate\Support\Js::from($desktopFlash) }};
        @if (!empty($desktopUser['id']))
        // Tempo real do sino de notificações (canal privado por usuário via Reverb).
        window.__DESKTOP_REALTIME = {{ \Illuminate\Support\Js::from([
            'userId' => (int) ($desktopUser['id'] ?? 0),
            'pusherKey' => env('REVERB_APP_KEY', ''),
            'pusherHost' => env('REVERB_HOST', 'localhost'),
            'pusherPort' => (int) env('REVERB_PORT', 8090),
            'pusherScheme' => env('REVERB_SCHEME', 'http'),
            'broadcastAuthUrl' => \Illuminate\Support\Facades\Route::has('desktop.broadcasting.auth') ? route('desktop.broadcasting.auth') : '',
            'notificationOpenUrlTemplate' => route('notifications.open', ['notification' => '__ID__']),
            'csrfToken' => csrf_token(),
        ]) }};
        @endif
    </script>
    @if (!empty($desktopSessionGuard['active']))
    <script>
        // Guard "fechar o navegador = deslogar" para sessões SEM "Manter-me
        // conectado". O cookie de sessão já nasce efêmero (morre ao fechar),
        // mas o Edge/Chrome com "Continuar de onde parei" restauram a aba
        // inteira (cookie + sessionStorage) ao reabrir. Detecção em duas
        // camadas, ambas em localStorage (que reflete hora real de parede):
        //   1. Marcador de fechamento: gravado no pagehide quando a saída NÃO
        //      é navegação interna → detecção instantânea, mesmo reabrindo
        //      em 2 segundos.
        //   2. Heartbeat (fallback p/ crash/kill sem pagehide): abas vivas
        //      batem a cada 3s; se o último beat está velho demais, o
        //      navegador esteve fechado.
        window.__DESKTOP_SESSION_GUARD = {{ \Illuminate\Support\Js::from([
            'justLoggedIn' => (bool) ($desktopSessionGuard['justLoggedIn'] ?? false),
            'warnOnClose' => (bool) ($desktopSessionGuard['warnOnClose'] ?? false),
            'logoutUrl' => route('logout'),
            'loginUrl' => route('login'),
            'csrfToken' => csrf_token(),
        ]) }};
        (function () {
            var cfg = window.__DESKTOP_SESSION_GUARD;
            if (!cfg) { return; }

            var HEARTBEAT_KEY = 'erpDesktopAlive';
            var CLOSED_KEY = 'erpDesktopClosedAt';
            var REAUTH_KEY = 'erpDesktopReauth';
            var PROBE_KEY = 'erpDesktopProbe';
            // Fallback por inatividade de beats (só pega crash/kill, onde o
            // pagehide não roda). Alto de propósito: abas em segundo plano têm
            // timers estrangulados a ~1/min pelo Chromium, então um beat pode
            // atrasar até ~60s com o navegador ABERTO — 90s evita deslogar
            // errado ao abrir nova aba enquanto as outras estão em background.
            var STALE_MS = 90000;

            var ss, ls;
            try { ss = window.sessionStorage; ls = window.localStorage; } catch (e) { return; }
            if (!ss || !ls) { return; }
            // Se o localStorage não persistir de verdade (modo restrito/cota), o
            // guard não funciona — desativa para não cair em laço de logout. O
            // timeout de inatividade no servidor continua protegendo.
            try { ls.setItem(PROBE_KEY, '1'); if (ls.getItem(PROBE_KEY) !== '1') { return; } ls.removeItem(PROBE_KEY); } catch (e) { return; }

            function now() { return Date.now(); }
            function beat() { try { ls.setItem(HEARTBEAT_KEY, String(now())); } catch (e) {} }
            function readInt(storage, key) {
                try { return parseInt(storage.getItem(key) || '0', 10) || 0; } catch (e) { return 0; }
            }

            // Sinaliza que a próxima saída da página é uma navegação legítima
            // dentro do sistema (clique em link, envio de form, F5, logout) —
            // nesses casos nem o aviso de fechamento nem o marcador de
            // fechamento devem ser acionados. A flag expira sozinha em 10s
            // (caso a navegação seja cancelada) e NÃO é consumida no
            // beforeunload, porque o pagehide ainda precisa lê-la depois.
            var internalNavigation = false;
            var internalNavTimer = null;
            function markInternalNavigation() {
                internalNavigation = true;
                if (internalNavTimer) { clearTimeout(internalNavTimer); }
                internalNavTimer = setTimeout(function () { internalNavigation = false; }, 10000);
            }

            function forceLogout() {
                markInternalNavigation();
                if (window.console && console.info) { console.info('[ERP Sessão] Navegador foi fechado e reaberto — encerrando a sessão.'); }
                var done = function () { window.location.replace(cfg.loginUrl); };
                try {
                    fetch(cfg.logoutUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-CSRF-TOKEN': cfg.csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'reopened=1'
                    }).then(done, done);
                } catch (e) { done(); }
            }

            // Recarregar (F5, botão do navegador ou Ctrl+R) nunca é "navegador
            // fechado" — o tipo de navegação identifica isso com precisão,
            // inclusive o botão de reload da barra (que não passa pelo keydown).
            var navType = 'navigate';
            try {
                var navEntries = performance.getEntriesByType('navigation');
                if (navEntries && navEntries[0] && navEntries[0].type) { navType = navEntries[0].type; }
            } catch (e) {}

            var lastBeat = readInt(ls, HEARTBEAT_KEY);
            var closedAt = readInt(ls, CLOSED_KEY);
            // Fechado se: marcador de fechamento sem nenhum beat posterior de
            // outra aba (tolerância de 4s = um ciclo de beat + folga), OU
            // beats parados há tempo demais (crash), OU nunca houve beat
            // (localStorage limpo / sessão restaurada de outra máquina).
            var markedClosed = closedAt > 0 && closedAt >= (lastBeat - 4000);
            var staleClosed = !lastBeat || (now() - lastBeat) > STALE_MS;
            var browserWasClosed = navType !== 'reload' && (markedClosed || staleClosed);

            if (window.console && console.info) {
                console.info('[ERP Sessão] guard: navType=' + navType
                    + ' idadeUltimoBeat=' + (lastBeat ? (now() - lastBeat) + 'ms' : 'nunca')
                    + ' marcadorFechamento=' + (closedAt > 0 ? 'sim' : 'não')
                    + ' decisão=' + (browserWasClosed ? 'FECHOU' : 'vivo')
                    + (cfg.justLoggedIn ? ' (login recente, check ignorado)' : ''));
            }

            if (!cfg.justLoggedIn && browserWasClosed) {
                // Encerra — a menos que já tenhamos tentado nesta aba (evita
                // laço caso o logout do servidor falhe).
                var triedReauth = false;
                try { triedReauth = ss.getItem(REAUTH_KEY) === '1'; } catch (e) {}
                if (!triedReauth) {
                    try { ss.setItem(REAUTH_KEY, '1'); } catch (e) {}
                    forceLogout();
                    return;
                }
            }

            try { ss.removeItem(REAUTH_KEY); } catch (e) {}
            try { ls.removeItem(CLOSED_KEY); } catch (e) {}
            beat();
            setInterval(beat, 3000);
            window.addEventListener('focus', beat);
            document.addEventListener('visibilitychange', function () { if (!document.hidden) { beat(); } });

            // Rastreamento de navegação interna — SEMPRE ativo (o pagehide
            // abaixo depende dele, com ou sem o aviso de fechamento ligado).
            document.addEventListener('click', function (event) {
                var el = event.target;
                while (el && el !== document) {
                    if (el.tagName === 'A' && el.getAttribute('href')) {
                        var href = el.getAttribute('href');
                        var target = (el.getAttribute('target') || '').toLowerCase();
                        var isBlankOrDownload = target === '_blank' || el.hasAttribute('download');
                        var isHashOrScheme = href.indexOf('#') === 0
                            || href.indexOf('javascript:') === 0
                            || href.indexOf('mailto:') === 0
                            || href.indexOf('tel:') === 0;
                        if (!isBlankOrDownload && !isHashOrScheme) {
                            var sameHost = true;
                            try { sameHost = new URL(el.href, window.location.href).host === window.location.host; } catch (e) {}
                            if (sameHost) { markInternalNavigation(); }
                        }
                        return;
                    }
                    el = el.parentNode;
                }
            }, true);

            document.addEventListener('submit', markInternalNavigation, true);

            window.addEventListener('keydown', function (event) {
                var key = event.key;
                if (key === 'F5' || ((event.ctrlKey || event.metaKey) && (key === 'r' || key === 'R'))) {
                    markInternalNavigation();
                }
            });

            // Marcador de fechamento: pagehide dispara na saída da página; se
            // NÃO foi navegação interna, é fechamento de aba/janela (ou saída
            // para outro site) — grava o instante. Na próxima carga, se nenhum
            // beat de outra aba veio depois do marcador, o navegador esteve
            // fechado — detecção instantânea, sem esperar staleness.
            window.addEventListener('pagehide', function () {
                if (!internalNavigation) {
                    try { ls.setItem(CLOSED_KEY, String(now())); } catch (e) {}
                }
            });

            // Volta via bfcache (botão Voltar): o navegador nunca fechou —
            // limpa o marcador e retoma os beats.
            window.addEventListener('pageshow', function (event) {
                if (event.persisted) {
                    try { ls.removeItem(CLOSED_KEY); } catch (e) {}
                    beat();
                }
            });

            // Aviso nativo ao fechar com sessão ativa (opcional, configurável).
            if (cfg.warnOnClose) {
                window.addEventListener('beforeunload', function (event) {
                    if (internalNavigation) {
                        return undefined;
                    }
                    // Mensagem genérica (navegadores modernos ignoram texto
                    // customizado e mostram o próprio diálogo padrão).
                    event.preventDefault();
                    event.returnValue = '';
                    return '';
                });
            }
        })();
    </script>
    @endif
    @stack('modals')
    <script src="{{ asset('assets/libs/jquery/jquery-3.7.1.min.js') }}"></script>
    <script src="{{ asset('assets/libs/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('assets/libs/select2/select2.min.js') }}"></script>
    <script src="{{ asset('assets/libs/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/libs/pusher-js/pusher.min.js') }}"></script>
    <script src="{{ asset('assets/js/desktop.js') }}?v={{ filemtime(public_path('assets/js/desktop.js')) }}"></script>
    @yield('scripts')
</body>
</html>
