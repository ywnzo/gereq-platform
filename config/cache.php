<?php

declare(strict_types=1);

return [
    // file | redis | database | array
    'driver' => env_value('CACHE_DRIVER', 'file'),
    'prefix' => env_value('CACHE_PREFIX', 'gereq'),
    'default_ttl' => (int) env_value('CACHE_TTL', 3600),
    // Doctrine query result cache (same pool as app cache)
    'doctrine_result' => (bool) env_value('CACHE_DOCTRINE_RESULT', true),
    // Compiled Twig templates (off when APP_DEBUG=true)
    'twig' => (bool) env_value('CACHE_TWIG', true),
    // Browser cache for /media/{id}/file (seconds)
    'media_ttl' => (int) env_value('CACHE_MEDIA_TTL', 604800),
    'redis' => [
        'host' => env_value('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env_value('REDIS_PORT', 6379),
        'password' => env_value('REDIS_PASSWORD', null),
        'database' => 0,
    ],
    'database' => [
        'table' => env_value('CACHE_DB_TABLE', 'cache_items'),
    ],
];
