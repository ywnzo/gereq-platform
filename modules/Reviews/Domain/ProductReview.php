<?php

declare(strict_types=1);

namespace Modules\Reviews\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_reviews')]
#[ORM\Index(name: 'idx_product_reviews_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_product_reviews_status', columns: ['status'])]
class ProductReview
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'product_id', length: 36)]
    private string $productId;

    #[ORM\Column(name: 'customer_id', length: 36, nullable: true)]
    private ?string $customerId = null;

    #[ORM\Column(name: 'author_name', length: 190)]
    private string $authorName;

    #[ORM\Column(name: 'author_email', length: 190, nullable: true)]
    private ?string $authorEmail = null;

    #[ORM\Column(type: 'integer')]
    private int $rating;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(length: 32)]
    private string $status = 'pending';

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $productId, string $authorName, int $rating, string $body)
    {
        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('reviews.error.invalid_rating');
        }
        $this->id = Uuid::uuid7()->toString();
        $this->productId = $productId;
        $this->authorName = $authorName;
        $this->rating = $rating;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function customerId(): ?string
    {
        return $this->customerId;
    }

    public function authorName(): string
    {
        return $this->authorName;
    }

    public function authorEmail(): ?string
    {
        return $this->authorEmail;
    }

    public function rating(): int
    {
        return $this->rating;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setProductId(string $productId): void
    {
        $this->productId = $productId;
        $this->touch();
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId !== '' ? $customerId : null;
        $this->touch();
    }

    public function setAuthorName(string $name): void
    {
        $this->authorName = $name;
        $this->touch();
    }

    public function setAuthorEmail(?string $email): void
    {
        $this->authorEmail = $email !== '' ? $email : null;
        $this->touch();
    }

    public function setRating(int $rating): void
    {
        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('reviews.error.invalid_rating');
        }
        $this->rating = $rating;
        $this->touch();
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title !== '' ? $title : null;
        $this->touch();
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('reviews.error.invalid_status');
        }
        $this->status = $status;
        $this->touch();
    }

    public function approve(): void
    {
        $this->setStatus('approved');
    }

    public function reject(): void
    {
        $this->setStatus('rejected');
    }

    public function statusLabel(): string
    {
        return 'reviews.status.' . $this->status;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
