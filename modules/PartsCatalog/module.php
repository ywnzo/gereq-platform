<?php

declare(strict_types=1);

return [
    'id' => 'partscatalog',
    'name' => 'PartsCatalog',
    'version' => '1.13.10',
    'description' => 'module.partscatalog.description',
    'class' => Modules\PartsCatalog\PartsCatalogModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0', 'currency' => '^1.1'],
    'optional' => ['carservice' => '^1.0', 'fleet' => '^1.0', 'media' => '^1.0', 'searchengines' => '^1.0'],
    'groups' => ['ecs'],
    'provides' => ['partscatalog'],
    'capabilities' => ['partscatalog'],
    'permissions' => [
        ['name' => 'partscatalog.view', 'label' => 'perm.partscatalog.view', 'group' => 'perm.group.partscatalog'],
        ['name' => 'partscatalog.manage', 'label' => 'perm.partscatalog.manage', 'group' => 'perm.group.partscatalog'],
    ],
];
