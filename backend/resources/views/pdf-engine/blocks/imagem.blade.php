{{-- $src é sempre data URI interna (base64) validada pelo renderer --}}
<div class="pdfe-imagem" style="text-align: {{ $alinhamento }};">
    <img src="{{ $src }}" style="max-width: {{ $larguraMax }}px; height: auto;" alt="">
</div>
