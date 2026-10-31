<?php

declare(strict_types=1);

return [
    'id' => 'analytics',
    'name' => 'Analytics',
    'icon' => 'analytics',
    'version' => '0.3.1',
    'description' => 'module.analytics.description',
    'class' => Modules\Analytics\AnalyticsModule::class,
    'author' => 'Lukáš Vlček',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [
        'webplus' => '^1.0 || ^2.0 || ^3.0',
        'cookie' => '^0.2',
    ],
    'groups' => ['analytics'],
    'provides' => ['analytics'],
    'capabilities' => ['analytics'],
    'permissions' => [
        ['name' => 'analytics.view', 'label' => 'perm.analytics.view', 'group' => 'perm.group.analytics'],
        ['name' => 'analytics.manage', 'label' => 'perm.analytics.manage', 'group' => 'perm.group.analytics'],
        ['name' => 'analytics.raw', 'label' => 'perm.analytics.raw', 'group' => 'perm.group.analytics'],
        ['name' => 'analytics.purge', 'label' => 'perm.analytics.purge', 'group' => 'perm.group.analytics'],
    ],
];
