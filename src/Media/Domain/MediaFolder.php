<?php

declare(strict_types=1);

namespace Gereq\Media\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'media_folders')]
#[ORM\Index(name: 'idx_media_folders_parent', columns: ['parent_id'])]
#[ORM\Index(name: 'idx_media_folders_name', columns: ['name'])]
#[FormEntity('media.folder.save')]
class MediaFolder
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    #[FormField(required: true)]
    private string $name;

    #[ORM\Column(name: 'parent_id', length: 36, nullable: true)]
    private ?string $parentId;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, ?string $parentId = null)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = mb_substr(trim($name), 0, 190);
        $this->parentId = $parentId !== null && $parentId !== '' ? $parentId : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function parentId(): ?string
    {
        return $this->parentId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function rename(string $name): void
    {
        $this->name = mb_substr(trim($name), 0, 190);
    }

    public function moveTo(?string $parentId): void
    {
        $this->parentId = $parentId !== null && $parentId !== '' ? $parentId : null;
    }
}
