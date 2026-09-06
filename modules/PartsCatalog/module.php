<?php

declare(strict_types=1);

return [
    'id' => 'partscatalog',
    'name' => 'PartsCatalog',
    'version' => '1.9.1',
    'description' => 'module.partscatalog.description',
    'class' => Modules\PartsCatalog\PartsCatalogModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0'],
    'optional' => ['carservice' => '^1.0', 'fleet' => '^1.0', 'media' => '^1.0'],
    'groups' => ['ecs'],
    'provides' => ['partscatalog'],
    'capabilities' => ['partscatalog'],
    'permissions' => [
        ['name' => 'partscatalog.view', 'label' => 'perm.partscatalog.view', 'group' => 'perm.group.partscatalog'],
        ['name' => 'partscatalog.manage', 'label' => 'perm.partscatalog.manage', 'group' => 'perm.group.partscatalog'],
    ],
];
