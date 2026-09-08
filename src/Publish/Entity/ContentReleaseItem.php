<?php

declare(strict_types=1);

namespace Gereq\Publish\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'content_release_items')]
#[ORM\Index(name: 'idx_content_release_items_release', columns: ['release_id'])]
class ContentReleaseItem
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'release_id', length: 36)]
    private string $releaseId;

    #[ORM\Column(length: 80)]
    private string $category;

    #[ORM\Column(name: 'resource_key', length: 190)]
    private string $resourceKey;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 32)]
    private string $action;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $releaseId,
        string $category,
        string $resourceKey,
        string $label,
        string $action,
        array $payload,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->releaseId = $releaseId;
        $this->category = $category;
        $this->resourceKey = $resourceKey;
        $this->label = $label;
        $this->action = $action;
        $this->payload = $payload;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function releaseId(): string
    {
        return $this->releaseId;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function resourceKey(): string
    {
        return $this->resourceKey;
    }

    public function label(): string
    {
        return $this->label;
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
}
