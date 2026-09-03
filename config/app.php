<?php

declare(strict_types=1);

return [
    'name' => env_value('APP_NAME', 'GEREQ Platform'),
    'env' => env_value('APP_ENV', 'production'),
    'debug' => (bool) env_value('APP_DEBUG', false),
    'url' => rtrim((string) env_value('APP_URL', 'http://localhost:8080'), '/'),
    'key' => env_value('APP_KEY', ''),
    'timezone' => 'Europe/Prague',
    'locale' => 'cs',
    'version' => '0.1.0',
];
