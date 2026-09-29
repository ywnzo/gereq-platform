<?php

declare(strict_types=1);

namespace Gereq\Customers\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'customer_tag_links')]
#[ORM\UniqueConstraint(name: 'uniq_customer_tag_link', columns: ['customer_id', 'tag_id'])]
#[ORM\Index(name: 'idx_customer_tag_links_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_customer_tag_links_tag', columns: ['tag_id'])]
class CustomerTagLink
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'customer_id', length: 36)]
    private string $customerId;

    #[ORM\Column(name: 'tag_id', length: 36)]
    private string $tagId;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $customerId, string $tagId)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->customerId = $customerId;
        $this->tagId = $tagId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function tagId(): string
    {
        return $this->tagId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
