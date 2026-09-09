<?php

declare(strict_types=1);

namespace Modules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ai_custom_tools')]
#[ORM\UniqueConstraint(name: 'uniq_ai_custom_tools_name', columns: ['name'])]
class AiCustomTool
{
    public const HANDLERS = ['http', 'static'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(type: 'text')]
    private string $description;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'parameters_schema', type: 'json')]
    private array $parametersSchema = ['type' => 'object', 'properties' => []];

    #[ORM\Column(name: 'handler_type', length: 20)]
    private string $handlerType = 'static';

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $config = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $surfaces = ['admin'];

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $permission = null;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $parametersSchema
     * @param array<string, mixed> $config
     * @param list<string> $surfaces
     */
    public function __construct(
        string $name,
        string $label,
        string $description,
        string $handlerType = 'static',
        array $parametersSchema = ['type' => 'object', 'properties' => []],
        array $config = [],
        array $surfaces = ['admin'],
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->label = $label;
        $this->description = $description;
        $this->handlerType = $handlerType;
        $this->parametersSchema = $parametersSchema;
        $this->config = $config;
        $this->surfaces = $surfaces;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return array<string, mixed> */
    public function parametersSchema(): array
    {
        return $this->parametersSchema;
    }

    public function handlerType(): string
    {
        return $this->handlerType;
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    /** @return list<string> */
    public function surfaces(): array
    {
        return $this->surfaces;
    }

    public function permission(): ?string
    {
        return $this->permission;
    }

    public function enabled(): bool
    {
        return $this->enabled;
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

    public function setLabel(string $label): void
    {
        $this->label = $label;
        $this->touch();
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    /** @param array<string, mixed> $parametersSchema */
    public function setParametersSchema(array $parametersSchema): void
    {
        $this->parametersSchema = $parametersSchema;
        $this->touch();
    }

    public function setHandlerType(string $handlerType): void
    {
        $this->handlerType = $handlerType;
        $this->touch();
    }

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): void
    {
        $this->config = $config;
        $this->touch();
    }

    /** @param list<string> $surfaces */
    public function setSurfaces(array $surfaces): void
    {
        $this->surfaces = $surfaces;
        $this->touch();
    }

    public function setPermission(?string $permission): void
    {
        $this->permission = $permission;
        $this->touch();
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
