<?php

declare(strict_types=1);

return [
    'id' => 'deepseek',
    'name' => 'DeepSeek',
    'icon' => 'zap',
    'version' => '1.0.0',
    'description' => 'module.deepseek.description',
    'class' => Integrations\DeepSeek\DeepSeekModule::class,
    'author' => 'GEREQ',
    'kind' => 'integration',
    'requires' => [
        'core' => '^1.0',
        'ai' => '^1.0',
    ],
    'optional' => [],
    'groups' => ['plugin.ai'],
    'provides' => ['deepseek'],
    'capabilities' => [],
    'permissions' => [],
];
