@if ($numerada)
<ol class="pdfe-lista">
    @foreach ($itens as $item)
        <li>{!! $item !!}</li>
    @endforeach
</ol>
@else
<ul class="pdfe-lista">
    @foreach ($itens as $item)
        <li>{!! $item !!}</li>
    @endforeach
</ul>
@endif
