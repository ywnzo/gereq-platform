<?php

declare(strict_types=1);

return [
    'id' => 'currency',
    'name' => 'Currency',
    'version' => '1.0.0',
    'description' => 'module.currency.description',
    'class' => Modules\Currency\CurrencyModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [],
    'provides' => ['currencies'],
    'capabilities' => [],
    'permissions' => [
        ['name' => 'currency.view', 'label' => 'perm.currency.view', 'group' => 'perm.group.currency'],
        ['name' => 'currency.manage', 'label' => 'perm.currency.manage', 'group' => 'perm.group.currency'],
    ],
];
