<?php

$allowedAccountIds = array_values(array_filter(array_map(
    static fn (string $value): int => (int) trim($value),
    explode(',', (string) env('CHAT_ALLOWED_ACCOUNT_IDS', ''))
), static fn (int $value): bool => $value > 0));
$trustedPrivateMediaOrigins = array_values(array_filter(array_map(
    static fn (string $value): string => strtolower(rtrim(trim($value), '/')),
    explode(',', (string) env('CHAT_TRUSTED_PRIVATE_MEDIA_ORIGINS', ''))
)));

return [
    'allowed_account_ids' => $allowedAccountIds,
    'default_account_id' => max(0, (int) env('CHAT_DEFAULT_ACCOUNT_ID', 0)),
    'inbound_attachment_max_bytes' => max(1024 * 1024, (int) env('CHAT_INBOUND_ATTACHMENT_MAX_BYTES', 25 * 1024 * 1024)),
    'trusted_private_media_origins' => $trustedPrivateMediaOrigins,
    'attachments' => [
        'max_upload_kilobytes' => max(1024, (int) env('CHAT_ATTACHMENT_MAX_KILOBYTES', 25 * 1024)),
        'allowed_disks' => ['local'],
        'inline_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
        ],
        'allowed_mime_extensions' => [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
            'image/gif' => ['gif'],
            'application/pdf' => ['pdf'],
            'text/plain' => ['txt', 'csv'],
            'text/csv' => ['csv'],
            'application/csv' => ['csv'],
            'application/msword' => ['doc'],
            'application/vnd.ms-excel' => ['xls'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
            'application/vnd.oasis.opendocument.text' => ['odt'],
            'application/vnd.oasis.opendocument.spreadsheet' => ['ods'],
            'application/zip' => ['zip', 'docx', 'xlsx', 'odt', 'ods'],
            'audio/mpeg' => ['mp3'],
            'audio/ogg' => ['ogg', 'opus'],
            'audio/wav' => ['wav'],
            'audio/mp4' => ['m4a', 'aac'],
            'audio/aac' => ['aac'],
            'audio/webm' => ['webm'],
            'video/mp4' => ['mp4', 'm4a'],
            'video/webm' => ['webm'],
            'video/quicktime' => ['mov'],
            'video/x-msvideo' => ['avi'],
            'video/x-matroska' => ['mkv'],
        ],
    ],
];
