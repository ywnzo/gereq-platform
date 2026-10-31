<?php

declare(strict_types=1);

return [
    'id' => 'site_inquiries',
    'name' => 'Site inquiries',
    'icon' => 'inbox',
    'version' => '0.11.1',
    'description' => 'module.site_inquiries.description',
    'class' => Modules\SiteInquiries\SiteInquiriesModule::class,
    'author' => 'GEREQ',
    'repository' => 'gereq/module-site_inquiries',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => ['gereq' => '^1.0'],
    'provides' => ['site_inquiries'],
    'capabilities' => ['site_inquiries'],
    'permissions' => [
        ['name' => 'site_inquiries.view', 'label' => 'perm.site_inquiries.view', 'group' => 'perm.group.site_inquiries'],
        ['name' => 'site_inquiries.manage', 'label' => 'perm.site_inquiries.manage', 'group' => 'perm.group.site_inquiries'],
    ],
];
