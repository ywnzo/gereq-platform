<?php

declare(strict_types=1);

return [
    'id' => 'webhooks',
    'name' => 'Webhooks',
    'icon' => 'zap',
    'version' => '1.0.3',
    'description' => 'module.webhooks.description',
    'class' => Modules\Webhooks\WebhooksModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [],
    'provides' => ['webhooks'],
    'capabilities' => ['webhooks'],
    'permissions' => [
        ['name' => 'webhooks.view', 'label' => 'perm.webhooks.view', 'group' => 'perm.group.webhooks'],
        ['name' => 'webhooks.manage', 'label' => 'perm.webhooks.manage', 'group' => 'perm.group.webhooks'],
        ['name' => 'webhooks.deliveries.retry', 'label' => 'perm.webhooks.deliveries.retry', 'group' => 'perm.group.webhooks'],
    ],
];
