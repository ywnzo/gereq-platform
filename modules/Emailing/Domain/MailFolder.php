<?php

declare(strict_types=1);

namespace Modules\Emailing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'emailing_folders')]
#[ORM\UniqueConstraint(name: 'uniq_emailing_folder_remote', columns: ['account_id', 'remote_path'])]
#[ORM\Index(name: 'idx_emailing_folders_account', columns: ['account_id'])]
class MailFolder
{
    public const ROLES = ['inbox', 'sent', 'drafts', 'trash', 'junk', 'other'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'account_id', length: 36)]
    private string $accountId;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(name: 'remote_path', length: 255)]
    private string $remotePath;

    #[ORM\Column(length: 32)]
    private string $role = 'other';

    #[ORM\Column(name: 'uid_validity', nullable: true)]
    private ?int $uidValidity = null;

    #[ORM\Column(name: 'last_uid', nullable: true)]
    private ?int $lastUid = null;

    #[ORM\Column(name: 'unread_count')]
    private int $unreadCount = 0;

    #[ORM\Column(name: 'total_count')]
    private int $totalCount = 0;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(name: 'is_favorite', options: ['default' => false])]
    private bool $isFavorite = false;

    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $accountId, string $name, string $remotePath, string $role = 'other')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->accountId = $accountId;
        $this->name = $name;
        $this->remotePath = $remotePath;
        $this->role = $role;
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

    public function remotePath(): string
    {
        return $this->remotePath;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function uidValidity(): ?int
    {
        return $this->uidValidity;
    }

    public function lastUid(): ?int
    {
        return $this->lastUid;
    }

    public function unreadCount(): int
    {
        return $this->unreadCount;
    }

    public function totalCount(): int
    {
        return $this->totalCount;
    }

    public function color(): ?string
    {
        return $this->color;
    }

    public function isFavorite(): bool
    {
        return $this->isFavorite;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setRemotePath(string $path): void
    {
        $this->remotePath = $path;
        $this->touch();
    }

    public function setRole(string $role): void
    {
        $this->role = $role;
        $this->touch();
    }

    public function setColor(?string $color): void
    {
        $this->color = $color;
        $this->touch();
    }

    public function setFavorite(bool $favorite): void
    {
        $this->isFavorite = $favorite;
        $this->touch();
    }

    public function setSortOrder(int $order): void
    {
        $this->sortOrder = $order;
        $this->touch();
    }

    public function setSyncCursor(?int $uidValidity, ?int $lastUid): void
    {
        $this->uidValidity = $uidValidity;
        $this->lastUid = $lastUid;
        $this->touch();
    }

    public function setCounts(int $unread, int $total): void
    {
        $this->unreadCount = $unread;
        $this->totalCount = $total;
        $this->touch();
    }

    public function isSystemRole(): bool
    {
        return in_array($this->role, ['inbox', 'sent', 'drafts', 'trash', 'junk'], true);
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
