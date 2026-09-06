<?php

declare(strict_types=1);

return [
    'id' => 'portals',
    'name' => 'Portals',
    'version' => '1.3.0',
    'description' => 'module.portals.description',
    'class' => Modules\Portals\PortalsModule::class,
    'author' => 'RealEstates',
    'requires' => [
        'core' => '^1.0',
        'listings' => '^1.0',
    ],
    'optional' => [
        'media' => '^1.0',
        'webhooks' => '^1.0',
    ],
    'groups' => ['realestates'],
    'provides' => ['portals'],
    'capabilities' => ['portals'],
    'permissions' => [
        ['name' => 'portals.view', 'label' => 'perm.portals.view', 'group' => 'perm.group.portals'],
        ['name' => 'portals.manage', 'label' => 'perm.portals.manage', 'group' => 'perm.group.portals'],
    ],
];
