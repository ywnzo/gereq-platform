<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

use Modules\Payments\Domain\PaymentIntent;
use Modules\Payments\Domain\PaymentMethod;

/** Generic payment gateway driver — implement per provider (manual bank, Comgate, GoPay…). */
interface PaymentGatewayInterface
{
    public function key(): string;

    /**
     * @param array<string, mixed> $context order_number, return_url, customer_email, …
     * @return array{status: string, redirect_url: ?string, external_id: ?string, payment_reference: ?string, spayd_payload: ?string, meta: array<string, mixed>}
     */
    public function createPayment(PaymentIntent $intent, PaymentMethod $method, array $context = []): array;

    /**
     * Handle provider notify/callback payload.
     *
     * @param array<string, mixed> $payload
     * @return array{ok: bool, status: ?string, external_id: ?string, message: string}
     */
    public function handleNotify(array $payload): array;
}
