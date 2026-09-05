<?php

declare(strict_types=1);

namespace Gereq\File;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'files')]
class StoredFile
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $disk = 'local';

    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\Column(name: 'original_name', length: 255)]
    private string $originalName;

    #[ORM\Column(length: 127)]
    private string $mime;

    #[ORM\Column]
    private int $size;

    #[ORM\Column(name: 'uploaded_by', length: 36, nullable: true)]
    private ?string $uploadedBy = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $path,
        string $originalName,
        string $mime,
        int $size,
        string $disk = 'local',
        ?string $uploadedBy = null,
        ?array $metadata = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->path = $path;
        $this->originalName = $originalName;
        $this->mime = $mime;
        $this->size = $size;
        $this->disk = $disk;
        $this->uploadedBy = $uploadedBy;
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function mime(): string
    {
        return $this->mime;
    }

    public function size(): int
    {
        return $this->size;
    }

    /** @return array<string, mixed>|null */
    public function metadata(): ?array
    {
        return $this->metadata;
    }

    public function disk(): string
    {
        return $this->disk;
    }
}
