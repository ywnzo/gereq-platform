<?php

declare(strict_types=1);

namespace Modules\Returns\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'return_requests')]
#[ORM\UniqueConstraint(name: 'uniq_return_requests_number', columns: ['number'])]
#[ORM\Index(name: 'idx_return_requests_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_return_requests_status', columns: ['status'])]
class ReturnRequest
{
    public const STATUSES = ['requested', 'approved', 'rejected', 'received', 'refunded', 'closed'];
    public const RESOLUTIONS = ['refund', 'exchange', 'store_credit', 'none'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $number;

    #[ORM\Column(name: 'order_id', length: 36)]
    private string $orderId;

    #[ORM\Column(name: 'customer_id', length: 36, nullable: true)]
    private ?string $customerId = null;

    #[ORM\Column(length: 32)]
    private string $status = 'requested';

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $resolution = null;

    #[ORM\Column(name: 'refund_amount', type: 'decimal', precision: 14, scale: 2, nullable: true)]
    private ?string $refundAmount = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'CZK';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $number, string $orderId, string $reason)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->number = $number;
        $this->orderId = $orderId;
        $this->reason = $reason;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function customerId(): ?string
    {
        return $this->customerId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function resolution(): ?string
    {
        return $this->resolution;
    }

    public function refundAmount(): ?string
    {
        return $this->refundAmount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
        $this->touch();
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId !== '' ? $customerId : null;
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('returns.error.invalid_status');
        }
        $this->status = $status;
        $this->touch();
    }

    public function setReason(string $reason): void
    {
        $this->reason = $reason;
        $this->touch();
    }

    public function setResolution(?string $resolution): void
    {
        if ($resolution !== null && $resolution !== '' && !in_array($resolution, self::RESOLUTIONS, true)) {
            throw new \InvalidArgumentException('returns.error.invalid_resolution');
        }
        $this->resolution = $resolution !== '' ? $resolution : null;
        $this->touch();
    }

    public function setRefundAmount(?string $amount): void
    {
        $this->refundAmount = $amount !== null && $amount !== ''
            ? number_format((float) $amount, 2, '.', '')
            : null;
        $this->touch();
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = strtoupper($currency) ?: 'CZK';
        $this->touch();
    }

    public function setNote(?string $note): void
    {
        $this->note = $note !== '' ? $note : null;
        $this->touch();
    }

    public function statusLabel(): string
    {
        return 'returns.status.' . $this->status;
    }

    public function resolutionLabel(): ?string
    {
        return $this->resolution !== null ? 'returns.resolution.' . $this->resolution : null;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
