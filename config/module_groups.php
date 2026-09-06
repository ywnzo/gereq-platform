<?php

declare(strict_types=1);

/**
 * Skupiny / balíčky modulů (např. ECS = Everything Car Service).
 *
 * - `modules`: pořadí zobrazení a zapnutí (hard deps dřív)
 * - `required`: bez kterých balíček není „kompletní“ (zbytek = doporučené)
 * - moduly mohou navíc deklarovat `groups` v module.php — doplní se sem
 */
return [
    'ecs' => [
        'name' => 'ECS',
        'label' => 'admin.modules.group.ecs',
        'description' => 'admin.modules.group.ecs.desc',
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
];
