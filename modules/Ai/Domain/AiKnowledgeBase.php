<?php

declare(strict_types=1);

namespace Modules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ai_knowledge_bases')]
#[ORM\UniqueConstraint(name: 'uniq_ai_kb_slug', columns: ['slug'])]
class AiKnowledgeBase
{
    public const VISIBILITIES = ['internal', 'public'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 80)]
    private string $slug;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(length: 20)]
    private string $visibility = 'internal';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $slug, string $name, string $visibility = 'internal')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->slug = $slug;
        $this->name = $name;
        $this->visibility = $visibility;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function visibility(): string
    {
        return $this->visibility;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
        $this->touch();
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setVisibility(string $visibility): void
    {
        $this->visibility = $visibility;
        $this->touch();
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
