<?php

declare(strict_types=1);

return [
    'id' => 'loyalty',
    'name' => 'Loyalty',
    'version' => '1.0.1',
    'description' => 'module.loyalty.description',
    'class' => Modules\Loyalty\LoyaltyModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [],
    'provides' => ['loyalty'],
    'capabilities' => ['loyalty'],
    'permissions' => [
        ['name' => 'loyalty.view', 'label' => 'perm.loyalty.view', 'group' => 'perm.group.loyalty'],
        ['name' => 'loyalty.manage', 'label' => 'perm.loyalty.manage', 'group' => 'perm.group.loyalty'],
    ],
];
