<?php

declare(strict_types=1);

return [
    'id' => 'webplus_ai',
    'name' => 'AI',
    'icon' => 'smart_toy',
    'version' => '2.0.1',
    'description' => 'module.webplus_ai.description',
    'class' => Modules\WebPlus\Submodules\Ai\AiModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
        'webplus' => '^2.1',
    ],
    'optional' => [
        'listings' => '^1.0',
    ],
    'provides' => ['webplus.ai'],
    'capabilities' => ['webplus.ai'],
    'permissions' => [
        ['name' => 'webplus_ai.view', 'label' => 'perm.webplus_ai.view', 'group' => 'perm.group.webplus_ai'],
        ['name' => 'webplus_ai.manage', 'label' => 'perm.webplus_ai.manage', 'group' => 'perm.group.webplus_ai'],
    ],
];
