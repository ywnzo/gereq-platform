<?php

declare(strict_types=1);

namespace Modules\WebPlus\Submodules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'webplus_ai_tools')]
#[ORM\UniqueConstraint(name: 'uniq_webplus_ai_tools_name', columns: ['name'])]
#[ORM\Index(name: 'idx_webplus_ai_tools_enabled', columns: ['is_enabled'])]
class AgentTool
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    /** Stable tool slug for agents, e.g. find_properties */
    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 190)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $description;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'input_schema', type: 'json')]
    private array $inputSchema = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'output_schema', type: 'json', nullable: true)]
    private ?array $outputSchema = null;

    #[ORM\Column(name: 'is_enabled', type: 'boolean')]
    private bool $enabled = true;

    #[ORM\Column(name: 'is_mutating', type: 'boolean')]
    private bool $mutating = false;

    /** Built-in tools cannot be deleted; only edited/disabled. */
    #[ORM\Column(name: 'is_builtin', type: 'boolean')]
    private bool $builtin = false;

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $title, string $description)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = self::normalizeName($name);
        $this->title = trim($title);
        $this->description = trim($description);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name) ?? $name;

        return trim($name, '_') ?: 'tool';
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return $this->inputSchema;
    }

    /** @return array<string, mixed>|null */
    public function outputSchema(): ?array
    {
        return $this->outputSchema;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isMutating(): bool
    {
        return $this->mutating;
    }

    public function isBuiltin(): bool
    {
        return $this->builtin;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
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
        $name = self::normalizeName($name);
        if ($this->name === $name) {
            return;
        }
        $this->name = $name;
        $this->touch();
    }

    public function setTitle(string $title): void
    {
        $title = trim($title);
        if ($this->title === $title) {
            return;
        }
        $this->title = $title;
        $this->touch();
    }

    public function setDescription(string $description): void
    {
        $description = trim($description);
        if ($this->description === $description) {
            return;
        }
        $this->description = $description;
        $this->touch();
    }

    /** @param array<string, mixed> $schema */
    public function setInputSchema(array $schema): void
    {
        if ($this->inputSchema == $schema) {
            return;
        }
        $this->inputSchema = $schema;
        $this->touch();
    }

    /** @param array<string, mixed>|null $schema */
    public function setOutputSchema(?array $schema): void
    {
        if ($this->outputSchema == $schema) {
            return;
        }
        $this->outputSchema = $schema;
        $this->touch();
    }

    public function setEnabled(bool $enabled): void
    {
        if ($this->enabled === $enabled) {
            return;
        }
        $this->enabled = $enabled;
        $this->touch();
    }

    public function setMutating(bool $mutating): void
    {
        if ($this->mutating === $mutating) {
            return;
        }
        $this->mutating = $mutating;
        $this->touch();
    }

    public function setBuiltin(bool $builtin): void
    {
        if ($this->builtin === $builtin) {
            return;
        }
        $this->builtin = $builtin;
        $this->touch();
    }

    public function setSortOrder(int $sortOrder): void
    {
        if ($this->sortOrder === $sortOrder) {
            return;
        }
        $this->sortOrder = $sortOrder;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
