<?php

declare(strict_types=1);

return [
    'id' => 'packeta',
    'name' => 'Packeta',
    'icon' => 'truck',
    'version' => '1.2.0',
    'description' => 'module.packeta.description',
    'class' => Integrations\Packeta\PacketaModule::class,
    'author' => 'GEREQ',
    'kind' => 'integration',
    'requires' => [
        'core' => '^1.0',
        'shop' => '^2.13',
    ],
    'optional' => [],
    'groups' => ['plugin.shipping'],
    'provides' => ['packeta'],
    'capabilities' => ['shipping.carrier.packeta'],
    'permissions' => [],
];
