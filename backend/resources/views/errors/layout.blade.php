<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') | {{ config('app.name', 'Sistema ERP') }}</title>
    @include('partials.favicon')
    <style>
        :root {
            color-scheme: light;
            --bg: #eef4ff;
            --card: rgba(255,255,255,.96);
            --border: rgba(56, 104, 176, 0.14);
            --text: #12233f;
            --muted: #5c6f8d;
            --primary: #3868b0;
            --primary-soft: rgba(56, 104, 176, 0.12);
            --warning: #b45309;
            --warning-soft: rgba(180, 83, 9, 0.12);
            --danger: #dc2626;
            --danger-soft: rgba(220, 38, 38, 0.12);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            font-family: "Segoe UI", Arial, sans-serif;
            background:
                radial-gradient(circle at top right, rgba(111, 90, 252, 0.12), transparent 34%),
                linear-gradient(180deg, #f7fbff 0%, var(--bg) 100%);
            color: var(--text);
        }
        .card {
            width: min(560px, 100%);
            padding: 32px;
            text-align: center;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: 0 22px 44px rgba(15, 23, 42, 0.08);
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 18px;
            padding: 7px 14px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--muted);
            background: var(--primary-soft);
        }
        .badge.tone-warning { color: var(--warning); background: var(--warning-soft); }
        .badge.tone-danger { color: var(--danger); background: var(--danger-soft); }
        .title {
            margin: 0 0 12px;
            font-size: clamp(24px, 4vw, 32px);
            line-height: 1.15;
        }
        .message {
            margin: 0;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.65;
        }
        .hint {
            margin: 22px 0 0;
            padding-top: 18px;
            border-top: 1px solid var(--border);
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }
        .code {
            margin: 20px 0 0;
            color: var(--muted);
            font-size: 12px;
            letter-spacing: .08em;
        }
    </style>
</head>
<body>
    <main class="card">
        <span class="badge tone-{{ $tone ?? 'primary' }}">{{ config('app.name', 'Sistema ERP') }}</span>
        <h1 class="title">@yield('title')</h1>
        <p class="message">@yield('message')</p>
        @hasSection('hint')
            <p class="hint">@yield('hint')</p>
        @endif
        <p class="code">Código {{ $code ?? '' }}</p>
    </main>
</body>
</html>
