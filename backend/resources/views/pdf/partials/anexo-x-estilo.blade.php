{{-- Estilo do formulário do Anexo X, compartilhado pelo PDF mensal e pelo
     bloco anual: duas folhas do mesmo documento não podem ter aparências
     diferentes. --}}
<style>
    @page { margin: 18mm 16mm; }

    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 9.5pt;
        color: #000;
        margin: 0;
    }

    .cabecalho { text-align: center; margin-bottom: 14px; }
    .cabecalho h1 { font-size: 13pt; margin: 0 0 3px; letter-spacing: .3px; }
    .cabecalho .fundamento { font-size: 8pt; }

    table { width: 100%; border-collapse: collapse; }

    .identificacao td {
        border: 1px solid #000;
        padding: 5px 7px;
        vertical-align: top;
    }
    .identificacao .rotulo {
        display: block;
        font-size: 7pt;
        text-transform: uppercase;
        letter-spacing: .3px;
        margin-bottom: 2px;
    }
    .identificacao .valor { font-size: 10pt; }

    .bloco { margin-top: 12px; }
    .bloco .titulo {
        border: 1px solid #000;
        border-bottom: none;
        padding: 4px 7px;
        font-size: 8.5pt;
        font-weight: bold;
        background: #ececec;
    }
    .linhas td {
        border: 1px solid #000;
        padding: 4px 7px;
        vertical-align: top;
    }
    .linhas .numeral { width: 34px; text-align: center; }
    .linhas .valor { width: 120px; text-align: right; white-space: nowrap; }
    .linhas tr.total td { font-weight: bold; background: #f6f6f6; }

    .total-geral { margin-top: 12px; }
    .total-geral td {
        border: 2px solid #000;
        padding: 7px;
        font-weight: bold;
        font-size: 10.5pt;
    }
    .total-geral .numeral { width: 34px; text-align: center; }
    .total-geral .valor { width: 120px; text-align: right; white-space: nowrap; }

    .negativo { color: #a11; }

    .assinatura { margin-top: 26px; }
    .assinatura .local { margin-bottom: 26px; }
    .assinatura .linha-assinatura {
        border-top: 1px solid #000;
        width: 62%;
        margin: 0 auto;
        padding-top: 4px;
        text-align: center;
        font-size: 8.5pt;
    }

    .anexos { margin-top: 22px; font-size: 8.5pt; }
    .anexos .intro { font-weight: bold; margin-bottom: 4px; }
    .anexos ul { margin: 0; padding-left: 16px; }
    .anexos li { margin-bottom: 2px; }

    .rodape {
        margin-top: 20px;
        padding-top: 5px;
        border-top: 1px solid #999;
        font-size: 7.5pt;
        color: #444;
    }
</style>
