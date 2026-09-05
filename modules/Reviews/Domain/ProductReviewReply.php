<?php

declare(strict_types=1);

namespace Modules\Reviews\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_review_replies')]
#[ORM\Index(name: 'idx_product_review_replies_review', columns: ['review_id'])]
class ProductReviewReply
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'review_id', length: 36)]
    private string $reviewId;

    #[ORM\Column(name: 'author_name', length: 190)]
    private string $authorName;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(name: 'is_official', options: ['default' => true])]
    private bool $isOfficial = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $reviewId, string $authorName, string $body, bool $isOfficial = true)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->reviewId = $reviewId;
        $this->authorName = $authorName;
        $this->body = $body;
        $this->isOfficial = $isOfficial;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function reviewId(): string
    {
        return $this->reviewId;
    }

    public function authorName(): string
    {
        return $this->authorName;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isOfficial(): bool
    {
        return $this->isOfficial;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
