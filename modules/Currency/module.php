<?php

declare(strict_types=1);

return [
    'id' => 'currency',
    'name' => 'Currency',
    'icon' => 'coins',
    'version' => '1.3.3',
    'description' => 'module.currency.description',
    'class' => Modules\Currency\CurrencyModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [
        'idoklad' => '^1.0',
    ],
    'provides' => ['currencies', 'currency'],
    'capabilities' => ['currency'],
    'permissions' => [
        ['name' => 'currency.view', 'label' => 'perm.currency.view', 'group' => 'perm.group.currency'],
        ['name' => 'currency.manage', 'label' => 'perm.currency.manage', 'group' => 'perm.group.currency'],
    ],
];
