<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Raiz do monorepo para leitura da documentacao
    |--------------------------------------------------------------------------
    |
    | Usada pela aba Documentacao em Configuracoes > Sistema. Quando vazio, o
    | DocumentationService assume dois niveis acima de base_path() (layout
    | padrao do monorepo: <raiz>/frontends/desktop).
    |
    */
    'docs_repository_root' => env('DESKTOP_DOCS_REPOSITORY_ROOT'),
];
