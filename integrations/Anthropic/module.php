<?php

declare(strict_types=1);

return [
    'id' => 'anthropic',
    'name' => 'Anthropic',
    'icon' => 'zap',
    'version' => '1.0.0',
    'description' => 'module.anthropic.description',
    'class' => Integrations\Anthropic\AnthropicModule::class,
    'author' => 'GEREQ',
    'kind' => 'integration',
    'requires' => [
        'core' => '^1.0',
        'ai' => '^1.0',
    ],
    'optional' => [],
    'groups' => ['plugin.ai'],
    'provides' => ['anthropic'],
    'capabilities' => [],
    'permissions' => [],
];
