{{-- Central PDF shell. Content is pre-rendered and escaped by PdfTemplateRenderer;
     this file controls only the light, modern presentation for A4 and 80mm. --}}
@php
    $mm = static fn ($valor, $padrao) => is_numeric($valor ?? null) ? (float) $valor : $padrao;
    $margemTopo = $mm($margens['topo'] ?? null, $formato === '80mm' ? 4 : 12);
    $margemBaixo = $mm($margens['baixo'] ?? null, $formato === '80mm' ? 6 : 14);
    $margemEsq = $mm($margens['esq'] ?? null, $formato === '80mm' ? 3 : 11);
    $margemDir = $mm($margens['dir'] ?? null, $formato === '80mm' ? 3 : 11);
    $isThermal = $formato === '80mm';
    $margemBaixoEfetiva = $isThermal ? $margemBaixo : max($margemBaixo, 18);
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: {{ $margemTopo }}mm {{ $margemDir }}mm {{ $margemBaixoEfetiva }}mm {{ $margemEsq }}mm; }
        * { box-sizing: border-box; }
        body {
            font-family: "{{ $fonte }}", DejaVu Sans, sans-serif;
            color: #172033;
            background: #ffffff;
            margin: 0;
            padding: 0;
            @if ($isThermal)
            font-size: 9.5px;
            line-height: 1.4;
            @else
            font-size: 12px;
            line-height: 1.5;
            @endif
        }

        .pdfe-header { margin-bottom: {{ $isThermal ? '6px' : '4px' }}; }
        .pdfe-body { margin-top: {{ $isThermal ? '0' : '4px' }}; }
        .pdfe-footer { page-break-inside: avoid; }
        .pdfe-paragrafo { margin: 0 0 6px; }
        .pdfe-campo { margin: 0 0 4px; }
        .pdfe-campo .rotulo { font-weight: 700; color: #3f4d5e; }
        .pdfe-subtitulo { margin: 0 0 6px; color: #334155; }

        @if ($isThermal)
            .pdfe-footer {
                margin-top: 8px;
                padding-top: 6px;
                border-top: 1px dashed #64748b;
                color: #475569;
                font-size: 8.5px;
            }
            .pdfe-titulo {
                position: static;
                margin: 0 0 5px;
                padding: 5px 6px;
                border-left: 3px solid #334155;
                background: #f1f5f9;
                color: #172033;
                font-size: 13px;
                line-height: 1.2;
            }
            .pdfe-titulo-decor { display: none; }
            .pdfe-titulo + .pdfe-paragrafo {
                margin: 0 0 6px;
                padding: 0 0 6px;
                background: none;
                color: #475569;
            }
            .pdfe-subtitulo { font-size: 11px; }
            .pdfe-secao {
                margin: 8px 0 4px;
                padding: 2px 0;
                border-bottom: 1px solid #94a3b8;
                color: #172033;
                font-weight: 700;
                font-size: 9.5px;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            table.pdfe-tabela th { background: #f1f5f9; color: #172033; font-size: 8.5px; }
        @else
            .pdfe-footer {
                position: fixed;
                right: 0;
                bottom: -10mm;
                left: 0;
                margin: 0;
                padding: 1px 6px;
                border: 1px solid #dbe4ef;
                border-top: 2px solid #7ba3d1;
                border-radius: 8px;
                background: #f8fafc;
                color: #526071;
                font-size: 8px;
                line-height: 1.05;
            }
            .pdfe-footer .pdfe-paragrafo { display: block; margin: 0; color: #526071; text-align: center; }
            .pdfe-footer .pdfe-divisor { display: none; }
            .pdfe-titulo {
                position: relative;
                overflow: hidden;
                margin: 0;
                padding: 14px 18px 7px;
                border: 1px solid #dbe7f3;
                border-bottom: 0;
                border-left: 4px solid #4c7fb8;
                border-radius: 9px 9px 0 0;
                background: #f3f7fb;
                color: #183b63;
                font-size: 20px;
                line-height: 1.2;
            }
            .pdfe-titulo-decor { display: none; }
            .pdfe-titulo + .pdfe-paragrafo {
                margin: 0 0 14px;
                padding: 0 18px 12px;
                border: 1px solid #dbe7f3;
                border-top: 0;
                border-left: 4px solid #4c7fb8;
                border-radius: 0 0 9px 9px;
                background: #f3f7fb;
                color: #526071;
                font-size: 11px;
            }
            .pdfe-subtitulo { font-size: 15px; }
            .pdfe-header .pdfe-imagem img { max-width: 110px !important; max-height: 110px; }
            .pdfe-secao {
                margin: 16px 0 7px;
                padding: 6px 9px;
                border-left: 3px solid #8aadd3;
                border-bottom: 1px solid #dbe7f3;
                border-radius: 5px;
                background: #f6f9fc;
                color: #294f79;
                font-weight: 700;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            table.pdfe-tabela th {
                background: #edf3f9;
                color: #294f79;
                font-weight: 700;
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 0.04em;
            }
        @endif

        table.pdfe-grade { width: 100%; table-layout: fixed; border-collapse: collapse; margin-bottom: 8px; }
        table.pdfe-grade td {
            padding: {{ $isThermal ? '3px 4px' : '6px 9px' }};
            border: 1px solid #dce5ee;
            vertical-align: top;
            word-wrap: break-word;
        }
        table.pdfe-grade td.rotulo { width: 26%; background: #f5f7fa; color: #3f4d5e; font-weight: 700; }
        table.pdfe-colunas { width: 100%; border-collapse: collapse; }
        table.pdfe-colunas > tbody > tr > td { padding: 0 6px 0 0; vertical-align: top; }
        table.pdfe-colunas > tbody > tr > td + td { padding: 0 0 0 6px; }
        table.pdfe-tabela { width: 100%; border-collapse: collapse; margin: 8px 0; }
        table.pdfe-tabela tr, table.pdfe-totais tr { page-break-inside: avoid; }
        table.pdfe-tabela th {
            padding: {{ $isThermal ? '3px 4px' : '7px 9px' }};
            border: 1px solid #dce5ee;
            text-align: left;
        }
        table.pdfe-tabela td {
            padding: {{ $isThermal ? '3px 4px' : '7px 9px' }};
            border: 1px solid #dce5ee;
            vertical-align: top;
        }
        table.pdfe-tabela tr.total td { background: #f3f6f9; font-weight: 700; }
        table.pdfe-tabela tr.total.destaque td {
            border-top: 2px solid #6f99c7;
            background: #e7f0f9;
            color: #183b63;
            font-size: {{ $isThermal ? '10px' : '13px' }};
        }
        table.pdfe-totais { width: {{ $isThermal ? '100%' : '52%' }}; margin: 8px 0 0 auto; border-collapse: collapse; }
        table.pdfe-totais td { padding: {{ $isThermal ? '3px 4px' : '6px 9px' }}; border: 1px solid #dce5ee; }
        table.pdfe-totais td.rotulo { background: #f5f7fa; color: #3f4d5e; font-weight: 700; }
        table.pdfe-totais td.valor { text-align: right; }
        table.pdfe-totais tr.destaque td {
            border-top: 2px solid #6f99c7;
            background: #e7f0f9;
            color: #183b63;
            font-weight: 700;
            font-size: {{ $isThermal ? '10.5px' : '14px' }};
        }
        ul.pdfe-lista, ol.pdfe-lista { margin: 4px 0 8px; padding-left: 18px; }
        ul.pdfe-lista li, ol.pdfe-lista li { margin-bottom: 3px; }
        .pdfe-divisor { margin: 8px 0; border-top-style: {{ $isThermal ? 'dashed' : 'solid' }}; border-top-color: #94a3b8; }
        .pdfe-observacoes {
            margin: 8px 0;
            padding: {{ $isThermal ? '5px 6px' : '12px 14px' }};
            border: 1px solid #d7e3ef;
            border-left: 3px solid #8aadd3;
            border-radius: 7px;
            background: {{ $isThermal ? '#fafafa' : '#f7fafc' }};
        }
        .pdfe-assinatura { width: 100%; margin-top: {{ $isThermal ? '18px' : '38px' }}; border-collapse: collapse; }
        .pdfe-assinatura td { padding: 0 12px; text-align: center; vertical-align: top; }
        .pdfe-assinatura .imagem-assinatura { height: {{ $isThermal ? '40px' : '58px' }}; text-align: center; }
        .pdfe-assinatura .imagem-assinatura img { display: inline-block; max-width: {{ $isThermal ? '105px' : '180px' }}; max-height: {{ $isThermal ? '38px' : '54px' }}; object-fit: contain; }
        .pdfe-assinatura .linha {
            padding-top: 4px;
            border-top: 1px solid #172033;
            color: #3f4d5e;
            font-size: {{ $isThermal ? '8.5px' : '10px' }};
        }
        .pdfe-assinatura .identificacao { height: {{ $isThermal ? '22px' : '26px' }}; line-height: {{ $isThermal ? '11px' : '13px' }}; overflow: hidden; }
        .pdfe-assinatura .data-assinatura { height: {{ $isThermal ? '11px' : '13px' }}; line-height: {{ $isThermal ? '11px' : '13px' }}; }
        .pdfe-imagem { margin-bottom: 6px; }
        table.pdfe-galeria-fotos { width: 100%; border-collapse: collapse; margin: 4px 0 8px; }
        table.pdfe-galeria-fotos td { padding: 0 4px; vertical-align: top; }
        table.pdfe-galeria-fotos td:first-child { padding-left: 0; }
        table.pdfe-galeria-fotos td:last-child { padding-right: 0; }
        .pdfe-galeria-fotos-item {
            width: 100%;
            height: 130px;
            background-repeat: no-repeat;
            background-position: center;
            background-size: cover;
            border: 1px solid #d7e3ef;
            border-radius: 4px;
        }
        .muted { color: #526071; }
        .empty-box, .info-box {
            margin: 4px 0;
            padding: 8px 10px;
            border: 1px solid #d7e3ef;
            border-radius: 7px;
            background: #f7fafc;
        }
        .list { margin: 4px 0; padding-left: 18px; }
        table.checklist-table { width: 100%; margin-top: 6px; border-collapse: collapse; }
        table.checklist-table th {
            padding: 4px 6px;
            border: 1px solid #dce5ee;
            background: #edf3f9;
            color: #294f79;
            text-align: left;
            font-size: {{ $isThermal ? '8.5px' : '10px' }};
        }
        table.checklist-table td { padding: 4px 6px; border: 1px solid #dce5ee; vertical-align: top; }
    </style>
</head>
<body>
    <div class="pdfe-header">{!! $cabecalhoHtml !!}</div>
    <div class="pdfe-body">{!! $corpoHtml !!}</div>
    <div class="pdfe-footer">{!! $rodapeHtml !!}</div>
</body>
</html>
