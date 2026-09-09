<?php

declare(strict_types=1);

namespace Modules\Emailing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'emailing_labels')]
#[ORM\UniqueConstraint(name: 'uniq_emailing_label_keyword', columns: ['account_id', 'keyword'])]
#[ORM\Index(name: 'idx_emailing_labels_account', columns: ['account_id'])]
class MailLabel
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'account_id', length: 36)]
    private string $accountId;

    #[ORM\Column(length: 120)]
    private string $name;

    /** IMAP flag-keyword (RFC 3501 atom), např. Work nebo Important. */
    #[ORM\Column(length: 80)]
    private string $keyword;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $accountId, string $name, string $keyword, ?string $color = null)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->accountId = $accountId;
        $this->name = $name;
        $this->keyword = $keyword;
        $this->color = $color;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function keyword(): string
    {
        return $this->keyword;
    }

    public function color(): ?string
    {
        return $this->color;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setColor(?string $color): void
    {
        $this->color = $color;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
