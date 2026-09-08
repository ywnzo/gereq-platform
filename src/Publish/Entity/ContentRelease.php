<?php

declare(strict_types=1);

namespace Gereq\Publish\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'content_releases')]
#[ORM\Index(name: 'idx_content_releases_created', columns: ['created_at'])]
class ContentRelease
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $version;

    #[ORM\Column(name: 'change_count')]
    private int $changeCount;

    #[ORM\Column(name: 'created_by', length: 36, nullable: true)]
    private ?string $createdBy = null;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'backup_json', type: 'json')]
    private array $backupJson = [];

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $backupJson
     */
    public function __construct(
        string $version,
        int $changeCount,
        array $backupJson,
        ?string $createdBy = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->version = $version;
        $this->changeCount = $changeCount;
        $this->backupJson = $backupJson;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function changeCount(): int
    {
        return $this->changeCount;
    }

    public function createdBy(): ?string
    {
        return $this->createdBy;
    }

    /** @return array<string, mixed> */
    public function backupJson(): array
    {
        return $this->backupJson;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
