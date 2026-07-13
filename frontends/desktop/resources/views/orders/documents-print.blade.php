<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Impressão documental {{ $order['numero_os'] ?? '' }}</title>
    <style>
        body { margin: 0; font-family: Inter, Arial, sans-serif; background: #f8fbff; color: #0f172a; }
        .page { max-width: 1120px; margin: 0 auto; padding: 24px; }
        .header { margin-bottom: 18px; }
        .header h1 { margin: 0 0 8px; font-size: 28px; }
        .header p { margin: 0; color: #475569; }
        .stack { display: flex; flex-direction: column; gap: 18px; }
        .doc-card { background: #fff; border: 1px solid #dbeafe; border-radius: 20px; padding: 16px; box-shadow: 0 12px 24px rgba(15, 23, 42, .06); }
        .doc-card h2 { margin: 0 0 6px; font-size: 20px; }
        .doc-card p { margin: 0 0 12px; color: #64748b; }
        .doc-frame { width: 100%; min-height: {{ $format === '80mm' ? '420px' : '960px' }}; border: 1px solid #dbeafe; border-radius: 16px; background: #fff; }
        @media print {
            .header { display: none; }
            .page { max-width: none; padding: 0; }
            .doc-card { border: none; box-shadow: none; padding: 0; margin: 0 0 12px; }
            .doc-frame { border: none; min-height: {{ $format === '80mm' ? '420px' : '1000px' }}; }
        }
    </style>
</head>
<body>
    <main class="page">
        <header class="header">
            <h1>Fila visual de impressão · {{ $order['numero_os'] ?? ('OS #' . ($order['id'] ?? 0)) }}</h1>
            <p>Layout {{ strtoupper($format) }}. O diálogo de impressão do navegador será aberto automaticamente.</p>
        </header>

        <section class="stack">
            @foreach ($documents as $document)
                <article class="doc-card">
                    <h2>{{ $document['label'] ?? 'Documento' }}</h2>
                    <p>Versão {{ $document['version'] ?? 1 }}</p>
                    <iframe src="{{ $document['print_url'] ?? '#' }}" class="doc-frame" loading="lazy"></iframe>
                </article>
            @endforeach
        </section>
    </main>

    <script>
        window.addEventListener('load', () => {
            window.setTimeout(() => window.print(), 900);
        });
    </script>
</body>
</html>
