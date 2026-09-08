<?php

declare(strict_types=1);

return [
    'id' => 'payments',
    'name' => 'Payments',
    'icon' => 'credit-card',
    'version' => '1.3.0',
    'description' => 'module.payments.description',
    'class' => Modules\Payments\PaymentsModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
        'currency' => '^1.1',
    ],
    'optional' => [
        'comgate' => '^1.0',
        'stripe' => '^1.0',
        'gopay' => '^1.0',
        'webhooks' => '^1.0',
    ],
    'provides' => ['payments'],
    'capabilities' => ['payments', 'payment_gateways'],
    'permissions' => [
        ['name' => 'payments.view', 'label' => 'perm.payments.view', 'group' => 'perm.group.payments'],
        ['name' => 'payments.manage', 'label' => 'perm.payments.manage', 'group' => 'perm.group.payments'],
    ],
];
