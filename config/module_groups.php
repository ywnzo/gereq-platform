<?php

declare(strict_types=1);

/**
 * Package / plugin groups.
 *
 * - `surface`: packages (domain bundles) | plugins (third parties)
 * - `modules`: display order and enablement
 * - `required`: required for a "complete" setup (empty = all)
 * - manifest `groups` in module.php are merged here
 */
return [
    'gereq' => [
        'name' => 'GEREQ',
        'label' => 'admin.modules.group.gereq',
        'description' => 'admin.modules.group.gereq.desc',
        'surface' => 'packages',
        'order' => 5,
        'modules' => [
            'gereq',
        ],
        'required' => [],
    ],

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
            'reviews',
            'emailing',
            'newsletter',
            'engage',
            'webplus',
        ],
        'required' => [
            'currency',
            'media',
            'cms',
            'listings',
        ],
    ],
    'forum' => [
        'name' => 'Forum',
        'label' => 'admin.modules.group.forum',
        'description' => 'admin.modules.group.forum.desc',
        'surface' => 'packages',
        'order' => 25,
        'modules' => [
            'customers',
            'forum',
            'community',
            'achievements',
            'messenger',
            'media',
            'notifications',
            'newsletter',
            'calendar',
        ],
        'required' => [
            'customers',
            'forum',
        ],
    ],

    // ——— Plug-ins (third parties) ———
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
    'plugin.ai' => [
        'name' => 'AI',
        'label' => 'admin.plugins.group.ai',
        'description' => 'admin.plugins.group.ai.desc',
        'surface' => 'plugins',
        'order' => 30,
        'modules' => [
            'ollama',
            'openai_compat',
            'zai',
            'deepseek',
            'gemini',
            'anthropic',
            'openai',
        ],
        'required' => [],
    ],
];
