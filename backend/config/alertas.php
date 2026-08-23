<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Alertas operacionais
    |--------------------------------------------------------------------------
    |
    | Destinos para falhas que ninguem ve' sozinho: certificado da integracao
    | vencendo, baixa com valor divergente, conciliacao que parou de rodar.
    |
    | Os destinos ficam aqui (env) e nao em `configuracoes` de proposito: alerta
    | e' o caminho que precisa funcionar QUANDO algo esta' quebrado. Depender de
    | uma consulta ao banco para descobrir para onde alertar adiciona
    | exatamente o tipo de dependencia que falha junto.
    |
    */

    'enabled' => (bool) env('ALERTAS_ENABLED', true),

    // Urgente: chega no celular. Numero em formato livre (o normalizador do
    // WhatsApp cuida). Vazio desliga o canal.
    'whatsapp' => trim((string) env('ALERTAS_WHATSAPP_NUMERO', '')),

    // Relatorio/historico: e-mail. Depende de SMTP real configurado em
    // Configuracoes > Integracoes (com MAIL_MAILER=log nada sai).
    'email' => trim((string) env('ALERTAS_EMAIL', '')),

    // Nao repetir o mesmo alerta em toda execucao do scheduler. Chave de
    // deduplicacao vive no cache por este numero de minutos.
    'dedupe_minutos' => (int) env('ALERTAS_DEDUPE_MINUTOS', 720),

];
