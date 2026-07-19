<?php

return [
    /*
     * Secure by default: todo PDF atribuído a uma pessoa precisa conter uma
     * assinatura ativa. Processos puramente sistêmicos, sem ator humano,
     * continuam permitidos e são identificados como "Sistema" na auditoria.
     */
    'require_user_signature' => (bool) env('DOCUMENT_SIGNATURES_REQUIRED', true),
];
