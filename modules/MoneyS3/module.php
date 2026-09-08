<?php

declare(strict_types=1);

return [
    'id' => 'moneys3',
    'name' => 'MoneyS3',
    'icon' => 'database',
    'version' => '1.0.1',
    'description' => 'module.moneys3.description',
    'class' => Modules\MoneyS3\MoneyS3Module::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [
        'orders' => '^1.0',
    ],
    'provides' => ['moneys3'],
    'capabilities' => ['moneys3'],
    'permissions' => [
        ['name' => 'moneys3.view', 'label' => 'perm.moneys3.view', 'group' => 'perm.group.moneys3'],
        ['name' => 'moneys3.manage', 'label' => 'perm.moneys3.manage', 'group' => 'perm.group.moneys3'],
        ['name' => 'moneys3.sync', 'label' => 'perm.moneys3.sync', 'group' => 'perm.group.moneys3'],
    ],
];
