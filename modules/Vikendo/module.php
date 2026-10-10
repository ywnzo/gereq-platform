<?php

declare(strict_types=1);

return [
    'id' => 'vikendo',
    'name' => 'Vikendo',
    'icon' => 'dynamic_form',
    'version' => '0.3.3',
    'description' => 'module.vikendo.description',
    'class' => Modules\Vikendo\VikendoModule::class,
    'author' => 'KdeAco',
    'settings_route' => 'admin.vikendo.import',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [
        'events' => '^0.5',
    ],
    'provides' => ['vikendo'],
    'capabilities' => ['events.source.vikendo'],
    'permissions' => [
        ['name' => 'vikendo.view', 'label' => 'perm.vikendo.view', 'group' => 'perm.group.vikendo'],
        ['name' => 'vikendo.manage', 'label' => 'perm.vikendo.manage', 'group' => 'perm.group.vikendo'],
    ],
];
