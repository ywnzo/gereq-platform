<?php

declare(strict_types=1);

namespace Modules\Ai\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ai_sources')]
#[ORM\Index(name: 'idx_ai_sources_kb_status', columns: ['knowledge_base_id', 'status'])]
#[ORM\Index(name: 'idx_ai_sources_ref', columns: ['source_type', 'source_ref'])]
class AiSource
{
    public const TYPES = ['document', 'email_message', 'listing', 'cms_page', 'manual_text', 'url'];
    public const STATUSES = ['pending', 'ready', 'failed', 'stale'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'knowledge_base_id', length: 36)]
    private string $knowledgeBaseId;

    #[ORM\Column(name: 'source_type', length: 40)]
    private string $sourceType;

    #[ORM\Column(name: 'source_ref', length: 80)]
    private string $sourceRef;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mime = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $checksum = null;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $acl = [];

    #[ORM\Column(name: 'indexed_at', nullable: true)]
    private ?\DateTimeImmutable $indexedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $acl
     */
    public function __construct(
        string $knowledgeBaseId,
        string $sourceType,
        string $sourceRef,
        string $title,
        array $acl = [],
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->knowledgeBaseId = $knowledgeBaseId;
        $this->sourceType = $sourceType;
        $this->sourceRef = $sourceRef;
        $this->title = $title;
        $this->acl = $acl;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function knowledgeBaseId(): string
    {
        return $this->knowledgeBaseId;
    }

    public function sourceType(): string
    {
        return $this->sourceType;
    }

    public function sourceRef(): string
    {
        return $this->sourceRef;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function mime(): ?string
    {
        return $this->mime;
    }

    public function checksum(): ?string
    {
        return $this->checksum;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /** @return array<string, mixed> */
    public function acl(): array
    {
        return $this->acl;
    }

    public function indexedAt(): ?\DateTimeImmutable
    {
        return $this->indexedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->touch();
    }

    public function setMime(?string $mime): void
    {
        $this->mime = $mime;
        $this->touch();
    }

    public function setChecksum(?string $checksum): void
    {
        $this->checksum = $checksum;
        $this->touch();
    }

    public function markReady(): void
    {
        $this->status = 'ready';
        $this->errorMessage = null;
        $this->indexedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function markFailed(string $message): void
    {
        $this->status = 'failed';
        $this->errorMessage = $message;
        $this->touch();
    }

    public function markPending(): void
    {
        $this->status = 'pending';
        $this->errorMessage = null;
        $this->touch();
    }

    public function markStale(): void
    {
        $this->status = 'stale';
        $this->touch();
    }

    /** @param array<string, mixed> $acl */
    public function setAcl(array $acl): void
    {
        $this->acl = $acl;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
