<?php
declare(strict_types=1);
namespace Modules\Documents\Domain;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'documents')]
class Document
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;
    #[ORM\Column(length: 190)]
    private string $title;
    #[ORM\Column(length: 255)]
    private string $path;
    #[ORM\Column(name: 'original_name', length: 255)]
    private string $originalName;
    #[ORM\Column(length: 120)]
    private string $mime;
    #[ORM\Column]
    private int $size = 0;
    #[ORM\Column(name: 'uploaded_by', length: 36, nullable: true)]
    private ?string $uploadedBy = null;
    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $title, string $path, string $originalName, string $mime, int $size, ?string $uploadedBy = null)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->title = $title;
        $this->path = $path;
        $this->originalName = $originalName;
        $this->mime = $mime;
        $this->size = $size;
        $this->uploadedBy = $uploadedBy;
        $this->createdAt = new \DateTimeImmutable();
    }
    public function id(): string { return $this->id; }
    public function title(): string { return $this->title; }
    public function path(): string { return $this->path; }
    public function originalName(): string { return $this->originalName; }
    public function mime(): string { return $this->mime; }
    public function size(): int { return $this->size; }
    public function uploadedBy(): ?string { return $this->uploadedBy; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setTitle(string $title): void { $this->title = $title; }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf'
            || str_ends_with(strtolower($this->originalName), '.pdf');
    }

    /** Icon key for non-image files (see Gereq\Admin\Ui\Icons). */
    public function fileIcon(): string
    {
        if ($this->isPdf()) {
            return 'pdf';
        }
        if (str_starts_with($this->mime, 'text/') || str_contains($this->mime, 'html')) {
            return 'file';
        }

        return 'file';
    }
}