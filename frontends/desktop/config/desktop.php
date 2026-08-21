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

    /*
    |--------------------------------------------------------------------------
    | Origens externas permitidas pelo Content-Security-Policy
    |--------------------------------------------------------------------------
    |
    | O desktop nao carrega nenhum script, estilo, fonte ou imagem de fora: tudo
    | e' servido pela propria origem. A UNICA saida externa e' de dados (fetch),
    | para as consultas de CEP e CNPJ no cadastro de clientes.
    |
    | Se estes hosts sairem daqui, o autopreenchimento de endereco e de CNPJ para
    | de funcionar silenciosamente — o navegador bloqueia a requisicao e o campo
    | simplesmente nunca preenche. Ao trocar de provedor, atualize esta lista.
    |
    */
    'csp_connect_src' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'DESKTOP_CSP_CONNECT_SRC',
        'https://viacep.com.br,https://brasilapi.com.br'
    ))))),
];
