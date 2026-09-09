<?php

declare(strict_types=1);

return [
    'id' => 'openai',
    'name' => 'OpenAi',
    'icon' => 'zap',
    'version' => '1.0.0',
    'description' => 'module.openai.description',
    'class' => Integrations\OpenAi\OpenAiModule::class,
    'author' => 'GEREQ',
    'kind' => 'integration',
    'requires' => [
        'core' => '^1.0',
        'ai' => '^1.0',
    ],
    'optional' => [],
    'groups' => ['plugin.ai'],
    'provides' => ['openai'],
    'capabilities' => [],
    'permissions' => [],
];
