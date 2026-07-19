{{-- Contêiner lado a lado (dompdf: tabela, não flex). Conteúdo e larguras são validados pelo motor. --}}
<table class="pdfe-colunas">
    <tbody>
    <tr>
        @foreach ($celulas as $indice => $celula)
            <td style="width: {{ number_format((float) ($larguras[$indice] ?? (100 / max(1, count($celulas)))), 4, '.', '') }}%;">{!! $celula !!}</td>
        @endforeach
    </tr>
    </tbody>
</table>
