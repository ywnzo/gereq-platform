<?php

declare(strict_types=1);

namespace Gereq\Publish\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'content_staging')]
#[ORM\UniqueConstraint(name: 'uniq_content_staging_resource', columns: ['category', 'resource_key'])]
#[ORM\Index(name: 'idx_content_staging_category', columns: ['category'])]
class ContentStagingEntry
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_SET = 'set';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 80)]
    private string $category;

    #[ORM\Column(name: 'resource_type', length: 80)]
    private string $resourceType;

    #[ORM\Column(name: 'resource_key', length: 190)]
    private string $resourceKey;

    #[ORM\Column(length: 32)]
    private string $action;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload = [];

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(name: 'created_by', length: 36, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $category,
        string $resourceType,
        string $resourceKey,
        string $action,
        array $payload,
        string $label,
        ?string $createdBy = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->category = $category;
        $this->resourceType = $resourceType;
        $this->resourceKey = $resourceKey;
        $this->action = $action;
        $this->payload = $payload;
        $this->label = $label;
        $this->createdBy = $createdBy;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function resourceType(): string
    {
        return $this->resourceType;
    }

    public function resourceKey(): string
    {
        return $this->resourceKey;
    }

    public function action(): string
    {
        return $this->action;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function createdBy(): ?string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function replace(string $action, array $payload, string $label, ?string $createdBy = null): void
    {
        $this->action = $action;
        $this->payload = $payload;
        $this->label = $label;
        if ($createdBy !== null) {
            $this->createdBy = $createdBy;
        }
        $this->updatedAt = new \DateTimeImmutable();
    }
}
