{{--
    RELAÇÃO DE DOCUMENTOS FISCAIS EMITIDOS — anexo ao Relatório Mensal das
    Receitas Brutas (Res. CGSN 140/2018, art. 106).

    Arquivo SEPARADO do formulário de propósito. A última cláusula do Anexo X
    manda anexar as notas emitidas ao relatório — anexar, não embutir. O
    formulário (pdf.anexo-x) não recebe nenhuma seção a mais.

    Canceladas aparecem identificadas em vez de omitidas: um buraco na
    sequência numérica é o primeiro lugar onde a fiscalização olha.
--}}
<style>
    @page { margin: 16mm 14mm; }

    body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; color: #000; margin: 0; }

    .cabecalho { margin-bottom: 12px; }
    .cabecalho h1 { font-size: 12pt; margin: 0 0 2px; }
    .cabecalho .subtitulo { font-size: 8.5pt; margin-bottom: 6px; }
    .cabecalho .empresa { font-size: 8pt; color: #333; }

    table { width: 100%; border-collapse: collapse; }

    .documentos th {
        border: 1px solid #000;
        background: #ececec;
        padding: 4px 5px;
        font-size: 7.5pt;
        text-align: left;
    }
    .documentos td {
        border: 1px solid #666;
        padding: 3px 5px;
        vertical-align: top;
    }
    .documentos .valor { text-align: right; white-space: nowrap; }
    .documentos .centro { text-align: center; white-space: nowrap; }
    .documentos tr.cancelada td { color: #a11; text-decoration: line-through; }
    .documentos tr.cancelada td.situacao { text-decoration: none; font-weight: bold; }

    .totais { margin-top: 10px; }
    .totais td { padding: 3px 5px; }
    .totais .rotulo { text-align: right; }
    .totais .valor { text-align: right; width: 120px; font-weight: bold; white-space: nowrap; }

    .vazio { margin-top: 14px; padding: 10px; border: 1px dashed #666; text-align: center; }

    .criterio {
        margin-top: 16px;
        padding-top: 5px;
        border-top: 1px solid #999;
        font-size: 7.5pt;
        color: #444;
    }
</style>

<div class="cabecalho">
    <h1>{{ $relacao['titulo'] }}</h1>
    <div class="subtitulo">{{ $relacao['subtitulo'] }}</div>
    <div class="empresa">{{ $relacao['empreendedor'] }} — CNPJ {{ $relacao['cnpj'] }}</div>
</div>

@if ($relacao['vazio'])
    <div class="vazio">Nenhum documento fiscal emitido no período.</div>
@else
    <table class="documentos">
        <thead>
            <tr>
                <th style="width: 46px;">Tipo</th>
                <th style="width: 34px;">Série</th>
                <th style="width: 60px;">Número</th>
                <th style="width: 60px;">Emissão</th>
                <th>Tomador</th>
                <th style="width: 105px;">CPF/CNPJ</th>
                <th style="width: 95px;">Origem</th>
                <th style="width: 80px;">Valor</th>
                <th style="width: 62px;">Situação</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($relacao['documentos'] as $documento)
                <tr @class(['cancelada' => $documento['cancelado']])>
                    <td>{{ $documento['tipo'] }}</td>
                    <td class="centro">{{ $documento['serie'] }}</td>
                    <td class="centro">{{ $documento['numero'] }}</td>
                    <td class="centro">{{ $documento['emitido_em'] }}</td>
                    <td>{{ $documento['tomador'] }}</td>
                    <td>{{ $documento['tomador_documento'] }}</td>
                    <td>{{ $documento['origem'] }}</td>
                    <td class="valor">{{ $documento['valor'] }}</td>
                    <td class="situacao centro">{{ $documento['situacao'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totais">
        <tr>
            <td class="rotulo">Documentos relacionados</td>
            <td class="valor">{{ $relacao['totais']['quantidade'] }}</td>
        </tr>
        <tr>
            <td class="rotulo">Total emitido (exclui canceladas)</td>
            <td class="valor">{{ $relacao['totais']['geral'] }}</td>
        </tr>
        @if ($relacao['totais']['quantidade_canceladas'] > 0)
            <tr>
                <td class="rotulo">Canceladas ({{ $relacao['totais']['quantidade_canceladas'] }})</td>
                <td class="valor">{{ $relacao['totais']['canceladas'] }}</td>
            </tr>
        @endif
    </table>
@endif

<div class="criterio">
    <div>{{ $relacao['criterio'] }}</div>
    <div>
        As colunas II, V e VIII do Anexo X classificam a OPERAÇÃO, e esta relação classifica a EMISSÃO.
        Uma nota emitida no mês seguinte ao da operação aparece nesta relação em um mês e naquelas colunas em outro.
    </div>
</div>
