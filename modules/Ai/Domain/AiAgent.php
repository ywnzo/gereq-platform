<?php

declare(strict_types=1);

namespace Modules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ai_agents')]
#[ORM\UniqueConstraint(name: 'uniq_ai_agents_slug', columns: ['slug'])]
class AiAgent
{
    public const WRITE_MODES = ['none', 'draft', 'confirm'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 80)]
    private string $slug;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'system_prompt', type: 'text')]
    private string $systemPrompt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $playbook = null;

    #[ORM\Column(name: 'chat_model_id', length: 36, nullable: true)]
    private ?string $chatModelId = null;

    #[ORM\Column(name: 'embed_model_id', length: 36, nullable: true)]
    private ?string $embedModelId = null;

    #[ORM\Column(name: 'write_mode', length: 20)]
    private string $writeMode = 'none';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $surfaces = ['admin'];

    #[ORM\Column]
    private bool $published = false;

    #[ORM\Column(name: 'rate_limit_rpm')]
    private int $rateLimitRpm = 30;

    #[ORM\Column(name: 'max_tool_rounds')]
    private int $maxToolRounds = 8;

    #[ORM\Column]
    private float $temperature = 0.3;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $surfaces
     */
    public function __construct(string $slug, string $name, string $systemPrompt, array $surfaces = ['admin'])
    {
        $this->id = Uuid::uuid7()->toString();
        $this->slug = $slug;
        $this->name = $name;
        $this->systemPrompt = $systemPrompt;
        $this->surfaces = $surfaces;
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

    public function description(): ?string
    {
        return $this->description;
    }

    public function systemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function playbook(): ?string
    {
        return $this->playbook;
    }

    public function chatModelId(): ?string
    {
        return $this->chatModelId;
    }

    public function embedModelId(): ?string
    {
        return $this->embedModelId;
    }

    public function writeMode(): string
    {
        return $this->writeMode;
    }

    /** @return list<string> */
    public function surfaces(): array
    {
        return $this->surfaces;
    }

    public function published(): bool
    {
        return $this->published;
    }

    public function rateLimitRpm(): int
    {
        return $this->rateLimitRpm;
    }

    public function maxToolRounds(): int
    {
        return $this->maxToolRounds;
    }

    public function temperature(): float
    {
        return $this->temperature;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function allowsSurface(string $surface): bool
    {
        return in_array($surface, $this->surfaces, true);
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

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    public function setSystemPrompt(string $systemPrompt): void
    {
        $this->systemPrompt = $systemPrompt;
        $this->touch();
    }

    public function setPlaybook(?string $playbook): void
    {
        $this->playbook = $playbook;
        $this->touch();
    }

    public function setChatModelId(?string $chatModelId): void
    {
        $this->chatModelId = $chatModelId;
        $this->touch();
    }

    public function setEmbedModelId(?string $embedModelId): void
    {
        $this->embedModelId = $embedModelId;
        $this->touch();
    }

    public function setWriteMode(string $writeMode): void
    {
        $this->writeMode = $writeMode;
        $this->touch();
    }

    /** @param list<string> $surfaces */
    public function setSurfaces(array $surfaces): void
    {
        $this->surfaces = array_values($surfaces);
        $this->touch();
    }

    public function setPublished(bool $published): void
    {
        $this->published = $published;
        $this->touch();
    }

    public function setRateLimitRpm(int $rateLimitRpm): void
    {
        $this->rateLimitRpm = $rateLimitRpm;
        $this->touch();
    }

    public function setMaxToolRounds(int $maxToolRounds): void
    {
        $this->maxToolRounds = $maxToolRounds;
        $this->touch();
    }

    public function setTemperature(float $temperature): void
    {
        $this->temperature = $temperature;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
