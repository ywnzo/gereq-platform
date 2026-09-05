<?php

declare(strict_types=1);

namespace Modules\Shop\Application;

use Modules\Shop\Domain\Shipment;

/** Carrier driver — Packeta/PPL/… adapters register here. */
interface ShippingCarrierInterface
{
    public function key(): string;

    /**
     * @param array<string, mixed> $context
     * @return array{status: string, tracking_number: ?string, external_id: ?string, label_url: ?string, meta: array<string, mixed>}
     */
    public function createShipment(Shipment $shipment, array $context = []): array;

    /**
     * @return array{status: ?string, tracking_number: ?string, meta: array<string, mixed>}
     */
    public function syncStatus(Shipment $shipment): array;
}
