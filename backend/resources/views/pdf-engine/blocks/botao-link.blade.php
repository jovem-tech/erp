{{-- $url ja validado como http(s) no renderer; vazio = documento impresso
     (ou link indisponivel), entao vira caixa de aviso em vez de botao morto. --}}
<div class="pdfe-botao-wrap" style="text-align: {{ $alinhamento }};">
    @if ($url !== '')
        <a class="pdfe-botao" href="{{ $url }}">{!! $texto !!}</a>
    @else
        <div class="pdfe-botao pdfe-botao-inativo">{!! $texto !!}</div>
    @endif

    @if ($legenda !== '')
        <div class="pdfe-botao-legenda">{!! $legenda !!}</div>
    @endif

    @if ($url !== '' && $mostrarUrl)
        <div class="pdfe-botao-url">{{ $url }}</div>
    @endif
</div>
