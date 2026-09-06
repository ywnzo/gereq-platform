<?php

declare(strict_types=1);

return [
    'id' => 'payments',
    'name' => 'Payments',
    'version' => '1.1.2',
    'description' => 'module.payments.description',
    'class' => Modules\Payments\PaymentsModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
        'currency' => '^1.1',
    ],
    'optional' => [],
    'provides' => ['payments'],
    'capabilities' => ['payments'],
    'permissions' => [
        ['name' => 'payments.view', 'label' => 'perm.payments.view', 'group' => 'perm.group.payments'],
        ['name' => 'payments.manage', 'label' => 'perm.payments.manage', 'group' => 'perm.group.payments'],
    ],
];
