<?php

$allowedAccountIds = array_values(array_filter(array_map(
    static fn (string $value): int => (int) trim($value),
    explode(',', (string) env('CHAT_ALLOWED_ACCOUNT_IDS', ''))
), static fn (int $value): bool => $value > 0));

return [
    'allowed_account_ids' => $allowedAccountIds,
    'default_account_id' => max(0, (int) env('CHAT_DEFAULT_ACCOUNT_ID', 0)),
    'inbound_attachment_max_bytes' => max(1024 * 1024, (int) env('CHAT_INBOUND_ATTACHMENT_MAX_BYTES', 25 * 1024 * 1024)),
];
