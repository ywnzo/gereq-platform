<?php

declare(strict_types=1);

return [
    'id' => 'bundles',
    'name' => 'Bundles',
    'icon' => 'layers',
    'version' => '1.0.1',
    'description' => 'module.bundles.description',
    'class' => Modules\Bundles\BundlesModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [],
    'provides' => ['bundles'],
    'capabilities' => ['bundles'],
    'permissions' => [
        ['name' => 'bundles.view', 'label' => 'perm.bundles.view', 'group' => 'perm.group.bundles'],
        ['name' => 'bundles.manage', 'label' => 'perm.bundles.manage', 'group' => 'perm.group.bundles'],
    ],
];
