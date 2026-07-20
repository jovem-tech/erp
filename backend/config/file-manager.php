<?php

$enabledCategories = array_values(array_filter(array_map(
    static fn (string $category): string => trim($category),
    explode(',', (string) env('FILE_MANAGER_ENABLED_CATEGORIES', ''))
)));
$hybridWriteCategories = array_values(array_filter(array_map(
    static fn (string $category): string => trim($category),
    explode(',', (string) env(
        'FILE_MANAGER_HYBRID_WRITE_CATEGORIES',
        'company_login_background,company_logo'
    ))
)));
$automaticSyncRoots = array_values(array_unique(array_filter(array_map(
    static fn (string $root): string => trim($root),
    explode(',', (string) env(
        'FILE_MANAGER_AUTOMATIC_SYNC_ROOTS',
        'branding,equipment_photos,order_photos,order_files,budget_documents,signatures,chat,legacy_equipment_profiles,legacy_equipment_files,legacy_order_anomalies,legacy_order_state,legacy_order_accessories,legacy_order_checklists,legacy_order_documents,legacy_budgets,legacy_chat,legacy_whatsapp,legacy_users,legacy_system'
    ))
))));

return [
    'mode' => env('FILE_MANAGER_MODE', 'off'),
    'enabled_categories' => $enabledCategories,
    'hybrid_write_categories' => $hybridWriteCategories,
    'storage' => [
        'disk' => env('FILE_MANAGER_DISK', 'local'),
        'root' => 'managed-files',
        'staging_root' => 'managed-files-staging',
        'allowed_disks' => ['local', 'legacy_public'],
        'legacy_read_disks' => ['local', 'legacy_public'],
    ],
    'kill_switches' => [
        'allow_writes' => (bool) env('FILE_MANAGER_ALLOW_WRITES', false),
        'allow_scanner' => (bool) env('FILE_MANAGER_ALLOW_SCANNER', false),
        'allow_mutating_reconcile' => (bool) env('FILE_MANAGER_ALLOW_MUTATING_RECONCILE', false),
        'allow_admin_state_mutations' => (bool) env('FILE_MANAGER_ALLOW_ADMIN_STATE_MUTATIONS', false),
    ],
    'locks' => [
        'seconds' => max(5, (int) env('FILE_MANAGER_LOCK_SECONDS', 30)),
        'wait_seconds' => max(1, (int) env('FILE_MANAGER_LOCK_WAIT_SECONDS', 5)),
    ],
    'retention' => [
        'previous_versions_days' => max(1, (int) env('FILE_MANAGER_PREVIOUS_VERSION_DAYS', 7)),
    ],
    'batch_download' => [
        'max_files' => max(1, min(100, (int) env('FILE_MANAGER_BATCH_DOWNLOAD_MAX_FILES', 50))),
        'max_bytes' => max(1_048_576, (int) env('FILE_MANAGER_BATCH_DOWNLOAD_MAX_BYTES', 104_857_600)),
    ],
    'pdf_thumbnails' => [
        'enabled' => (bool) env('FILE_MANAGER_PDF_THUMBNAILS_ENABLED', false),
        'renderer_binary' => env('FILE_MANAGER_PDF_THUMBNAIL_RENDERER', '/usr/bin/pdftocairo'),
        'disk' => env('FILE_MANAGER_PDF_THUMBNAIL_DISK', 'local'),
        'root' => 'file-thumbnails/pdf',
        'max_dimension' => max(160, min(1024, (int) env('FILE_MANAGER_PDF_THUMBNAIL_MAX_DIMENSION', 480))),
        'max_bytes' => max(65_536, min(5_242_880, (int) env('FILE_MANAGER_PDF_THUMBNAIL_MAX_BYTES', 2_097_152))),
        'timeout_seconds' => max(2, min(30, (int) env('FILE_MANAGER_PDF_THUMBNAIL_TIMEOUT_SECONDS', 10))),
        'lock_seconds' => max(10, min(60, (int) env('FILE_MANAGER_PDF_THUMBNAIL_LOCK_SECONDS', 20))),
        'lock_wait_seconds' => max(1, min(10, (int) env('FILE_MANAGER_PDF_THUMBNAIL_LOCK_WAIT_SECONDS', 5))),
        'browser_cache_seconds' => max(300, min(604_800, (int) env('FILE_MANAGER_PDF_THUMBNAIL_CACHE_SECONDS', 86_400))),
    ],
    'scanner' => [
        'default_limit' => max(1, (int) env('FILE_MANAGER_SCAN_LIMIT', 1000)),
        'max_depth' => max(1, (int) env('FILE_MANAGER_SCAN_MAX_DEPTH', 12)),
        'timeout_seconds' => max(5, (int) env('FILE_MANAGER_SCAN_TIMEOUT_SECONDS', 60)),
        'pause_milliseconds' => max(0, min(500, (int) env('FILE_MANAGER_SCAN_PAUSE_MS', 0))),
        'roots' => [
            'managed' => ['disk' => 'local', 'path' => 'managed-files'],
            'branding' => ['disk' => 'local', 'path' => 'private/empresa'],
            'equipment_photos' => ['disk' => 'local', 'path' => 'private/equipamentos'],
            'order_photos' => ['disk' => 'local', 'path' => 'private/os'],
            'order_files' => ['disk' => 'local', 'path' => 'private/os_documentos'],
            'budget_documents' => ['disk' => 'local', 'path' => 'private/orcamentos'],
            'signatures' => ['disk' => 'local', 'path' => 'private/assinaturas'],
            'chat' => ['disk' => 'local', 'path' => 'chat-media'],
            'legacy_equipment_profiles' => ['disk' => 'legacy_public', 'path' => 'uploads/equipamentos_perfil'],
            'legacy_equipment_files' => ['disk' => 'legacy_public', 'path' => 'uploads/equipamentos'],
            'legacy_order_anomalies' => ['disk' => 'legacy_public', 'path' => 'uploads/os_anormalidades'],
            'legacy_order_state' => ['disk' => 'legacy_public', 'path' => 'uploads/estado_fisico'],
            'legacy_order_accessories' => ['disk' => 'legacy_public', 'path' => 'uploads/acessorios'],
            'legacy_order_checklists' => ['disk' => 'legacy_public', 'path' => 'uploads/checklist'],
            'legacy_order_documents' => ['disk' => 'legacy_public', 'path' => 'uploads/os_documentos'],
            'legacy_budgets' => ['disk' => 'legacy_public', 'path' => 'uploads/orcamentos'],
            'legacy_chat' => ['disk' => 'legacy_public', 'path' => 'uploads/central_mensagens'],
            'legacy_whatsapp' => ['disk' => 'legacy_public', 'path' => 'uploads/whatsapp'],
            'legacy_users' => ['disk' => 'legacy_public', 'path' => 'uploads/usuarios'],
            'legacy_system' => ['disk' => 'legacy_public', 'path' => 'uploads/sistema'],
        ],
    ],
    'automatic_sync' => [
        'enabled' => (bool) env('FILE_MANAGER_AUTOMATIC_SYNC_ENABLED', false),
        'interval_minutes' => max(1, min(60, (int) env('FILE_MANAGER_AUTOMATIC_SYNC_INTERVAL_MINUTES', 5))),
        'roots' => $automaticSyncRoots,
        'scan_limit_per_root' => max(1, min(100_000, (int) env('FILE_MANAGER_AUTOMATIC_SYNC_SCAN_LIMIT', 10_000))),
        'catalog_limit_per_root' => max(1, min(10_000, (int) env('FILE_MANAGER_AUTOMATIC_SYNC_CATALOG_LIMIT', 10_000))),
        'domain_link_limit' => max(1, min(100_000, (int) env('FILE_MANAGER_AUTOMATIC_SYNC_DOMAIN_LINK_LIMIT', 10_000))),
        'max_depth' => max(1, min(64, (int) env('FILE_MANAGER_AUTOMATIC_SYNC_MAX_DEPTH', 12))),
        'lock_seconds' => max(60, min(3600, (int) env('FILE_MANAGER_AUTOMATIC_SYNC_LOCK_SECONDS', 3600))),
    ],
    'subject_types' => [
        'configuration',
        'equipment',
        'order',
        'order_document',
        'user_signature',
        'chat_message',
        'chat_attachment',
    ],
    'policies' => [
        'company_login_background' => [
            'max_bytes' => 4 * 1024 * 1024,
            'mime_extensions' => [
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/webp' => ['webp'],
            ],
            'inline_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        ],
        'company_logo' => [
            'max_bytes' => 4 * 1024 * 1024,
            'mime_extensions' => [
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/webp' => ['webp'],
            ],
            'inline_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        ],
        'equipment_photo' => [
            'max_bytes' => 4 * 1024 * 1024,
            'mime_extensions' => [
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/webp' => ['webp'],
            ],
            'inline_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        ],
        'order_photo' => [
            'max_bytes' => 4 * 1024 * 1024,
            'mime_extensions' => [
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/webp' => ['webp'],
            ],
            'inline_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        ],
        'order_pdf' => [
            'max_bytes' => 50 * 1024 * 1024,
            'mime_extensions' => ['application/pdf' => ['pdf']],
            'inline_mime_types' => ['application/pdf'],
        ],
        'budget_pdf' => [
            'max_bytes' => 50 * 1024 * 1024,
            'mime_extensions' => ['application/pdf' => ['pdf']],
            'inline_mime_types' => ['application/pdf'],
        ],
        'user_signature' => [
            'max_bytes' => 2 * 1024 * 1024,
            'mime_extensions' => [
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/webp' => ['webp'],
            ],
            'inline_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        ],
        'user_profile_photo' => [
            'max_bytes' => 4 * 1024 * 1024,
            'mime_extensions' => [
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/webp' => ['webp'],
            ],
            'inline_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        ],
        'chat_attachment' => [
            'max_bytes' => 25 * 1024 * 1024,
            'mime_extensions' => config('chat.attachments.allowed_mime_extensions', []),
            'inline_mime_types' => config('chat.attachments.inline_mime_types', []),
        ],
    ],
];
