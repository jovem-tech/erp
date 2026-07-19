{{-- Pares rótulo→valor em grade de N colunas lógicas (cada par = célula rótulo + célula valor) --}}
@php $porLinha = max(1, (int) $colunas); @endphp
<table class="pdfe-grade">
    <tbody>
    @foreach (array_chunk($campos, $porLinha) as $linha)
        <tr>
            @foreach ($linha as $campo)
                @if (trim((string) $campo['rotulo']) !== '')
                    <td class="rotulo">{!! $campo['rotulo'] !!}</td>
                @endif
                <td @if (trim((string) $campo['rotulo']) === '') colspan="2" @endif>{!! $campo['valor'] !!}</td>
            @endforeach
            @for ($i = count($linha); $i < $porLinha; $i++)
                <td colspan="2" style="border: none;"></td>
            @endfor
        </tr>
    @endforeach
    </tbody>
</table>
