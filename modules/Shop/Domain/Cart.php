<?php

declare(strict_types=1);

namespace Modules\Shop\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'shop_carts')]
#[ORM\Index(name: 'idx_shop_carts_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_shop_carts_session', columns: ['session_key'])]
#[ORM\Index(name: 'idx_shop_carts_status', columns: ['status'])]
class Cart
{
    public const STATUSES = ['open', 'converted', 'abandoned'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'customer_id', length: 36, nullable: true)]
    private ?string $customerId = null;

    #[ORM\Column(name: 'session_key', length: 64, nullable: true)]
    private ?string $sessionKey = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'CZK';

    #[ORM\Column(length: 32)]
    private string $status = 'open';

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meta = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?string $customerId = null, ?string $sessionKey = null, string $currency = 'CZK')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->customerId = $customerId !== null && $customerId !== '' ? $customerId : null;
        $this->sessionKey = $sessionKey !== null && $sessionKey !== '' ? $sessionKey : null;
        $this->currency = strtoupper($currency);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function customerId(): ?string
    {
        return $this->customerId;
    }

    public function sessionKey(): ?string
    {
        return $this->sessionKey;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return array<string, mixed>|null */
    public function meta(): ?array
    {
        return $this->meta;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId !== null && $customerId !== '' ? $customerId : null;
        $this->touch();
    }

    public function setSessionKey(?string $sessionKey): void
    {
        $this->sessionKey = $sessionKey !== null && $sessionKey !== '' ? $sessionKey : null;
        $this->touch();
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = strtoupper($currency);
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('shop.error.invalid_status');
        }
        $this->status = $status;
        $this->touch();
    }

    /** @param array<string, mixed>|null $meta */
    public function setMeta(?array $meta): void
    {
        $this->meta = $meta;
        $this->touch();
    }

    /** @param array<string, mixed> $data */
    public function mergeMeta(array $data): void
    {
        $this->meta = array_merge($this->meta ?? [], $data);
        $this->touch();
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function statusLabel(): string
    {
        return 'shop.cart.status.' . $this->status;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
