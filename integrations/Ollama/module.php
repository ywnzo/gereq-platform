<?php

declare(strict_types=1);

return [
    'id' => 'ollama',
    'name' => 'Ollama',
    'icon' => 'zap',
    'version' => '1.0.0',
    'description' => 'module.ollama.description',
    'class' => Integrations\Ollama\OllamaModule::class,
    'author' => 'GEREQ',
    'kind' => 'integration',
    'requires' => [
        'core' => '^1.0',
        'ai' => '^1.0',
    ],
    'optional' => [],
    'groups' => ['plugin.ai'],
    'provides' => ['ollama'],
    'capabilities' => [],
    'permissions' => [],
];
