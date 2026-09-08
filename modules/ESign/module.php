<?php

declare(strict_types=1);

return [
    'id' => 'esign',
    'name' => 'eSign',
    'icon' => 'edit',
    'version' => '1.1.2',
    'description' => 'module.esign.description',
    'class' => Modules\ESign\ESignModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0'],
    'optional' => [],
    'provides' => ['esign'],
    'capabilities' => ['esign'],
    'permissions' => [
        ['name' => 'esign.view', 'label' => 'perm.esign.view', 'group' => 'perm.group.esign'],
        ['name' => 'esign.manage', 'label' => 'perm.esign.manage', 'group' => 'perm.group.esign'],
    ],
];
