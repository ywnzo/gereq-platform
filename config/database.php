<?php

declare(strict_types=1);

return [
    'connection' => env_value('DB_CONNECTION', 'sqlite'),
    'database' => env_value('DB_DATABASE', 'storage/database.sqlite'),
    'host' => env_value('DB_HOST', '127.0.0.1'),
    'port' => (int) env_value('DB_PORT', 3306),
    'name' => env_value('DB_DATABASE_NAME', 'gereq'),
    'username' => env_value('DB_USERNAME', 'gereq'),
    'password' => env_value('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
];
