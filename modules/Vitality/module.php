<?php

declare(strict_types=1);

return [
    'id' => 'vitality',
    'name' => 'Vitality',
    'icon' => 'heart',
    'version' => '1.0.3',
    'description' => 'module.vitality.description',
    'class' => Modules\Vitality\VitalityModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [],
    'provides' => ['vitality'],
    'capabilities' => [],
    'permissions' => [
        ['name' => 'vitality.view', 'label' => 'perm.vitality.view', 'group' => 'perm.group.vitality'],
        ['name' => 'vitality.manage', 'label' => 'perm.vitality.manage', 'group' => 'perm.group.vitality'],
    ],
];
