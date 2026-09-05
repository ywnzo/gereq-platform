<?php

declare(strict_types=1);

namespace Modules\Shop\Application\Carriers;

use Gereq\Setting\Settings;
use Modules\Shop\Application\ShippingCarrierInterface;
use Modules\Shop\Domain\Shipment;

/** PPL / DPD-style carrier scaffold. */
final class PplCarrier implements ShippingCarrierInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function key(): string
    {
        return 'ppl';
    }

    public function createShipment(Shipment $shipment, array $context = []): array
    {
        $token = trim((string) $this->settings->get('shop.ppl_api_token', ''));
        if ($token === '') {
            return [
                'status' => 'draft',
                'tracking_number' => null,
                'external_id' => null,
                'label_url' => null,
                'meta' => ['error' => 'PPL not configured', 'provider' => 'ppl'],
            ];
        }

        return [
            'status' => 'draft',
            'tracking_number' => null,
            'external_id' => null,
            'label_url' => null,
            'meta' => [
                'provider' => 'ppl',
                'note' => 'PPL API scaffold — implement createShipment against PPL MyAPI2',
                'parcel_point_id' => $shipment->parcelPointId(),
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
