<?php
declare(strict_types=1);
return [
    'id' => 'documents',
    'name' => 'Documents',
    'version' => '1.3.2',
    'description' => 'module.documents.description',
    'class' => Modules\Documents\DocumentsModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0'],
    'optional' => [],
    'groups' => ['ecs'],
    'provides' => ['documents'],
    'capabilities' => ['documents'],
    'permissions' => [
        ['name' => 'documents.view', 'label' => 'perm.documents.view', 'group' => 'perm.group.documents'],
        ['name' => 'documents.manage', 'label' => 'perm.documents.manage', 'group' => 'perm.group.documents'],
    ],
];