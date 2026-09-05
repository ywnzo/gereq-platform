<?php

declare(strict_types=1);

namespace Modules\Shop\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/** Generic shipment record for any carrier driver (Packeta, PPL, manual…). */
#[ORM\Entity]
#[ORM\Table(name: 'shop_shipments')]
#[ORM\Index(name: 'idx_shop_shipments_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_shop_shipments_status', columns: ['status'])]
class Shipment
{
    public const STATUSES = ['draft', 'created', 'label_ready', 'in_transit', 'delivered', 'returned', 'cancelled'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'order_id', length: 36)]
    private string $orderId;

    #[ORM\Column(length: 64)]
    private string $carrier = 'manual';

    #[ORM\Column(name: 'shipping_method_code', length: 64, nullable: true)]
    private ?string $shippingMethodCode = null;

    #[ORM\Column(length: 32)]
    private string $status = 'draft';

    #[ORM\Column(name: 'tracking_number', length: 128, nullable: true)]
    private ?string $trackingNumber = null;

    #[ORM\Column(name: 'external_id', length: 190, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(name: 'parcel_point_id', length: 64, nullable: true)]
    private ?string $parcelPointId = null;

    #[ORM\Column(name: 'label_url', type: 'text', nullable: true)]
    private ?string $labelUrl = null;

    #[ORM\Column(name: 'meta_json', type: 'text', nullable: true)]
    private ?string $metaJson = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $orderId, string $carrier = 'manual')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->orderId = $orderId;
        $this->carrier = strtolower(trim($carrier)) ?: 'manual';
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function carrier(): string
    {
        return $this->carrier;
    }

    public function shippingMethodCode(): ?string
    {
        return $this->shippingMethodCode;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function trackingNumber(): ?string
    {
        return $this->trackingNumber;
    }

    public function externalId(): ?string
    {
        return $this->externalId;
    }

    public function parcelPointId(): ?string
    {
        return $this->parcelPointId;
    }

    public function labelUrl(): ?string
    {
        return $this->labelUrl;
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        if ($this->metaJson === null || $this->metaJson === '') {
            return [];
        }
        $decoded = json_decode($this->metaJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setShippingMethodCode(?string $code): void
    {
        $this->shippingMethodCode = $code;
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('shop.error.invalid_shipment_status');
        }
        $this->status = $status;
        $this->touch();
    }

    public function setTrackingNumber(?string $trackingNumber): void
    {
        $this->trackingNumber = $trackingNumber !== '' ? $trackingNumber : null;
        $this->touch();
    }

    public function setExternalId(?string $externalId): void
    {
        $this->externalId = $externalId !== '' ? $externalId : null;
        $this->touch();
    }

    public function setParcelPointId(?string $parcelPointId): void
    {
        $this->parcelPointId = $parcelPointId !== '' ? $parcelPointId : null;
        $this->touch();
    }

    public function setLabelUrl(?string $labelUrl): void
    {
        $this->labelUrl = $labelUrl !== '' ? $labelUrl : null;
        $this->touch();
    }

    /** @param array<string, mixed> $meta */
    public function setMeta(array $meta): void
    {
        $this->metaJson = $meta === [] ? null : (json_encode($meta, JSON_UNESCAPED_UNICODE) ?: null);
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
