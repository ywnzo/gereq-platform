<?php

declare(strict_types=1);

return [
    'id' => 'gereq',
    'name' => 'GEREQ',
    'icon' => 'shield',
    'version' => '1.0.1',
    'description' => 'module.gereq.description',
    'class' => Modules\Gereq\GereqModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [],
    'provides' => ['gereq.ticketing', 'gereq.security'],
    'capabilities' => ['gereq.ticketing', 'gereq.security'],
    'permissions' => [
        ['name' => 'gereq.manage', 'label' => 'perm.gereq.manage', 'group' => 'perm.group.gereq'],
    ],
    'groups' => ['gereq'],
    // 'repository' => 'gereq/module-gereq',
];
