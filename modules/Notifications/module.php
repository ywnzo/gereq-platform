<?php

declare(strict_types=1);

return [
    'id' => 'notifications',
    'name' => 'Notifications',
    'version' => '1.0.0',
    'description' => 'module.notifications.description',
    'class' => Modules\Notifications\NotificationsModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0'],
    'optional' => [],
    'provides' => ['notifications'],
    'capabilities' => ['notifications'],
    'permissions' => [
        ['name' => 'notifications.view', 'label' => 'perm.notifications.view', 'group' => 'perm.group.notifications'],
        ['name' => 'notifications.manage', 'label' => 'perm.notifications.manage', 'group' => 'perm.group.notifications'],
    ],
];