<?php
declare(strict_types=1);
namespace Modules\Documents\Domain;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'document_links')]
#[ORM\Index(name: 'idx_doc_link_entity', columns: ['entity_type', 'entity_id'])]
class DocumentLink
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;
    #[ORM\Column(name: 'document_id', length: 36)]
    private string $documentId;
    #[ORM\Column(name: 'entity_type', length: 80)]
    private string $entityType;
    #[ORM\Column(name: 'entity_id', length: 36)]
    private string $entityId;
    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $documentId, string $entityType, string $entityId)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->documentId = $documentId;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->createdAt = new \DateTimeImmutable();
    }
    public function id(): string { return $this->id; }
    public function documentId(): string { return $this->documentId; }
    public function entityType(): string { return $this->entityType; }
    public function entityId(): string { return $this->entityId; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
}