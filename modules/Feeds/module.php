<?php

declare(strict_types=1);

return [
    'id' => 'feeds',
    'name' => 'Feeds',
    'icon' => 'rss',
    'version' => '1.0.0',
    'description' => 'module.feeds.description',
    'class' => Modules\Feeds\FeedsModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [],
    'provides' => ['feeds'],
    'capabilities' => ['feeds'],
    'permissions' => [
        ['name' => 'feeds.view', 'label' => 'perm.feeds.view', 'group' => 'perm.group.feeds'],
        ['name' => 'feeds.manage', 'label' => 'perm.feeds.manage', 'group' => 'perm.group.feeds'],
    ],
];
