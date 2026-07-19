{{-- $texto pré-resolvido/escapado pelo renderer. Os círculos decorativos são
     divs reais (não pseudo-elementos ::before/::after — suporte inconsistente
     no dompdf); só aparecem no A4 via CSS (display:none no 80mm). --}}
<h1 class="pdfe-titulo" style="text-align: {{ $alinhamento }};">
    <span class="pdfe-titulo-decor pdfe-titulo-decor-a"></span>
    <span class="pdfe-titulo-decor pdfe-titulo-decor-b"></span>
    {!! $texto !!}
</h1>
