{{--
    Icone da aba do navegador para as paginas HTML servidas pela API
    (orcamento publico, documentos compartilhados e telas de erro). Usa a mesma
    logo da empresa que o desktop exibe, servida por
    /api/v1/configuracoes/empresa/favicon-publico.

    Regra importante: nunca declarar um segundo <link rel="icon"> junto deste
    include (nem com rel="alternate icon"). O Chrome usa o ULTIMO icone
    declarado no <head>, entao um fallback extra sobrescreve a logo da empresa.
--}}
@if ($erpCompanyHasLogo ?? false)
    <link rel="icon" type="image/x-icon" href="{{ route('api.v1.configuracoes.empresa.favicon_publico') }}?v={{ config('app.version') }}">
@else
    <link rel="icon" type="image/svg+xml" href="{{ asset('assets/img/favicon-default.svg') }}">
@endif
