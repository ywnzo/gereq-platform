<?php

declare(strict_types=1);

return [
    // file | redis
    'driver' => env_value('SESSION_DRIVER', 'file'),
    'lifetime' => (int) env_value('SESSION_LIFETIME', 120),
    'cookie' => 'gereq_session',
    'path' => '/',
    'samesite' => env_value('SESSION_SAME_SITE', 'lax'),
    'files' => 'storage/sessions',
    'redis' => [
        'host' => env_value('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env_value('REDIS_PORT', 6379),
        'password' => env_value('REDIS_PASSWORD', null),
        'prefix' => 'gereq:session:',
    ],
];
