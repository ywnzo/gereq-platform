<?php

declare(strict_types=1);

namespace Modules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ai_models')]
#[ORM\Index(name: 'idx_ai_models_provider', columns: ['provider_id'])]
#[ORM\Index(name: 'idx_ai_models_role', columns: ['role', 'enabled'])]
class AiModel
{
    public const ROLES = ['chat', 'fast', 'heavy', 'embed', 'vision'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'provider_id', length: 36)]
    private string $providerId;

    #[ORM\Column(name: 'model_id', length: 190)]
    private string $modelId;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $family = null;

    #[ORM\Column(length: 20)]
    private string $role;

    #[ORM\Column(name: 'supports_tools')]
    private bool $supportsTools = false;

    #[ORM\Column(name: 'supports_vision')]
    private bool $supportsVision = false;

    #[ORM\Column(name: 'supports_thinking')]
    private bool $supportsThinking = false;

    #[ORM\Column(name: 'context_window', nullable: true)]
    private ?int $contextWindow = null;

    #[ORM\Column(name: 'embedding_dimensions', nullable: true)]
    private ?int $embeddingDimensions = null;

    #[ORM\Column(name: 'is_free_tier')]
    private bool $isFreeTier = false;

    #[ORM\Column(name: 'is_open_weights')]
    private bool $isOpenWeights = false;

    #[ORM\Column]
    private bool $enabled = true;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $providerId,
        string $modelId,
        string $role,
        ?string $family = null,
        array $metadata = [],
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->providerId = $providerId;
        $this->modelId = $modelId;
        $this->role = $role;
        $this->family = $family;
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function providerId(): string
    {
        return $this->providerId;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function family(): ?string
    {
        return $this->family;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function supportsTools(): bool
    {
        return $this->supportsTools;
    }

    public function supportsVision(): bool
    {
        return $this->supportsVision;
    }

    public function supportsThinking(): bool
    {
        return $this->supportsThinking;
    }

    public function contextWindow(): ?int
    {
        return $this->contextWindow;
    }

    public function embeddingDimensions(): ?int
    {
        return $this->embeddingDimensions;
    }

    public function isFreeTier(): bool
    {
        return $this->isFreeTier;
    }

    public function isOpenWeights(): bool
    {
        return $this->isOpenWeights;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setSupportsTools(bool $v): void
    {
        $this->supportsTools = $v;
        $this->touch();
    }

    public function setSupportsVision(bool $v): void
    {
        $this->supportsVision = $v;
        $this->touch();
    }

    public function setSupportsThinking(bool $v): void
    {
        $this->supportsThinking = $v;
        $this->touch();
    }

    public function setContextWindow(?int $v): void
    {
        $this->contextWindow = $v;
        $this->touch();
    }

    public function setEmbeddingDimensions(?int $v): void
    {
        $this->embeddingDimensions = $v;
        $this->touch();
    }

    public function setIsFreeTier(bool $v): void
    {
        $this->isFreeTier = $v;
        $this->touch();
    }

    public function setIsOpenWeights(bool $v): void
    {
        $this->isOpenWeights = $v;
        $this->touch();
    }

    public function setEnabled(bool $v): void
    {
        $this->enabled = $v;
        $this->touch();
    }

    public function setModelId(string $modelId): void
    {
        $this->modelId = $modelId;
        $this->touch();
    }

    /** @param array<string, mixed> $metadata */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
