{{-- Galeria de fotos de entrada (recepção/check-in), até 4 lado a lado.
     Fotos retrato já chegam giradas pra paisagem (OrderPdfContextFactory::
     rotateToLandscapeIfPortrait) — aqui só exibe, sempre sem cortar nada:
     tabela pra alinhar (dompdf não suporta flex) e background-image +
     background-size:contain (não "cover") pra mostrar a foto inteira,
     centralizada, dentro da caixa. Fontes já validadas/em base64 pelo
     renderer. --}}
<table class="pdfe-galeria-fotos">
    <tbody>
    <tr>
        @foreach ($fotos as $foto)
            <td style="width: {{ number_format(100 / count($fotos), 4, '.', '') }}%;">
                <div class="pdfe-galeria-fotos-item" style="background-image: url('{{ $foto }}');"></div>
            </td>
        @endforeach
    </tr>
    </tbody>
</table>
