<?php

declare(strict_types=1);

return [
    'id' => 'webplus',
    'name' => 'Web+',
    'icon' => 'globe',
    'version' => '3.0.1',
    'description' => 'module.webplus.description',
    'class' => Modules\WebPlus\WebPlusModule::class,
    'author' => 'GEREQ',
    'submodules' => ['Submodules/Ai'],
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [
        'cookie' => '^0.2',
        'cms' => '^1.0',
        'products' => '^2.0',
        'listings' => '^1.0',
        'media' => '^1.0',
    ],
    'provides' => ['webplus'],
    'capabilities' => ['webplus'],
    'permissions' => [
        ['name' => 'webplus.view', 'label' => 'perm.webplus.view', 'group' => 'perm.group.webplus'],
        ['name' => 'webplus.manage', 'label' => 'perm.webplus.manage', 'group' => 'perm.group.webplus'],
    ],
];
