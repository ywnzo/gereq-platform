<?php

declare(strict_types=1);

namespace Modules\iDoklad\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Vazba lokální entity (customer / invoice …) na iDoklad ID.
 */
#[ORM\Entity]
#[ORM\Table(name: 'idoklad_links')]
#[ORM\UniqueConstraint(name: 'uniq_idoklad_links_entity', columns: ['entity_type', 'entity_id', 'document_type'])]
#[ORM\Index(name: 'idx_idoklad_links_external', columns: ['idoklad_id'])]
class iDokladLink
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'entity_type', length: 64)]
    private string $entityType;

    #[ORM\Column(name: 'entity_id', length: 36)]
    private string $entityId;

    #[ORM\Column(name: 'document_type', length: 32, options: ['default' => 'contact'])]
    private string $documentType = 'contact';

    #[ORM\Column(name: 'idoklad_id', length: 64)]
    private string $iDokladId;

    #[ORM\Column(name: 'meta_json', type: 'text', nullable: true)]
    private ?string $metaJson = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $entityType, string $entityId, string $iDokladId, string $documentType = 'contact')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->iDokladId = $iDokladId;
        $this->documentType = $documentType;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function entityType(): string
    {
        return $this->entityType;
    }

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function documentType(): string
    {
        return $this->documentType;
    }

    public function iDokladId(): string
    {
        return $this->iDokladId;
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        if ($this->metaJson === null || $this->metaJson === '') {
            return [];
        }
        $decoded = json_decode($this->metaJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setIDokladId(string $iDokladId): void
    {
        $this->iDokladId = $iDokladId;
        $this->touch();
    }

    /** @param array<string, mixed> $meta */
    public function setMeta(array $meta): void
    {
        $this->metaJson = $meta === [] ? null : (json_encode($meta, JSON_UNESCAPED_UNICODE) ?: null);
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
