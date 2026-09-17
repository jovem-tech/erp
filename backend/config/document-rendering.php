<?php

/*
|--------------------------------------------------------------------------
| Renderização de documentos PDF (Central Documental da OS e orçamentos)
|--------------------------------------------------------------------------
|
| O acervo documental deixou de guardar o binário de cada versão: ao gerar,
| grava-se um snapshot (JSON com os dados daquele instante + template usado)
| e o PDF é renderizado sob demanda a partir dele. Só assinatura formal
| continua persistida em disco, comprimida ao máximo.
|
| Todo PDF gerado (persistido ou efêmero) respeita um teto de tamanho
| (max_bytes) — a Central Documental é para consulta rápida, não para
| substituir a foto original (essa já está guardada em private/os e
| private/equipamentos). Acima do teto, o motor recorre ao Ghostscript;
| se mesmo assim não couber, entrega o menor resultado possível e loga.
|
*/

return [

    // 'dual'     => grava snapshot E arquivo em disco (transição/validação).
    // 'snapshot' => grava só o snapshot; disco apenas para assinatura formal.
    'mode' => env('DOCUMENT_RENDERING_MODE', 'dual'),

    // Incrementar invalida todo o cache de render (mudou perfil de foto,
    // subsetting de fonte, etc.). Entra na chave do cache.
    'profile_version' => 1,

    // Sem subsetting o dompdf embute as DejaVu inteiras (~1,4 MB de TTF)
    // em todo documento — era isso que fazia um cupom 80mm pesar 800 KB.
    'font_subsetting' => (bool) env('DOCUMENT_RENDERING_FONT_SUBSETTING', true),

    // Métodos de assinatura que exigem o binário em disco (prova). A rubrica
    // automática de emissão (sessao/reautenticacao) NÃO entra: é reidratada
    // do cadastro do usuário na hora do render.
    'persist_signature_methods' => ['pendencia_sessao', 'pendencia_reautenticada', 'cliente_link'],

    // tipo_documento legado (abertura, laudo...) que deve sempre ir a disco.
    'always_persist_types' => [],

    // Renderizar o 80mm já na emissão (true) ou só sob demanda (false).
    'eager_80mm' => false,

    // Documento antigo sem snapshot e sem arquivo: reconstituir com os dados
    // atuais da OS em vez de devolver "arquivo indisponível".
    'live_fallback' => true,

    // Teto de tamanho de TODO PDF gerado (a4 e 80mm, persistido ou sob
    // demanda). 80 KB — o motor só chama o Ghostscript quando o render
    // já ultrapassou o teto, então documento sem foto (bem abaixo disso
    // com o subsetting de fonte) não paga esse custo.
    'max_bytes' => (int) env('DOCUMENT_RENDERING_MAX_BYTES', 80 * 1024),

    // Fotos embutidas no PDF passam pelo libvips com estes perfis antes de ir
    // para o dompdf — começar pequeno poupa trabalho do Ghostscript e
    // preserva mais detalhe por byte do que deixar tudo para o passo de
    // recompressão. 'padrao' vale para o render efêmero/cache; 'assinado'
    // para o binário persistido (assinatura formal).
    'photos' => [
        'padrao' => ['max_dimension' => 700, 'quality' => 45],
        'assinado' => ['max_dimension' => 600, 'quality' => 38],
    ],

    // Pós-processamento via Ghostscript, disparado sempre que um render
    // estoura max_bytes. Só aceita a saída se ficar menor; sem gs cai para
    // os bytes originais (log de aviso, nunca bloqueia a emissão).
    'archive' => [
        'ghostscript' => (bool) env('DOCUMENT_RENDERING_GHOSTSCRIPT', true),
        'gs_binary' => env('DOCUMENT_RENDERING_GS_BINARY', '/usr/bin/gs'),
        'gs_timeout_seconds' => 20,
    ],

    // Temporários curtos (entrada/saída do Ghostscript, tempfile para binários
    // que exigem caminho — pdftocairo, anexo do chat). Sempre apagados no finally.
    'temp_directory' => env('DOCUMENT_RENDERING_TEMP', storage_path('framework/cache/pdf-tmp')),

    // Cache descartável dos renders (fora de private/ e fora do backup).
    'render_cache' => [
        'enabled' => (bool) env('DOCUMENT_RENDERING_CACHE', true),
        'disk' => 'pdf_render_cache',
        'ttl_days' => 7,
        'max_bytes' => 512 * 1024 * 1024,
        'max_entry_bytes' => 25 * 1024 * 1024,
    ],

    'thumbnails' => [
        'enabled' => (bool) env('FILE_MANAGER_PDF_THUMBNAILS_ENABLED', false),
        'max_dimension' => 480,
    ],

];
