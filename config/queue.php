<?php

declare(strict_types=1);

return [
    'driver' => env_value('QUEUE_DRIVER', 'sync'),
    'retry_after' => 90,
];
