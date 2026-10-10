<?php

declare(strict_types=1);

return [
    'id' => 'facebook_oauth',
    'name' => 'Facebook',
    'icon' => 'login',
    'version' => '1.0.0',
    'description' => 'module.facebook_oauth.description',
    'class' => Integrations\FacebookOauth\FacebookOauthModule::class,
    'author' => 'GEREQ',
    'kind' => 'integration',
    'requires' => [
        'core' => '^1.0',
        'authorization' => '^1.0',
    ],
    'optional' => [],
    'groups' => ['plugin.oauth'],
    'provides' => ['facebook_oauth'],
    'capabilities' => ['oauth.facebook'],
    'permissions' => [],
];
