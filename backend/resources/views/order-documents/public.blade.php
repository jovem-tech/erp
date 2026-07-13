<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Documentos da OS {{ $order['numero_os'] ?? '' }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, Arial, sans-serif; background: #eff6ff; color: #0f172a; }
        .page { max-width: 960px; margin: 0 auto; padding: 24px; }
        .card { background: #fff; border: 1px solid #dbeafe; border-radius: 24px; padding: 24px; box-shadow: 0 24px 48px rgba(15, 23, 42, .08); }
        .eyebrow { display: inline-flex; padding: 8px 14px; border-radius: 999px; background: #eff6ff; color: #1d4ed8; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        h1 { margin: 16px 0 8px; font-size: 32px; line-height: 1.1; }
        .muted { color: #475569; }
        .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-top: 24px; }
        .meta { padding: 16px 18px; border: 1px solid #dbeafe; border-radius: 18px; background: #f8fbff; }
        .meta strong { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: #334155; margin-bottom: 6px; }
        .doc-list { margin-top: 24px; display: grid; gap: 16px; }
        .doc-card { border: 1px solid #dbeafe; border-radius: 20px; padding: 18px; background: #fff; }
        .doc-card h2 { margin: 0 0 6px; font-size: 20px; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; padding: 0 16px; border-radius: 999px; text-decoration: none; font-weight: 700; }
        .btn-primary { background: #1d4ed8; color: #fff; }
        .btn-secondary { background: #eff6ff; color: #1e3a8a; border: 1px solid #bfdbfe; }
        .footer { margin-top: 24px; font-size: 13px; color: #64748b; }
    </style>
</head>
<body>
    <main class="page">
        <section class="card">
            <span class="eyebrow">Documentos compartilhados</span>
            <h1>OS {{ $order['numero_os'] ?? '' }}</h1>
            <p class="muted">Acesso seguro aos documentos selecionados pela assistência. Este link não deve ser publicado em ambientes abertos.</p>

            <div class="grid">
                <div class="meta">
                    <strong>Cliente</strong>
                    <span>{{ $order['cliente_nome'] ?? 'Não informado' }}</span>
                </div>
                <div class="meta">
                    <strong>Equipamento</strong>
                    <span>{{ $order['equipamento'] ?? 'Não informado' }}</span>
                </div>
                <div class="meta">
                    <strong>Expira em</strong>
                    <span>{{ \Illuminate\Support\Carbon::parse($link['expires_at'] ?? now())->format('d/m/Y H:i') }}</span>
                </div>
            </div>

            <div class="doc-list">
                @foreach ($documents as $document)
                    <article class="doc-card">
                        <h2>{{ $document['label'] ?? 'Documento' }}</h2>
                        <p class="muted">Versão {{ $document['version'] ?? 1 }}</p>

                        <div class="actions">
                            @foreach (($document['files'] ?? []) as $file)
                                <a class="btn {{ ($file['format'] ?? '') === '80mm' ? 'btn-secondary' : 'btn-primary' }}"
                                   href="{{ $file['url'] ?? '#' }}"
                                   target="_blank"
                                   rel="noreferrer">
                                    Abrir {{ $file['label'] ?? strtoupper($file['format'] ?? 'PDF') }}
                                </a>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </div>

            <p class="footer">Se precisar de outra versão, reenvio ou novo link, solicite diretamente à assistência.</p>
        </section>
    </main>
</body>
</html>
