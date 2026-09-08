<?php

declare(strict_types=1);

return [
    'id' => 'bikeservice',
    'name' => 'BikeService',
    'icon' => 'bike',
    'version' => '1.11.0',
    'description' => 'module.bikeservice.description',
    'class' => Modules\BikeService\BikeServiceModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0', 'customers' => '^1.0', 'searchengines' => '^1.0', 'currency' => '^1.3'],
    'optional' => ['media' => '^1.0', 'documents' => '^1.0', 'calendar' => '^1.0', 'esign' => '^1.0'],
    'groups' => ['ebs'],
    'provides' => ['bikeservice'],
    'capabilities' => ['bikeservice'],
    'permissions' => [
        ['name' => 'bikeservice.view', 'label' => 'perm.bikeservice.view', 'group' => 'perm.group.bikeservice'],
        ['name' => 'bikeservice.manage', 'label' => 'perm.bikeservice.manage', 'group' => 'perm.group.bikeservice'],
    ],
];
