<?php

declare(strict_types=1);

namespace Modules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ai_chunks')]
#[ORM\Index(name: 'idx_ai_chunks_kb', columns: ['knowledge_base_id'])]
#[ORM\Index(name: 'idx_ai_chunks_source', columns: ['source_id', 'ordinal'])]
class AiChunk
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'source_id', length: 36)]
    private string $sourceId;

    #[ORM\Column(name: 'knowledge_base_id', length: 36)]
    private string $knowledgeBaseId;

    #[ORM\Column]
    private int $ordinal = 0;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(name: 'token_estimate')]
    private int $tokenEstimate = 0;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    #[ORM\Column(type: 'blob', nullable: true)]
    private mixed $embedding = null;

    #[ORM\Column(name: 'embedding_model_id', length: 36, nullable: true)]
    private ?string $embeddingModelId = null;

    #[ORM\Column(name: 'embedding_dim', nullable: true)]
    private ?int $embeddingDim = null;

    #[ORM\Column(name: 'content_hash', length: 64)]
    private string $contentHash;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $sourceId,
        string $knowledgeBaseId,
        int $ordinal,
        string $content,
        string $contentHash,
        int $tokenEstimate = 0,
        array $metadata = [],
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->sourceId = $sourceId;
        $this->knowledgeBaseId = $knowledgeBaseId;
        $this->ordinal = $ordinal;
        $this->content = $content;
        $this->contentHash = $contentHash;
        $this->tokenEstimate = $tokenEstimate;
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function sourceId(): string
    {
        return $this->sourceId;
    }

    public function knowledgeBaseId(): string
    {
        return $this->knowledgeBaseId;
    }

    public function ordinal(): int
    {
        return $this->ordinal;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function tokenEstimate(): int
    {
        return $this->tokenEstimate;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function embedding(): ?string
    {
        if ($this->embedding === null) {
            return null;
        }
        if (is_resource($this->embedding)) {
            $data = stream_get_contents($this->embedding);
            return $data === false ? null : $data;
        }

        return is_string($this->embedding) ? $this->embedding : null;
    }

    public function embeddingModelId(): ?string
    {
        return $this->embeddingModelId;
    }

    public function embeddingDim(): ?int
    {
        return $this->embeddingDim;
    }

    public function contentHash(): string
    {
        return $this->contentHash;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setEmbedding(string $binary, string $modelId, int $dim): void
    {
        $this->embedding = $binary;
        $this->embeddingModelId = $modelId;
        $this->embeddingDim = $dim;
    }
}
