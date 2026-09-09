<?php

declare(strict_types=1);

return [
    'id' => 'crm',
    'name' => 'CRM',
    'icon' => 'briefcase',
    'version' => '1.3.7',
    'description' => 'module.crm.description',
    'class' => Modules\CRM\CrmModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
        'customers' => '^1.0',
        'currency' => '^1.1',
    ],
    'optional' => ['emailing' => '^1.0'],
    'groups' => ['ecs'],
    'provides' => [],
    'capabilities' => [],
    'permissions' => [
        ['name' => 'crm.view', 'label' => 'perm.crm.view', 'group' => 'perm.group.crm'],
        ['name' => 'crm.deals.manage', 'label' => 'perm.crm.deals.manage', 'group' => 'perm.group.crm'],
        ['name' => 'crm.activities.manage', 'label' => 'perm.crm.activities.manage', 'group' => 'perm.group.crm'],
        ['name' => 'crm.workflows.manage', 'label' => 'perm.crm.workflows.manage', 'group' => 'perm.group.crm'],
    ],
];
