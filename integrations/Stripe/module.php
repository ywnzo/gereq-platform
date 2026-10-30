<?php

declare(strict_types=1);

return [
    'id' => 'stripe',
    'name' => 'Stripe',
    'icon' => 'credit-card',
    'version' => '1.1.0',
    'description' => 'module.stripe.description',
    'class' => Integrations\Stripe\StripeModule::class,
    'author' => 'GEREQ',
    'kind' => 'integration',
    'requires' => [
        'core' => '^1.0',
        'payments' => '^2.2',
    ],
    'optional' => [
        'webhooks' => '^1.0',
    ],
    'groups' => ['plugin.payments'],
    'provides' => ['stripe'],
    'capabilities' => ['payment.gateway.stripe'],
    'permissions' => [],
];
