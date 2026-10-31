<?php

declare(strict_types=1);

namespace Modules\Cms\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'cms_partials')]
#[ORM\Index(name: 'idx_cms_partials_name', columns: ['name'])]
class CmsPartial
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** @var list<array{type: string, payload: array<string, mixed>}> */
    #[ORM\Column(type: 'json')]
    private array $blocks = [];

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<array{type: string, payload: array<string, mixed>}> $blocks
     */
    public function __construct(string $name, array $blocks = [], ?string $description = null)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->description = $description;
        $this->blocks = $blocks;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @return list<array{type: string, payload: array<string, mixed>}>
     */
    public function blocks(): array
    {
        return $this->blocks;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    /**
     * @param list<array{type: string, payload: array<string, mixed>}> $blocks
     */
    public function setBlocks(array $blocks): void
    {
        $this->blocks = $blocks;
        $this->touch();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'block_count' => count($this->blocks),
            'blocks' => $this->blocks,
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
