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
    /** Minimální počet opakování stejného SQL fingerprintu, od kterého hlásíme N+1 (jen při app.debug). */
    'n_plus_one_threshold' => max(2, (int) env_value('APP_N_PLUS_ONE_THRESHOLD', 2)),
    /** Logovat N+1 do error_log (jen při app.debug). */
    'n_plus_one_log' => (bool) env_value('APP_N_PLUS_ONE_LOG', true),
];
