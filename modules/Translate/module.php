<?php

declare(strict_types=1);

return [
    'id' => 'translate',
    'name' => 'Translate',
    'icon' => 'language',
    'version' => '2.0.1',
    'description' => 'module.translate.description',
    'class' => Modules\Translate\TranslateModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0'],
    'optional' => [],
    'groups' => ['ecs'],
    'provides' => ['translate'],
    'capabilities' => ['translate'],
    'permissions' => [
        ['name' => 'translate.view', 'label' => 'perm.translate.view', 'group' => 'perm.group.translate'],
        ['name' => 'translate.manage', 'label' => 'perm.translate.manage', 'group' => 'perm.group.translate'],
    ],
];
