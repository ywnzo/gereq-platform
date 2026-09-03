<?php

declare(strict_types=1);

return [
    'dsn' => env_value('MAIL_DSN', 'null://null'),
    'from' => [
        'address' => env_value('MAIL_FROM_ADDRESS', 'noreply@gereq.cz'),
        'name' => env_value('MAIL_FROM_NAME', 'GEREQ Platform'),
    ],
];
