{{-- Galeria de fotos de entrada (recepção/check-in), até 4 numa grade fixa:
     2 colunas no A4, 1 na bobina térmica. A grade é FIXA de propósito — quando
     a largura era dividida pelo número de fotos, uma OS com uma única foto
     imprimia uma faixa da largura da página inteira.
     Fotos retrato já chegam giradas pra paisagem (OrderPdfContextFactory::
     rotateToLandscapeIfPortrait) — aqui só exibe, sempre sem cortar nada:
     tabela pra alinhar (dompdf não suporta flex) e <img> com max-width/
     max-height (equivale a "contain", não "cover") pra mostrar a foto
     inteira, centralizada, dentro da caixa de altura fixa. As células vazias
     da última linha são emitidas para a foto sozinha não esticar sobre a
     linha toda. Fontes já validadas/em base64 pelo renderer. --}}
@php
    $colunas = max(1, (int) ($colunas ?? 2));
    $linhas = array_chunk($fotos, $colunas);
    $larguraCelula = number_format(100 / $colunas, 4, '.', '');
@endphp
<table class="pdfe-galeria-fotos">
    <tbody>
    @foreach ($linhas as $linha)
        <tr>
            @foreach ($linha as $foto)
                <td style="width: {{ $larguraCelula }}%;">
                    <div class="pdfe-galeria-fotos-item"><img src="{{ $foto }}" alt=""></div>
                </td>
            @endforeach
            @for ($vazia = count($linha); $vazia < $colunas; $vazia++)
                <td style="width: {{ $larguraCelula }}%;"></td>
            @endfor
        </tr>
    @endforeach
    </tbody>
</table>
