<?php

declare(strict_types=1);

return [
    'id' => 'github_oauth',
    'name' => 'GitHub',
    'icon' => 'login',
    'version' => '1.0.0',
    'description' => 'module.github_oauth.description',
    'class' => Integrations\GithubOauth\GithubOauthModule::class,
    'author' => 'GEREQ',
    'kind' => 'integration',
    'requires' => [
        'core' => '^1.0',
        'authorization' => '^1.0',
    ],
    'optional' => [],
    'groups' => ['plugin.oauth'],
    'provides' => ['github_oauth'],
    'capabilities' => ['oauth.github'],
    'permissions' => [],
];
