<?php

declare(strict_types=1);

return [
    'id' => 'coupons',
    'name' => 'Coupons',
    'version' => '1.0.0',
    'description' => 'module.coupons.description',
    'class' => Modules\Coupons\CouponsModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [],
    'provides' => ['coupons'],
    'capabilities' => ['coupons'],
    'permissions' => [
        ['name' => 'coupons.view', 'label' => 'perm.coupons.view', 'group' => 'perm.group.coupons'],
        ['name' => 'coupons.manage', 'label' => 'perm.coupons.manage', 'group' => 'perm.group.coupons'],
    ],
];
