<?php

declare(strict_types=1);

return [
    'id' => 'media',
    'name' => 'Media',
    'version' => '1.1.0',
    'description' => 'module.media.description',
    'class' => Modules\Media\MediaModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [],
    'provides' => ['media'],
    'capabilities' => ['media'],
    'permissions' => [
        ['name' => 'media.view', 'label' => 'perm.media.view', 'group' => 'perm.group.media'],
        ['name' => 'media.manage', 'label' => 'perm.media.manage', 'group' => 'perm.group.media'],
    ],
];
