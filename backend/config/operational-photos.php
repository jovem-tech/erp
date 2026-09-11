<?php

return [
    'vips_binary' => env('OPERATIONAL_PHOTO_VIPS_BINARY', '/usr/bin/vips'),
    'thumbnail_binary' => env('OPERATIONAL_PHOTO_THUMBNAIL_BINARY', '/usr/bin/vipsthumbnail'),
    'header_binary' => env('OPERATIONAL_PHOTO_HEADER_BINARY', '/usr/bin/vipsheader'),
    'timeout_seconds' => (int) env('OPERATIONAL_PHOTO_TIMEOUT_SECONDS', 12),
    'vips_concurrency' => (int) env('OPERATIONAL_PHOTO_VIPS_CONCURRENCY', 1),
    'vips_disc_threshold' => env('OPERATIONAL_PHOTO_VIPS_DISC_THRESHOLD', '64m'),
    'temporary_directory' => env(
        'OPERATIONAL_PHOTO_TEMP_DIRECTORY',
        storage_path('app/private/operational-photo-tmp'),
    ),

    'max_files_per_group' => 4,
    'max_input_bytes' => 20 * 1024 * 1024,
    'max_pixels' => 60_000_000,
    'max_output_dimension' => 2560,
    'target_bytes' => 400 * 1024,
    'hard_limit_bytes' => 700 * 1024,

    'target_dimensions' => [2560, 2304, 2048],
    'fallback_dimensions' => [2560, 2304, 2048, 1600],
    'stage_one_max_quality' => 72,
    'stage_one_min_quality' => 60,
    'stage_two_max_quality' => 72,
    'stage_two_min_quality' => 50,
    'avif_effort' => (int) env('OPERATIONAL_PHOTO_AVIF_EFFORT', 0),

    'pdf_max_dimension' => 1920,
    'pdf_jpeg_quality' => 85,
];
