<?php

declare(strict_types=1);

return [
    'id' => 'newsletter',
    'name' => 'Newsletter',
    'icon' => 'mail',
    'version' => '1.0.1',
    'description' => 'module.newsletter.description',
    'class' => Modules\Newsletter\NewsletterModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0'],
    'optional' => [],
    'provides' => ['newsletter'],
    'capabilities' => ['newsletter'],
    'permissions' => [
        ['name' => 'newsletter.view', 'label' => 'perm.newsletter.view', 'group' => 'perm.group.newsletter'],
        ['name' => 'newsletter.manage', 'label' => 'perm.newsletter.manage', 'group' => 'perm.group.newsletter'],
    ],
];
