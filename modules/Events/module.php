<?php

declare(strict_types=1);

return [
    'id' => 'events',
    'name' => 'Events',
    'icon' => 'view_timeline',
    'version' => '0.5.5',
    'description' => 'module.events.description',
    'class' => Modules\Events\EventsModule::class,
    'author' => 'KdeAco',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [
        'authorization' => '^1.0',
        'calendar' => '^1.0',
        'vikendo' => '^0.3',
    ],
    'provides' => ['events'],
    'capabilities' => ['events'],
    'permissions' => [
        ['name' => 'events.view', 'label' => 'perm.events.view', 'group' => 'perm.group.events'],
        ['name' => 'events.manage', 'label' => 'perm.events.manage', 'group' => 'perm.group.events'],
    ],
];
