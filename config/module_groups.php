<?php

declare(strict_types=1);

/**
 * Skupiny balíčků / pluginů.
 *
 * - `surface`: packages (doménové balíčky) | plugins (třetí strany)
 * - `modules`: pořadí zobrazení a zapnutí
 * - `required`: bez kterých není „kompletní“ (prázdné = všechny)
 * - manifest `groups` v module.php se sem doplní
 */
return [
    'ecs' => [
        'name' => 'ECS',
        'label' => 'admin.modules.group.ecs',
        'description' => 'admin.modules.group.ecs.desc',
        'surface' => 'packages',
        'order' => 10,
        'modules' => [
            'customers',
            'fleet',
            'carservice',
            'media',
            'documents',
            'crm',
            'calendar',
            'idoklad',
        ],
        'required' => [
            'customers',
            'fleet',
            'carservice',
        ],
    ],
    'ebs' => [
        'name' => 'EBS',
        'label' => 'admin.modules.group.ebs',
        'description' => 'admin.modules.group.ebs.desc',
        'surface' => 'packages',
        'order' => 15,
        'modules' => [
            'customers',
            'bikeservice',
            'searchengines',
            'media',
            'documents',
            'calendar',
        ],
        'required' => [
            'customers',
            'bikeservice',
        ],
    ],
    'realestates' => [
        'name' => 'RealEstates',
        'label' => 'admin.modules.group.realestates',
        'description' => 'admin.modules.group.realestates.desc',
        'surface' => 'packages',
        'order' => 20,
        'modules' => [
            'currency',
            'customers',
            'media',
            'cms',
            'listings',
            'crm',
            'calendar',
            'viewings',
            'documents',
            'notifications',
            'reviews',
            'newsletter',
            'portals',
        ],
        'required' => [
            'currency',
            'media',
            'cms',
            'listings',
        ],
    ],

    // ——— Plug-iny (třetí strany) ———
    'plugin.payments' => [
        'name' => 'Platby',
        'label' => 'admin.plugins.group.payments',
        'description' => 'admin.plugins.group.payments.desc',
        'surface' => 'plugins',
        'order' => 10,
        'modules' => [
            'comgate',
            'stripe',
            'gopay',
        ],
        'required' => [],
    ],
    'plugin.shipping' => [
        'name' => 'Doprava',
        'label' => 'admin.plugins.group.shipping',
        'description' => 'admin.plugins.group.shipping.desc',
        'surface' => 'plugins',
        'order' => 20,
        'modules' => [
            'packeta',
        ],
        'required' => [],
    ],
];
