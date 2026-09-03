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
        'image/gif',
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ],
    's3' => [
        'bucket' => env_value('S3_BUCKET', ''),
        'region' => env_value('S3_REGION', 'eu-central-1'),
        'endpoint' => env_value('S3_ENDPOINT', null),
        'key' => env_value('S3_KEY', ''),
        'secret' => env_value('S3_SECRET', ''),
    ],
];
