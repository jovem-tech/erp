{{-- Tabela de dados: headers/rows/totais pré-resolvidos e escapados --}}
<table class="pdfe-tabela">
    @if ($repetirCabecalho)
    <thead>
    @else
    <tbody>
    @endif
    <tr>
        @foreach ($headers as $header)
            <th style="text-align: {{ $header['alinhamento'] }};@if ($header['largura'] !== null) width: {{ $header['largura'] }}%;@endif">{!! $header['rotulo'] !!}</th>
        @endforeach
    </tr>
    @if ($repetirCabecalho)
    </thead>
    <tbody>
    @endif
    @foreach ($rows as $row)
        <tr>
            @foreach ($row as $cell)
                <td style="text-align: {{ $cell['alinhamento'] }};">{!! $cell['valor'] !!}</td>
            @endforeach
        </tr>
    @endforeach
    @foreach ($totais as $total)
        <tr class="total @if ($total['destaque']) destaque @endif">
            <td colspan="{{ max(1, count($headers) - 1) }}" style="text-align: right;">{!! $total['rotulo'] !!}</td>
            <td style="text-align: right;">{!! $total['valor'] !!}</td>
        </tr>
    @endforeach
    </tbody>
</table>
