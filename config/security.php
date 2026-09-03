<?php

declare(strict_types=1);

return [
    'session_lifetime' => (int) env_value('SESSION_LIFETIME', 120),
    'login_max_attempts' => 5,
    'login_decay_seconds' => 60,
    'password_min_length' => 10,
    'cookie_samesite' => 'lax',
];
