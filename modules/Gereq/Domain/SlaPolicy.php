<?php

declare(strict_types=1);

namespace Modules\Gereq\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'gereq_sla_policies')]
class SlaPolicy
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'json')]
    private array $meta = [];

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, ?string $body = null)
    {
        $this->id = Uuid::uuid4()->toString();
        $this->name = $name;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string { return $this->id; }
    public function name(): string { return $this->name; }
    public function body(): ?string { return $this->body; }
    public function isActive(): bool { return $this->isActive; }
    /** @return array<string, mixed> */
    public function meta(): array { return $this->meta; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setName(string $name): void { $this->name = trim($name); $this->touch(); }
    public function setBody(?string $body): void { $this->body = $body; $this->touch(); }
    public function setActive(bool $v): void { $this->isActive = $v; $this->touch(); }
    /** @param array<string, mixed> $meta */
    public function setMeta(array $meta): void { $this->meta = $meta; $this->touch(); }
    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
