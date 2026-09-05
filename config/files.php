<?php

declare(strict_types=1);

return [
    'driver' => env_value('FILE_DRIVER', 'local'),
    'local_path' => 'storage/uploads',
    'max_size' => 10 * 1024 * 1024,
    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/avif',
        'image/gif',
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ],
    'image_convert' => [
        'enabled' => filter_var(env_value('FILE_IMAGE_CONVERT', '1'), FILTER_VALIDATE_BOOL),
        'avif_quality' => (int) env_value('FILE_AVIF_QUALITY', '55'),
        'webp_quality' => (int) env_value('FILE_WEBP_QUALITY', '82'),
    ],
    's3' => [
        'bucket' => env_value('S3_BUCKET', ''),
        'region' => env_value('S3_REGION', 'eu-central-1'),
        'endpoint' => env_value('S3_ENDPOINT', null),
        'key' => env_value('S3_KEY', ''),
        'secret' => env_value('S3_SECRET', ''),
    ],
];
