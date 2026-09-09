<?php

declare(strict_types=1);

namespace Modules\Emailing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'emailing_bodies')]
#[ORM\UniqueConstraint(name: 'uniq_emailing_body_message', columns: ['message_id'])]
class MailBody
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'message_id', length: 36)]
    private string $messageId;

    #[ORM\Column(name: 'text_plain', type: 'text', nullable: true)]
    private ?string $textPlain = null;

    #[ORM\Column(name: 'text_html', type: 'text', nullable: true)]
    private ?string $textHtml = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $messageId, ?string $textPlain, ?string $textHtml)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->messageId = $messageId;
        $this->textPlain = $textPlain;
        $this->textHtml = $textHtml;
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

    public function textPlain(): ?string
    {
        return $this->textPlain;
    }

    public function textHtml(): ?string
    {
        return $this->textHtml;
    }

    public function setContent(?string $textPlain, ?string $textHtml): void
    {
        $this->textPlain = $textPlain;
        $this->textHtml = $textHtml;
    }
}
