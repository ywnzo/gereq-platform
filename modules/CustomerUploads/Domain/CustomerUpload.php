<?php

declare(strict_types=1);

namespace Modules\CustomerUploads\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/** Generic customer file upload (product photos, attachments…) linkable to order. */
#[ORM\Entity]
#[ORM\Table(name: 'customer_uploads')]
#[ORM\Index(name: 'idx_customer_uploads_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_customer_uploads_order', columns: ['order_number'])]
#[ORM\Index(name: 'idx_customer_uploads_session', columns: ['session_hash'])]
class CustomerUpload
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'session_hash', length: 64)]
    private string $sessionHash;

    #[ORM\Column(name: 'product_id', length: 36, nullable: true)]
    private ?string $productId = null;

    #[ORM\Column(name: 'order_number', length: 64, nullable: true)]
    private ?string $orderNumber = null;

    #[ORM\Column(name: 'original_name', length: 255)]
    private string $originalName;

    #[ORM\Column(name: 'stored_name', length: 255)]
    private string $storedName;

    #[ORM\Column(length: 120)]
    private string $mime;

    #[ORM\Column]
    private int $size = 0;

    #[ORM\Column(name: 'promotional_consent', type: 'boolean')]
    private bool $promotionalConsent = false;

    #[ORM\Column(name: 'meta_json', type: 'text', nullable: true)]
    private ?string $metaJson = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $sessionHash,
        string $originalName,
        string $storedName,
        string $mime,
        int $size,
        ?string $productId = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->sessionHash = $sessionHash;
        $this->originalName = $originalName;
        $this->storedName = $storedName;
        $this->mime = $mime;
        $this->size = $size;
        $this->productId = $productId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string { return $this->id; }
    public function sessionHash(): string { return $this->sessionHash; }
    public function productId(): ?string { return $this->productId; }
    public function orderNumber(): ?string { return $this->orderNumber; }
    public function originalName(): string { return $this->originalName; }
    public function storedName(): string { return $this->storedName; }
    public function mime(): string { return $this->mime; }
    public function size(): int { return $this->size; }
    public function promotionalConsent(): bool { return $this->promotionalConsent; }
    /** @return array<string, mixed> */
    public function meta(): array
    {
        if ($this->metaJson === null || $this->metaJson === '') {
            return [];
        }
        $decoded = json_decode($this->metaJson, true);

        return is_array($decoded) ? $decoded : [];
    }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }

    public function setProductId(?string $productId): void { $this->productId = $productId; }
    public function linkToOrder(string $orderNumber): void { $this->orderNumber = $orderNumber; }
    public function setPromotionalConsent(bool $consent): void { $this->promotionalConsent = $consent; }
    /** @param array<string, mixed> $meta */
    public function setMeta(array $meta): void
    {
        $this->metaJson = $meta === [] ? null : (json_encode($meta, JSON_UNESCAPED_UNICODE) ?: null);
    }
}
