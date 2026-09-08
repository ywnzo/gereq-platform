<?php
declare(strict_types=1);
return [
    'id' => 'calendar',
    'name' => 'Calendar',
    'icon' => 'calendar',
    'version' => '1.2.3',
    'description' => 'module.calendar.description',
    'class' => Modules\Calendar\CalendarModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0', 'crm' => '^1.0'],
    'optional' => ['projects' => '^1.0', 'notifications' => '^1.0'],
    'groups' => ['ecs'],
    'provides' => ['calendar'],
    'capabilities' => ['calendar'],
    'permissions' => [
        ['name' => 'calendar.view', 'label' => 'perm.calendar.view', 'group' => 'perm.group.calendar'],
        ['name' => 'calendar.manage', 'label' => 'perm.calendar.manage', 'group' => 'perm.group.calendar'],
    ],
];