<?php

declare(strict_types=1);

namespace Modules\Shop\Application\Carriers;

use Modules\Shop\Application\ShippingCarrierInterface;
use Modules\Shop\Domain\Shipment;

/** Placeholder carrier — stores parcel point / tracking without external API. */
final class ManualCarrier implements ShippingCarrierInterface
{
    public function key(): string
    {
        return 'manual';
    }

    public function createShipment(Shipment $shipment, array $context = []): array
    {
        return [
            'status' => 'created',
            'tracking_number' => $shipment->trackingNumber(),
            'external_id' => $shipment->id(),
            'label_url' => null,
            'meta' => [
                'parcel_point_id' => $shipment->parcelPointId(),
                'note' => 'Manual carrier — replace with Packeta/PPL adapter module',
            ],
        ];
    }

    public function syncStatus(Shipment $shipment): array
    {
        return [
            'status' => $shipment->status(),
            'tracking_number' => $shipment->trackingNumber(),
            'meta' => $shipment->meta(),
        ];
    }
}
