<?php

declare(strict_types=1);

return [
    'driver' => env_value('QUEUE_DRIVER', 'sync'),
    'retry_after' => 90,
    'max_attempts' => (int) env_value('QUEUE_MAX_ATTEMPTS', 3),
];
