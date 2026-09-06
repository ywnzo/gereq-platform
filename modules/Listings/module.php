<?php

declare(strict_types=1);

return [
    'id' => 'listings',
    'name' => 'Listings',
    'version' => '1.0.2',
    'description' => 'module.listings.description',
    'class' => Modules\Listings\ListingsModule::class,
    'author' => 'RealEstates',
    'requires' => [
        'core' => '^1.0',
        'currency' => '^1.1',
    ],
    'optional' => [
        'customers' => '^1.0',
        'crm' => '^1.0',
        'viewings' => '^1.0',
        'portals' => '^1.0',
        'documents' => '^1.0',
        'media' => '^1.0',
    ],
    'groups' => ['realestates'],
    'provides' => ['listings', 'brokers', 'near_locations', 'cities'],
    'capabilities' => ['listings', 'brokers', 'near_locations', 'cities'],
    'permissions' => [
        ['name' => 'listings.view', 'label' => 'perm.listings.view', 'group' => 'perm.group.listings'],
        ['name' => 'listings.manage', 'label' => 'perm.listings.manage', 'group' => 'perm.group.listings'],
    ],
];
