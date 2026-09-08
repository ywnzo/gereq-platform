<?php

declare(strict_types=1);

return [
    'id' => 'customer_uploads',
    'name' => 'Customer Uploads',
    'icon' => 'upload',
    'version' => '1.1.1',
    'description' => 'module.customer_uploads.description',
    'class' => Modules\CustomerUploads\CustomerUploadsModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0'],
    'optional' => [],
    'provides' => ['customer_uploads'],
    'capabilities' => ['customer_uploads'],
    'permissions' => [
        ['name' => 'uploads.view', 'label' => 'perm.uploads.view', 'group' => 'perm.group.uploads'],
        ['name' => 'uploads.manage', 'label' => 'perm.uploads.manage', 'group' => 'perm.group.uploads'],
    ],
];
