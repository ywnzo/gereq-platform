<?php

declare(strict_types=1);

namespace Modules\Emailing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'emailing_attachments')]
#[ORM\Index(name: 'idx_emailing_attachments_message', columns: ['message_id'])]
class MailAttachment
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'message_id', length: 36)]
    private string $messageId;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 120)]
    private string $mime;

    #[ORM\Column]
    private int $size = 0;

    #[ORM\Column(length: 500)]
    private string $path;

    #[ORM\Column(name: 'content_id', length: 190, nullable: true)]
    private ?string $contentId = null;

    #[ORM\Column(name: 'is_inline', options: ['default' => false])]
    private bool $isInline = false;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $messageId,
        string $filename,
        string $mime,
        int $size,
        string $path,
        ?string $contentId = null,
        bool $isInline = false,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->messageId = $messageId;
        $this->filename = $filename;
        $this->mime = $mime;
        $this->size = $size;
        $this->path = $path;
        $this->contentId = $contentId !== null && $contentId !== '' ? trim($contentId, "<> \t") : null;
        $this->isInline = $isInline;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function messageId(): string
    {
        return $this->messageId;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function mime(): string
    {
        return $this->mime;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function contentId(): ?string
    {
        return $this->contentId;
    }

    public function isInline(): bool
    {
        return $this->isInline;
    }

    /** Ke stažení: ne-inline, nebo „inline“ soubor který není obrázek (chybně označený PDF apod.). */
    public function isDownloadable(): bool
    {
        if (!$this->isInline) {
            return true;
        }

        return !str_starts_with(strtolower($this->mime), 'image/');
    }

    public function setInline(bool $inline): void
    {
        $this->isInline = $inline;
    }

    public function setFilename(string $filename): void
    {
        $this->filename = $filename;
    }
}
