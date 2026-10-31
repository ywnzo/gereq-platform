<?php

declare(strict_types=1);

namespace Modules\Reviews\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_reviews')]
#[ORM\Index(name: 'idx_product_reviews_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_product_reviews_status', columns: ['status'])]
#[ORM\Index(name: 'idx_product_reviews_source', columns: ['source'])]
#[ORM\UniqueConstraint(name: 'uniq_product_reviews_external', columns: ['source', 'external_id'])]
class ProductReview
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    public const SOURCES = ['local', 'google', 'facebook'];

    /** Subject ID for agency / site-wide testimonials (public storefront). */
    public const SITE_PRODUCT_ID = 'site';

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

    #[ORM\Column(length: 32, options: ['default' => 'pending'])]
    private string $status = 'pending';

    #[ORM\Column(length: 32, options: ['default' => 'local'])]
    private string $source = 'local';

    #[ORM\Column(name: 'external_id', length: 190, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(name: 'external_url', length: 500, nullable: true)]
    private ?string $externalUrl = null;

    #[ORM\Column(name: 'is_verified_purchase', options: ['default' => false])]
    private bool $isVerifiedPurchase = false;

    #[ORM\Column(name: 'helpful_count', options: ['default' => 0])]
    private int $helpfulCount = 0;

    #[ORM\Column(length: 16, options: ['default' => 'cs'])]
    private string $locale = 'cs';

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

    public function source(): string
    {
        return $this->source;
    }

    public function externalId(): ?string
    {
        return $this->externalId;
    }

    public function externalUrl(): ?string
    {
        return $this->externalUrl;
    }

    public function isVerifiedPurchase(): bool
    {
        return $this->isVerifiedPurchase;
    }

    public function helpfulCount(): int
    {
        return $this->helpfulCount;
    }

    public function locale(): string
    {
        return $this->locale;
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

    public function setSource(string $source): void
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('reviews.error.invalid_source');
        }
        $this->source = $source;
        $this->touch();
    }

    public function setExternalId(?string $externalId): void
    {
        $this->externalId = $externalId !== '' ? $externalId : null;
        $this->touch();
    }

    public function setExternalUrl(?string $externalUrl): void
    {
        $this->externalUrl = $externalUrl !== '' ? $externalUrl : null;
        $this->touch();
    }

    public function setVerifiedPurchase(bool $verified): void
    {
        $this->isVerifiedPurchase = $verified;
        $this->touch();
    }

    public function setHelpfulCount(int $count): void
    {
        $this->helpfulCount = max(0, $count);
        $this->touch();
    }

    public function markHelpful(): void
    {
        ++$this->helpfulCount;
        $this->touch();
    }

    public function setLocale(string $locale): void
    {
        $locale = trim($locale);
        $this->locale = $locale !== '' ? $locale : 'cs';
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

    public function sourceLabel(): string
    {
        return 'reviews.source.' . $this->source;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
