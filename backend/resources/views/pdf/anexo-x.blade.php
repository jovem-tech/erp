{{--
    ANEXO X — RELATÓRIO MENSAL DAS RECEITAS BRUTAS
    Resolução CGSN nº 140, de 22 de maio de 2018, art. 106.

    Este Blade é o formulário oficial e NADA MAIS. Não acrescente aqui
    acumulado do ano, limite do MEI, lista de receita sem documento fiscal nem
    relação de notas emitidas: o Anexo X é um padrão da Receita Federal, e um
    formulário com seções extras deixa de ser o formulário. Esses números
    existem na tela, e a relação de documentos tem PDF próprio
    (pdf.anexo-x-documentos). Há teste que falha se algum deles vazar para cá.

    As linhas IV/V/VI (indústria) saem zeradas nesta base, mas continuam
    impressas — são linhas do formulário.
--}}
@include('pdf.partials.anexo-x-estilo')
@include('pdf.partials.anexo-x-formulario', ['anexo' => $anexo])
