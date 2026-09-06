<?php

declare(strict_types=1);

return [
    'module.stripe.description' => 'Stripe payment gateway (Checkout + webhook).',
    'stripe.settings.secret' => 'Stripe — Secret key',
    'stripe.settings.webhook_secret' => 'Stripe — Webhook signing secret',
    'stripe.settings.secret.help' => 'Stripe Checkout Session (sk_…).',
    'stripe.settings.webhook_secret.help' => 'whsec_… for Stripe-Signature verification.',
];
