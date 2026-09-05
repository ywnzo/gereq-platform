<?php
declare(strict_types=1);
return [
    'id' => 'projects',
    'name' => 'Projects',
    'version' => '1.0.0',
    'description' => 'module.projects.description',
    'class' => Modules\Projects\ProjectsModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0', 'customers' => '^1.0'],
    'optional' => ['crm' => '^1.0', 'notifications' => '^1.0'],
    'provides' => ['projects'],
    'capabilities' => ['projects'],
    'permissions' => [
        ['name' => 'projects.view', 'label' => 'perm.projects.view', 'group' => 'perm.group.projects'],
        ['name' => 'projects.manage', 'label' => 'perm.projects.manage', 'group' => 'perm.group.projects'],
    ],
];