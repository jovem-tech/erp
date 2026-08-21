{{--
    Icone da aba do navegador (favicon), centralizado para que TODA pagina do
    desktop use a mesma marca: login, recuperacao de senha, painel logado,
    telas de impressao/pre-visualizacao e a assinatura publica.

    Regra importante: nunca declarar um segundo <link rel="icon"> junto deste
    include (nem com rel="alternate icon"). O Chrome usa o ULTIMO icone
    declarado no <head>, entao um fallback extra sobrescreve a logo da empresa
    e a aba volta a exibir o icone generico.
--}}
@php
    $faviconBranding = $desktopCompanyBranding ?? $branding ?? [];
@endphp
@if ($faviconBranding['has_logo'] ?? false)
    <link rel="icon" type="image/x-icon" href="{{ route('branding.company.favicon', ['v' => config('app.version')]) }}">
@else
    <link rel="icon" type="image/svg+xml" href="{{ asset('assets/img/favicon-default.svg') }}">
@endif
