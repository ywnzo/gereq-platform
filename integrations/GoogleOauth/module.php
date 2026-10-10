<?php

declare(strict_types=1);

return [
    'id' => 'google_oauth',
    'name' => 'Google',
    'icon' => 'login',
    'version' => '1.0.0',
    'description' => 'module.google_oauth.description',
    'class' => Integrations\GoogleOauth\GoogleOauthModule::class,
    'author' => 'GEREQ',
    'kind' => 'integration',
    'requires' => [
        'core' => '^1.0',
        'authorization' => '^1.0',
    ],
    'optional' => [],
    'groups' => ['plugin.oauth'],
    'provides' => ['google_oauth'],
    'capabilities' => ['oauth.google'],
    'permissions' => [],
];
