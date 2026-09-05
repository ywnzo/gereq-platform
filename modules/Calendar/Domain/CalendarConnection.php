<?php

declare(strict_types=1);

namespace Modules\Calendar\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * External calendar account (Google Calendar, Seznam CalDAV, Outlook…).
 */
#[ORM\Entity]
#[ORM\Table(name: 'calendar_connections')]
#[ORM\UniqueConstraint(name: 'uniq_calendar_conn_provider_account', columns: ['provider', 'account_email'])]
class CalendarConnection
{
    public const PROVIDERS = ['google', 'seznam', 'outlook', 'caldav', 'ics'];
    public const STATUSES = ['pending', 'connected', 'error', 'revoked'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 32)]
    private string $provider;

    #[ORM\Column(name: 'account_email', length: 190)]
    private string $accountEmail;

    #[ORM\Column(name: 'display_name', length: 190, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(length: 16)]
    private string $status = 'pending';

    /** Encrypted refresh/access token blob (JSON). */
    #[ORM\Column(name: 'token_cipher', type: 'text', nullable: true)]
    private ?string $tokenCipher = null;

    #[ORM\Column(name: 'caldav_url', length: 500, nullable: true)]
    private ?string $caldavUrl = null;

    #[ORM\Column(name: 'calendar_id', length: 255, nullable: true)]
    private ?string $calendarId = null;

    #[ORM\Column(name: 'sync_token', length: 255, nullable: true)]
    private ?string $syncToken = null;

    #[ORM\Column(name: 'last_synced_at', nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'user_id', length: 36, nullable: true)]
    private ?string $userId = null;

    #[ORM\Column(name: 'color', length: 16)]
    private string $color = 'blue';

    #[ORM\Column(name: 'sync_enabled', type: 'boolean')]
    private bool $syncEnabled = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $provider, string $accountEmail, ?string $userId = null)
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new \InvalidArgumentException('Invalid calendar provider');
        }
        $this->id = Uuid::uuid7()->toString();
        $this->provider = $provider;
        $this->accountEmail = strtolower(trim($accountEmail));
        $this->userId = $userId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string { return $this->id; }
    public function provider(): string { return $this->provider; }
    public function accountEmail(): string { return $this->accountEmail; }
    public function displayName(): ?string { return $this->displayName; }
    public function status(): string { return $this->status; }
    public function tokenCipher(): ?string { return $this->tokenCipher; }
    public function caldavUrl(): ?string { return $this->caldavUrl; }
    public function calendarId(): ?string { return $this->calendarId; }
    public function syncToken(): ?string { return $this->syncToken; }
    public function lastSyncedAt(): ?\DateTimeImmutable { return $this->lastSyncedAt; }
    public function lastError(): ?string { return $this->lastError; }
    public function userId(): ?string { return $this->userId; }
    public function color(): string { return $this->color; }
    public function syncEnabled(): bool { return $this->syncEnabled; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setDisplayName(?string $v): void { $this->displayName = $v; $this->touch(); }
    public function setStatus(string $v): void
    {
        $this->status = in_array($v, self::STATUSES, true) ? $v : 'pending';
        $this->touch();
    }
    public function setTokenCipher(?string $v): void { $this->tokenCipher = $v; $this->touch(); }
    public function setCaldavUrl(?string $v): void { $this->caldavUrl = $v; $this->touch(); }
    public function setCalendarId(?string $v): void { $this->calendarId = $v; $this->touch(); }
    public function setSyncToken(?string $v): void { $this->syncToken = $v; $this->touch(); }
    public function markSynced(): void
    {
        $this->lastSyncedAt = new \DateTimeImmutable();
        $this->lastError = null;
        $this->status = 'connected';
        $this->touch();
    }
    public function markError(string $message): void
    {
        $this->lastError = $message;
        $this->status = 'error';
        $this->touch();
    }
    public function setColor(string $v): void { $this->color = $v; $this->touch(); }
    public function setSyncEnabled(bool $v): void { $this->syncEnabled = $v; $this->touch(); }

    public function providerLabel(): string { return 'calendar.provider.' . $this->provider; }
    public function statusLabel(): string { return 'calendar.connection.status.' . $this->status; }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
