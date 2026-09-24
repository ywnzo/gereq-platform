<?php

declare(strict_types=1);

return [
    'id' => 'engage',
    'name' => 'Engage',
    'icon' => 'campaign',
    'version' => '1.0.0',
    'description' => 'module.engage.description',
    'class' => Modules\Engage\EngageModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [
        'cms' => '^1.0',
        'webplus' => '^1.0',
        'newsletter' => '^1.0',
        'emailing' => '^1.0',
        'listings' => '^1.0',
        'products' => '^1.0',
        'customers' => '^1.0',
        'media' => '^1.0',
    ],
    'provides' => ['engage'],
    'capabilities' => ['engage'],
    'permissions' => [
        ['name' => 'engage.view', 'label' => 'perm.engage.view', 'group' => 'perm.group.engage'],
        ['name' => 'engage.manage', 'label' => 'perm.engage.manage', 'group' => 'perm.group.engage'],
    ],
    'groups' => ['realestates'],
];
