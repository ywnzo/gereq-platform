<?php

declare(strict_types=1);

return [
    'id' => 'comgate',
    'name' => 'Comgate',
    'icon' => 'credit-card',
    'version' => '1.0.0',
    'description' => 'module.comgate.description',
    'class' => Integrations\Comgate\ComgateModule::class,
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
    'provides' => ['comgate'],
    'capabilities' => ['payment.gateway.comgate'],
    'permissions' => [],
];
