<?php

declare(strict_types=1);

return [
    'id' => 'crm',
    'name' => 'CRM',
    'version' => '1.0.1',
    'description' => 'module.crm.description',
    'class' => Modules\CRM\CrmModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
        'customers' => '^1.0',
    ],
    'optional' => [],
    'groups' => ['ecs'],
    'provides' => [],
    'capabilities' => [],
    'permissions' => [
        ['name' => 'crm.view', 'label' => 'perm.crm.view', 'group' => 'perm.group.crm'],
        ['name' => 'crm.deals.manage', 'label' => 'perm.crm.deals.manage', 'group' => 'perm.group.crm'],
        ['name' => 'crm.activities.manage', 'label' => 'perm.crm.activities.manage', 'group' => 'perm.group.crm'],
    ],
];
