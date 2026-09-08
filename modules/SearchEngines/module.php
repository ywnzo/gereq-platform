<?php

declare(strict_types=1);

return [
    'id' => 'searchengines',
    'name' => 'Vyhledávače',
    'icon' => 'search',
    'version' => '1.3.6',
    'description' => 'module.searchengines.description',
    'class' => Modules\SearchEngines\SearchEnginesModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0', 'currency' => '^1.3'],
    'optional' => [],
    'groups' => ['ebs', 'ecs'],
    'provides' => ['searchengines'],
    'capabilities' => ['searchengines'],
    'permissions' => [
        ['name' => 'searchengines.view', 'label' => 'perm.searchengines.view', 'group' => 'perm.group.searchengines'],
        ['name' => 'searchengines.manage', 'label' => 'perm.searchengines.manage', 'group' => 'perm.group.searchengines'],
    ],
];
