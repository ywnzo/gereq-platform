<?php

declare(strict_types=1);

return [
    'id' => 'cms',
    'name' => 'CMS',
    'icon' => 'layout',
    'version' => '1.1.0',
    'description' => 'module.cms.description',
    'class' => Modules\Cms\CmsModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [
        'media' => '^1.0',
        'products' => '^1.0',
    ],
    'provides' => ['cms'],
    'capabilities' => ['cms'],
    'permissions' => [
        ['name' => 'cms.view', 'label' => 'perm.cms.view', 'group' => 'perm.group.cms'],
        ['name' => 'cms.manage', 'label' => 'perm.cms.manage', 'group' => 'perm.group.cms'],
    ],
];
