<?php

declare(strict_types=1);

return [
    'id' => 'reviews',
    'name' => 'Reviews',
    'version' => '1.3.0',
    'description' => 'module.reviews.description',
    'class' => Modules\Reviews\ReviewsModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0'],
    'optional' => ['products' => '^1.0', 'customers' => '^1.0'],
    'provides' => ['reviews'],
    'capabilities' => ['reviews'],
    'permissions' => [
        ['name' => 'reviews.view', 'label' => 'perm.reviews.view', 'group' => 'perm.group.reviews'],
        ['name' => 'reviews.manage', 'label' => 'perm.reviews.manage', 'group' => 'perm.group.reviews'],
    ],
];
