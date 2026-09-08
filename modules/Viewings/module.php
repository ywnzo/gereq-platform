<?php

declare(strict_types=1);

return [
    'id' => 'viewings',
    'name' => 'Viewings',
    'icon' => 'eye',
    'version' => '1.0.1',
    'description' => 'module.viewings.description',
    'class' => Modules\Viewings\ViewingsModule::class,
    'author' => 'RealEstates',
    'requires' => [
        'core' => '^1.0',
        'listings' => '^1.0',
    ],
    'optional' => [
        'customers' => '^1.0',
        'crm' => '^1.0',
        'calendar' => '^1.0',
        'notifications' => '^1.0',
    ],
    'groups' => ['realestates'],
    'provides' => ['viewings'],
    'capabilities' => ['viewings'],
    'permissions' => [
        ['name' => 'viewings.view', 'label' => 'perm.viewings.view', 'group' => 'perm.group.viewings'],
        ['name' => 'viewings.manage', 'label' => 'perm.viewings.manage', 'group' => 'perm.group.viewings'],
    ],
];
