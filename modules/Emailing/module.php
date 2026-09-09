<?php

declare(strict_types=1);

return [
    'id' => 'emailing',
    'name' => 'Emailing',
    'icon' => 'mail',
    'version' => '1.5.3',
    'description' => 'module.emailing.description',
    'class' => Modules\Emailing\EmailingModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0'],
    'optional' => [],
    'groups' => ['comms'],
    'provides' => ['emailing'],
    'capabilities' => ['emailing'],
    'permissions' => [
        ['name' => 'emailing.view', 'label' => 'perm.emailing.view', 'group' => 'perm.group.emailing'],
        ['name' => 'emailing.accounts.manage', 'label' => 'perm.emailing.accounts.manage', 'group' => 'perm.group.emailing'],
        ['name' => 'emailing.send', 'label' => 'perm.emailing.send', 'group' => 'perm.group.emailing'],
        ['name' => 'emailing.rules.manage', 'label' => 'perm.emailing.rules.manage', 'group' => 'perm.group.emailing'],
    ],
];
