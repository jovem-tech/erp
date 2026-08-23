<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interruptores de seguranca
    |--------------------------------------------------------------------------
    | Seguem o precedente do gerenciador de arquivos (config/file-manager.php).
    | A restauracao e a acao mais destrutiva do sistema: publicar o codigo nao
    | pode torna-la alcancavel enquanto alguem nao ligar a chave de proposito.
    */

    'enabled' => (bool) env('BACKUP_ENABLED', true),
    'allow_restore' => (bool) env('BACKUP_ALLOW_RESTORE', false),
    'allow_remote' => (bool) env('BACKUP_ALLOW_REMOTE', false),

    /*
    |--------------------------------------------------------------------------
    | Armazenamento local
    |--------------------------------------------------------------------------
    | Fora do repositorio de proposito: evita ruido no git, evita recursao do
    | backup sobre si mesmo e mantem o nginx longe do arquivo. Provisionado uma
    | unica vez por root:
    |   install -d -o www-data -g www-data -m 0700 /var/backups/sistema-erp/erp
    */

    'store' => [
        'path' => env('BACKUP_STORE_PATH', '/var/backups/sistema-erp/erp'),
        'work_dirname' => '.trabalho',
        'lock_file' => env('BACKUP_LOCK_FILE', '/var/lock/sistema-erp-backup.lock'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Catalogo unificado
    |--------------------------------------------------------------------------
    | O painel e a unica lista de backups do sistema. Alem do que ele mesmo
    | gera, varre o disco e cataloga o que encontrar - inclusive os dumps do
    | cron de root das 02:00, que ele consegue ler mas nao apagar.
    */

    'discovery' => [
        'enabled' => (bool) env('BACKUP_DISCOVERY_ENABLED', true),
        'roots' => [
            [
                'path' => env('BACKUP_STORE_PATH', '/var/backups/sistema-erp/erp'),
                'origin' => 'painel',
                'managed' => true,
            ],
            [
                'path' => '/var/backups/sistema-erp',
                'origin' => 'cron_legado',
                'managed' => false,
                'recursive' => false,
            ],
            [
                'path' => storage_path('app/backups'),
                'origin' => 'manual',
                'managed' => true,
            ],
        ],
        'patterns' => ['*.sql.gz', '*.tar'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bancos de dados
    |--------------------------------------------------------------------------
    | Cada conexao e SONDADA antes do dump. Na bancada o sistema_erp_chat nao
    | existe e o usuario erp_app nao tem grant para ele - ausencia vira aviso
    | no manifesto, nunca falha do backup inteiro.
    */

    'databases' => [
        'connections' => ['mysql', 'chat'],

        // file_scan_runs sozinha ocupa 72% do banco (320 MB, +11 mil linhas/dia)
        // e e telemetria regeneravel do scanner. Entra so como estrutura.
        'structure_only_tables' => [
            'file_scan_runs',
            'file_scan_findings',
        ],

        'mysqldump_options' => [
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--default-character-set=utf8mb4',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Arvores de arquivos
    |--------------------------------------------------------------------------
    | IDs LOGICOS, nunca caminhos absolutos no manifesto: LEGACY_PUBLIC_PATH
    | difere entre bancada e VPS, e um pacote de producao restaurado na bancada
    | precisa resolver a raiz pela configuracao ATUAL, nao pela do pacote.
    */

    'roots' => [
        'backend_privado' => [
            'label' => 'Arquivos privados do backend',
            'resolver' => 'storage:app/private',
            'optional' => false,
        ],
        'legado_uploads' => [
            'label' => 'Uploads do sistema legado',
            'resolver' => 'disk:legacy_public',
            'suffix' => 'uploads',
            'optional' => true,
        ],
        'coletor_bancada' => [
            'label' => 'Coletor de bancada',
            'resolver' => 'storage:app/bench-collector',
            'optional' => true,
        ],
    ],

    // Padroes SEM barra casam com qualquer segmento do caminho; padroes COM
    // barra casam por prefixo. Os caminhos sao relativos a cada raiz, nunca
    // ao storage/ - foi essa confusao que deixou file-thumbnails passar.
    'exclude' => [
        'file-thumbnails',  // miniaturas regeneraveis
        'backups',          // evita o backup copiar a si mesmo
        'logs',
        'framework',
        'node_modules',
        'vendor',
        '.trabalho',
        'temporario',       // area de descarte do sistema legado
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuracao e segredos
    |--------------------------------------------------------------------------
    | O APP_KEY PRECISA viajar junto: sem ele as colunas do EncryptedSecret
    | ficam ilegiveis e o dump esta parcialmente destruido. Mas nunca e
    | restaurado automaticamente - ver RestoreManager.
    |
    | Certificados TLS ficam DE FORA de proposito: na VPS sao Let's Encrypt
    | (renovam sozinhos), na bancada sao autoassinados, e liberar a chave
    | privada para o www-data a fim de manda-la para a nuvem seria perda
    | liquida de seguranca em troca de um dado que se regenera.
    */

    'config_snapshot' => [
        'files' => [
            'backend/.env' => 'backend.env',
            'frontends/desktop/.env' => 'desktop.env',
            'VERSION' => 'VERSION',
            'backend/composer.lock' => 'composer.lock',
        ],
        'include_tls' => (bool) env('BACKUP_INCLUDE_TLS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Criptografia
    |--------------------------------------------------------------------------
    | AES-256-CBC via openssl: presente em qualquer Linux, sem agente, sem
    | estado. A restauracao e um pipe unico que funciona sem PHP e sem o
    | sistema no ar. A malebilidade do CBC e fechada pelo sha256 de cada
    | membro no manifesto, conferido antes de qualquer restauracao.
    */

    'cipher' => [
        'algorithm' => 'aes-256-cbc',
        'digest' => 'sha512',
        'iterations' => (int) env('BACKUP_KDF_ITERATIONS', 600000),
    ],

    'format_version' => 1,

    /*
    |--------------------------------------------------------------------------
    | Agenda e retencao
    |--------------------------------------------------------------------------
    | 02:00 (cron de root) e 02:30 (file-manager:purge-trash) ja estao ocupados.
    */

    'schedule' => [
        'daily_time' => env('BACKUP_DAILY_TIME', '03:15'),
        'prune_time' => env('BACKUP_PRUNE_TIME', '03:50'),
    ],

    'retention' => [
        'daily' => (int) env('BACKUP_RETENTION_DAILY', 7),
        'weekly' => (int) env('BACKUP_RETENTION_WEEKLY', 4),
        'monthly' => (int) env('BACKUP_RETENTION_MONTHLY', 6),
        // Rede de seguranca dura: nunca apagar o ultimo backup bom.
        'minimum_copies' => (int) env('BACKUP_RETENTION_MINIMUM', 2),
    ],

    'preflight' => [
        'required_binaries' => ['mysqldump', 'gzip', 'tar', 'openssl'],
        'free_space_multiplier' => 3.0,
        'free_space_floor_bytes' => 500 * 1024 * 1024,
    ],
];
