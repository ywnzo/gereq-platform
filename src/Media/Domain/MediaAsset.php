<?php

declare(strict_types=1);

namespace Gereq\Media\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'media_assets')]
#[ORM\Index(name: 'idx_media_assets_mime', columns: ['mime'])]
#[ORM\Index(name: 'idx_media_assets_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_media_assets_folder', columns: ['folder_id'])]
#[FormEntity('media.save')]
#[FormEntity('media.upload')]
#[FormGenerator(
    exclude: ['id', 'path', 'mime', 'size', 'width', 'height', 'uploadedBy', 'createdAt'],
    form: 'media.save',
    cols: 1,
)]
class MediaAsset
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    #[FormField(label: 'media.field.title', hint: 'media.field.title.hint', order: 10)]
    private string $title;

    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\Column(length: 120)]
    private string $mime;

    #[ORM\Column]
    private int $size = 0;

    #[ORM\Column(length: 255, nullable: true)]
    #[FormField(label: 'media.field.alt', order: 20)]
    private ?string $alt = null;

    #[ORM\Column(nullable: true)]
    private ?int $width = null;

    #[ORM\Column(nullable: true)]
    private ?int $height = null;

    #[ORM\Column(name: 'uploaded_by', length: 36, nullable: true)]
    private ?string $uploadedBy = null;

    #[ORM\Column(name: 'folder_id', length: 36, nullable: true)]
    #[FormField(
        label: 'media.field.folder',
        type: 'select',
        name: 'folder_id',
        options: ['searchable' => true],
        order: 30,
    )]
    private ?string $folderId = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $title,
        string $path,
        string $mime,
        int $size,
        ?string $uploadedBy = null,
        ?string $alt = null,
        ?int $width = null,
        ?int $height = null,
        ?string $folderId = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->title = $title;
        $this->path = $path;
        $this->mime = $mime;
        $this->size = $size;
        $this->uploadedBy = $uploadedBy;
        $this->alt = $alt !== '' ? $alt : null;
        $this->width = $width;
        $this->height = $height;
        $this->folderId = $folderId !== null && $folderId !== '' ? $folderId : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function mime(): string
    {
        return $this->mime;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function alt(): ?string
    {
        return $this->alt;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function height(): ?int
    {
        return $this->height;
    }

    public function uploadedBy(): ?string
    {
        return $this->uploadedBy;
    }

    public function folderId(): ?string
    {
        return $this->folderId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    public function setMime(string $mime): void
    {
        $this->mime = $mime;
    }

    public function setSize(int $size): void
    {
        $this->size = max(0, $size);
    }

    public function setAlt(?string $alt): void
    {
        $this->alt = $alt !== null && $alt !== '' ? $alt : null;
    }

    public function setDimensions(?int $width, ?int $height): void
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function moveTo(?string $folderId): void
    {
        $this->folderId = $folderId !== null && $folderId !== '' ? $folderId : null;
    }
}
