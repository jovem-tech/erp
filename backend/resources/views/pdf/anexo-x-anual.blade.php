{{--
    ANEXO X — bloco anual: uma folha por mês do ano-calendário.

    Cada folha é o MESMO formulário oficial do PDF mensal, vindo do mesmo
    partial — não é uma segunda versão dele. Uma correção feita só de um lado
    produziria uma folha divergente que continuaria sendo entregue ao fisco
    como se fosse o formulário.

    Meses encerrados saem pelo valor CONGELADO. Meses futuros saem zerados e
    com aviso no rodapé, para não serem assinados por engano — declarar R$ 0,00
    para um mês que ainda não aconteceu é declaração falsa.
--}}
@include('pdf.partials.anexo-x-estilo')

<style>
    /* Uma folha por mês. `page-break-after` no último geraria uma página em
       branco no fim do documento. */
    .folha { page-break-after: always; }
    .folha:last-child { page-break-after: auto; }
</style>

@foreach ($anexos as $anexo)
    <div class="folha">
        @include('pdf.partials.anexo-x-formulario', ['anexo' => $anexo])
    </div>
@endforeach
