<table class="pdfe-totais">
    <tbody>
    @foreach ($linhas as $linha)
        <tr @if ($linha['destaque']) class="destaque" @endif>
            <td class="rotulo">{!! $linha['rotulo'] !!}</td>
            <td class="valor">{!! $linha['valor'] !!}</td>
        </tr>
    @endforeach
    </tbody>
</table>
