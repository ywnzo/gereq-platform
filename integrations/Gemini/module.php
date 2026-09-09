<?php

declare(strict_types=1);

return [
    'id' => 'gemini',
    'name' => 'Gemini',
    'icon' => 'zap',
    'version' => '1.0.0',
    'description' => 'module.gemini.description',
    'class' => Integrations\Gemini\GeminiModule::class,
    'author' => 'GEREQ',
    'kind' => 'integration',
    'requires' => [
        'core' => '^1.0',
        'ai' => '^1.0',
    ],
    'optional' => [],
    'groups' => ['plugin.ai'],
    'provides' => ['gemini'],
    'capabilities' => [],
    'permissions' => [],
];
