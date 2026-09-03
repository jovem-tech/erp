{{--
    Corpo do formulário oficial do Anexo X — Res. CGSN nº 140/2018, art. 106.

    Vive num partial porque é impresso por DOIS documentos: o PDF de um mês
    (pdf.anexo-x) e o bloco anual com uma folha por mês (pdf.anexo-x-anual).
    Duas cópias divergiriam na primeira correção feita só de um lado, e a folha
    divergente continuaria sendo entregue ao fisco como se fosse o formulário.

    Não acrescente nada aqui: acumulado do ano, limite do MEI, receita sem
    documento fiscal e relação de notas emitidas são extras de TELA, e há teste
    que falha se qualquer um deles aparecer.
--}}
<div class="cabecalho">
    <h1>{{ $anexo['titulo'] }}</h1>
    <div class="fundamento">{{ $anexo['fundamento'] }}</div>
</div>

<table class="identificacao">
    <tr>
        <td style="width: 32%;">
            <span class="rotulo">CNPJ</span>
            <span class="valor">{{ $anexo['cnpj'] }}</span>
        </td>
        <td>
            <span class="rotulo">Empreendedor individual</span>
            <span class="valor">{{ $anexo['empreendedor'] }}</span>
        </td>
        <td style="width: 22%;">
            <span class="rotulo">Período de apuração</span>
            <span class="valor">{{ $anexo['periodo'] }}</span>
        </td>
    </tr>
</table>

@foreach ($anexo['blocos'] as $bloco)
    <div class="bloco">
        <div class="titulo">{{ $bloco['titulo'] }}</div>
        <table class="linhas">
            @foreach ($bloco['itens'] as $item)
                <tr @class(['total' => $item['total']])>
                    <td class="numeral">{{ $item['numeral'] }}</td>
                    <td>{{ $item['rotulo'] }}</td>
                    <td @class(['valor', 'negativo' => $item['negativo']])>{{ $item['valor'] }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endforeach

<table class="total-geral">
    <tr>
        <td class="numeral">{{ $anexo['total_geral']['numeral'] }}</td>
        <td>{{ $anexo['total_geral']['rotulo'] }}</td>
        <td @class(['valor', 'negativo' => $anexo['total_geral']['negativo']])>{{ $anexo['total_geral']['valor'] }}</td>
    </tr>
</table>

<div class="assinatura">
    <div class="local">{{ $anexo['local_e_data'] ?: 'Local e data: ______________________________________________' }}</div>
    <div class="linha-assinatura">ASSINATURA DO EMPRESÁRIO</div>
</div>

<div class="anexos">
    <div class="intro">ENCONTRAM-SE ANEXADOS A ESTE RELATÓRIO:</div>
    <ul>
        @foreach ($anexo['anexos'] as $item)
            <li>{{ $item }}</li>
        @endforeach
    </ul>
</div>

<div class="rodape">
    @foreach ($anexo['rodape'] as $linha)
        <div>{{ $linha }}</div>
    @endforeach
</div>
