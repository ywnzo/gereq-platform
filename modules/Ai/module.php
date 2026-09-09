<?php

declare(strict_types=1);

return [
    'id' => 'ai',
    'name' => 'AI',
    'icon' => 'zap',
    'version' => '1.2.0',
    'description' => 'module.ai.description',
    'class' => Modules\Ai\AiModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [
        'documents' => '^1.0',
        'customers' => '^1.0',
        'crm' => '^1.0',
        'listings' => '^1.0',
        'emailing' => '^1.0',
    ],
    'groups' => ['comms'],
    'provides' => ['ai'],
    'capabilities' => ['ai'],
    'permissions' => [
        ['name' => 'ai.use', 'label' => 'perm.ai.use', 'group' => 'perm.group.ai'],
        ['name' => 'ai.manage', 'label' => 'perm.ai.manage', 'group' => 'perm.group.ai'],
    ],
];
