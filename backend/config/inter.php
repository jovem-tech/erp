<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Banco Inter — API CDPJ
    |--------------------------------------------------------------------------
    |
    | OAuth2 client_credentials sobre mTLS. O par certificado + chave privada
    | e' emitido no Internet Banking e expira — a expiracao silenciosa e' a
    | falha operacional classica desta integracao, por isso InterCredentials
    | expoe a validade e existe um comando que alerta antes do vencimento.
    |
    | Os CAMINHOS ficam aqui (env); o conteudo NUNCA vai para o banco. O dump
    | diario e gzip sem cifra e o backup de configuracao carrega o APP_KEY
    | junto (ver ConfigSnapshotService), entao guardar a chave privada no banco
    | — mesmo cifrada — colocaria chave e segredo no mesmo pacote.
    |
    | client_id/client_secret vivem em `configuracoes`, cifrados em repouso
    | (App\Support\SecretSettings), porque sao rotacionaveis pela tela de
    | Integracoes e nao sao material de chave.
    |
    */

    'base_url' => rtrim((string) env('INTER_BASE_URL', 'https://cdpj.partners.bancointer.com.br'), '/'),

    // 'sandbox' | 'producao'. So' muda o rotulo e a chave de cache do token —
    // a URL vem de INTER_BASE_URL, que e' diferente por ambiente.
    'ambiente' => (string) env('INTER_AMBIENTE', 'sandbox'),

    'certificado' => [
        // Relativo e' resolvido contra base_path().
        'cert_path' => (string) env('INTER_CERT_PATH', 'storage/app/private/integracoes/inter/cliente.crt'),
        'key_path' => (string) env('INTER_KEY_PATH', 'storage/app/private/integracoes/inter/cliente.key'),
        'key_passphrase' => (string) env('INTER_KEY_PASSPHRASE', ''),

        // Dias de antecedencia para alertar sobre o vencimento.
        'avisos_dias' => [30, 15, 7, 1],
    ],

    // Obrigatorio apenas quando a aplicacao tem mais de uma conta vinculada.
    'conta_corrente' => trim((string) env('INTER_CONTA_CORRENTE', '')),

    'http' => [
        'timeout' => (int) env('INTER_TIMEOUT', 20),
        'connect_timeout' => (int) env('INTER_CONNECT_TIMEOUT', 10),
        'retries' => (int) env('INTER_RETRIES', 2),
        'retry_delay_ms' => (int) env('INTER_RETRY_DELAY_MS', 400),
    ],

    /*
    | Escopos minimos. Deliberadamente sem `pagamento`, transferencia ou
    | devolucao: uma credencial roubada que so' sabe emitir cobranca e ler
    | status nao move dinheiro para fora da conta.
    |
    | extrato.read e' read-only, mas expoe o extrato inteiro — vazamento de
    | inteligencia do negocio, nao roubo. Classe de risco diferente, nao nivel.
    */
    'escopos' => [
        'banking' => ['extrato.read'],
        'cobranca' => ['cob.write', 'cob.read'],
    ],

    /*
    | API Banking (saldo e extrato).
    |
    | Os CAMINHOS ficam em config porque a documentacao do Inter e' uma SPA que
    | nao renderiza para leitura automatizada — nao consegui confirmar os
    | endpoints exatos. Se estiverem errados, e' ajuste de configuracao, nao
    | mudanca de codigo.
    |
    | janela_maxima_dias: o Inter limita o periodo do extrato (comumente 90).
    | Validamos ANTES de chamar, para o erro ser nosso e claro em vez de um 400
    | generico do banco.
    */
    'banking' => [
        'saldo_path' => (string) env('INTER_SALDO_PATH', 'banking/v2/saldo'),
        'extrato_path' => (string) env('INTER_EXTRATO_PATH', 'banking/v2/extrato'),
        'janela_maxima_dias' => (int) env('INTER_EXTRATO_JANELA_DIAS', 90),

        // Saldo cacheado por pouco tempo: a tela nao pode bater no banco a cada
        // carregamento (ha limite de requisicoes), mas um numero de 1h atras
        // seria pior que inutil numa conferencia de caixa.
        'saldo_cache_segundos' => (int) env('INTER_SALDO_CACHE', 600),
    ],

    // Token do Inter vale ~1h; renovamos antes para nao correr o risco de
    // usar um token que expira no meio do voo.
    'token' => [
        'ttl_segundos' => (int) env('INTER_TOKEN_TTL', 3000),
        'lock_segundos' => (int) env('INTER_TOKEN_LOCK', 10),
    ],

];
