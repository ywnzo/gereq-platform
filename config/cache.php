<?php

declare(strict_types=1);

return [
    'driver' => env_value('CACHE_DRIVER', 'file'),
    'redis' => [
        'host' => env_value('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env_value('REDIS_PORT', 6379),
        'password' => env_value('REDIS_PASSWORD', null),
        'database' => 0,
    ],
];
