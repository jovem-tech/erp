<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle }} · Jovem Tech OS</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #102f59; background: #eef4fc; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: radial-gradient(circle at top, #fff 0, #eef4fc 55%, #e5eefb 100%); }
        .page { width: min(760px, calc(100% - 24px)); margin: 0 auto; padding: max(24px, env(safe-area-inset-top)) 0 max(30px, env(safe-area-inset-bottom)); }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
        .brand img { width: 48px; height: 48px; object-fit: contain; }
        .brand strong, .brand span { display: block; }
        .brand span { color: #6b7d97; font-size: 13px; }
        .card { background: rgba(255,255,255,.96); border: 1px solid #d9e4f4; border-radius: 22px; box-shadow: 0 18px 50px rgba(35, 72, 125, .12); padding: clamp(20px, 5vw, 36px); }
        h1 { margin: 0 0 8px; font-size: clamp(24px, 5vw, 34px); }
        p { line-height: 1.55; }
        .meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin: 20px 0; }
        .meta div { background: #f4f8fe; border-radius: 14px; padding: 12px; }
        .meta small, .meta strong { display: block; }
        .meta small { color: #6b7d97; margin-bottom: 3px; }
        label { display: block; font-weight: 700; margin-bottom: 7px; }
        input[type=text] { width: 100%; min-height: 48px; border: 1px solid #c8d7ec; border-radius: 12px; padding: 0 14px; font: inherit; }
        .canvas-shell { position: relative; height: 260px; border: 2px dashed #9fb9df; border-radius: 16px; background: #fff; overflow: hidden; }
        canvas { display: block; width: 100%; height: 260px; touch-action: none; }
        .hint { position: absolute; inset: 0; display: grid; place-items: center; text-align: center; color: #8393aa; padding: 20px; pointer-events: none; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
        button { appearance: none; border: 1px solid #b8cbe7; background: #fff; color: #174b91; border-radius: 12px; min-height: 44px; padding: 0 16px; font: inherit; font-weight: 700; }
        button.primary { background: #3372c6; color: #fff; border-color: #3372c6; width: 100%; min-height: 52px; margin-top: 18px; }
        .consent { display: grid; grid-template-columns: auto 1fr; gap: 10px; margin-top: 20px; font-weight: 400; }
        .consent input { width: 20px; height: 20px; }
        .message { padding: 18px; border-radius: 15px; background: #edf9f3; color: #17663b; }
        .error { background: #fff0f0; color: #a22d2d; }
        [hidden] { display: none !important; }
        @media (max-width: 520px) { .meta { grid-template-columns: 1fr; } .canvas-shell, canvas { height: 220px; } }
    </style>
</head>
<body>
<main class="page">
    <div class="brand">
        <img src="{{ route('branding.company.logo') }}" alt="Logo da empresa">
        <div><strong>Jovem Tech OS</strong><span>Assinatura segura de documento</span></div>
    </div>
    <section class="card">
        @if ($signed)
            <h1>Assinatura concluída</h1>
            <div class="message">Sua assinatura foi registrada. O documento foi emitido com a rubrica e a trilha de auditoria.</div>
            <p>OS: <strong>{{ $signatureRequest['order_number'] ?? '' }}</strong></p>
        @elseif (isset($publicError))
            <h1>Link indisponível</h1>
            <div class="message error">{{ $publicError }}</div>
        @else
            <h1>Assinar documento</h1>
            <p>Confira os dados abaixo e faça sua rubrica no campo indicado.</p>
            <div class="meta">
                <div><small>Ordem de serviço</small><strong>{{ $signatureRequest['order_number'] ?? '' }}</strong></div>
                <div><small>Documento</small><strong>{{ $signatureRequest['document_type'] ?? '' }}</strong></div>
                <div><small>Cliente</small><strong>{{ $signatureRequest['client_name'] ?? '' }}</strong></div>
                <div><small>Solicitado por</small><strong>{{ $signatureRequest['company_user'] ?? '' }}</strong></div>
            </div>

            <form method="post" action="{{ route('document-signatures.public.store', $token) }}" data-public-signature-form>
                @csrf
                <input type="hidden" name="signature_data" data-signature-data>
                <div>
                    <label for="customerSignerName">Nome completo</label>
                    <input type="text" id="customerSignerName" name="name" value="{{ old('name', $signatureRequest['client_name'] ?? '') }}" maxlength="160" autocomplete="name" required>
                </div>
                <div style="margin-top:18px">
                    <label>Sua rubrica</label>
                    <div class="canvas-shell">
                        <canvas data-signature-canvas aria-label="Campo de assinatura"></canvas>
                        <span class="hint" data-signature-hint>Assine aqui com o dedo ou Apple Pencil</span>
                    </div>
                    <div class="actions">
                        <button type="button" data-signature-undo>Desfazer</button>
                        <button type="button" data-signature-clear>Limpar</button>
                    </div>
                </div>
                <label class="consent">
                    <input type="checkbox" name="consent" value="1" required>
                    <span>Declaro que revisei os dados apresentados e concordo em registrar esta rubrica eletrônica, vinculada à OS e ao documento informados.</span>
                </label>
                <button type="submit" class="primary">Assinar e concluir</button>
            </form>
        @endif
    </section>
</main>
@if (! $signed && ! isset($publicError))
<script src="{{ asset('assets/js/public-document-signature.js') }}?v={{ filemtime(public_path('assets/js/public-document-signature.js')) }}"></script>
@endif
</body>
</html>
