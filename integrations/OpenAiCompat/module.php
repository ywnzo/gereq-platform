<?php

declare(strict_types=1);

return [
    'id' => 'openai_compat',
    'name' => 'OpenAiCompat',
    'icon' => 'zap',
    'version' => '1.0.0',
    'description' => 'module.openai_compat.description',
    'class' => Integrations\OpenAiCompat\OpenAiCompatModule::class,
    'author' => 'GEREQ',
    'kind' => 'integration',
    'requires' => [
        'core' => '^1.0',
        'ai' => '^1.0',
    ],
    'optional' => [],
    'groups' => ['plugin.ai'],
    'provides' => ['openai_compat'],
    'capabilities' => [],
    'permissions' => [],
];
