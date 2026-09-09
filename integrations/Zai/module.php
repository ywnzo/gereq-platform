<?php

declare(strict_types=1);

return [
    'id' => 'zai',
    'name' => 'Zai',
    'icon' => 'zap',
    'version' => '1.0.0',
    'description' => 'module.zai.description',
    'class' => Integrations\Zai\ZaiModule::class,
    'author' => 'GEREQ',
    'kind' => 'integration',
    'requires' => [
        'core' => '^1.0',
        'ai' => '^1.0',
    ],
    'optional' => [],
    'groups' => ['plugin.ai'],
    'provides' => ['zai'],
    'capabilities' => [],
    'permissions' => [],
];
