<?php

declare(strict_types=1);

return [
    'id' => 'gopay',
    'name' => 'GoPay',
    'version' => '1.0.0',
    'description' => 'module.gopay.description',
    'class' => Integrations\GoPay\GoPayModule::class,
    'author' => 'GEREQ',
    'kind' => 'integration',
    'requires' => [
        'core' => '^1.0',
        'payments' => '^1.1',
    ],
    'optional' => [
        'webhooks' => '^1.0',
    ],
    'groups' => ['plugin.payments'],
    'provides' => ['gopay'],
    'capabilities' => ['payment.gateway.gopay'],
    'permissions' => [],
];
