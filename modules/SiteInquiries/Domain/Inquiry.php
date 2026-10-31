<?php

declare(strict_types=1);

namespace Modules\SiteInquiries\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'site_inquiries')]
#[ORM\Index(name: 'idx_site_inquiries_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_site_inquiries_email', columns: ['email'])]
#[ORM\Index(name: 'idx_site_inquiries_status', columns: ['status'])]
#[ORM\Index(name: 'idx_site_inquiries_notification_due', columns: ['notification_status', 'notification_next_at'])]
class Inquiry
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(length: 190)]
    private string $email;

    #[ORM\Column(length: 40)]
    private string $phone;

    #[ORM\Column(name: 'preferred_date', length: 32, nullable: true)]
    private ?string $preferredDate = null;

    #[ORM\Column(name: 'route_from', length: 190, nullable: true)]
    private ?string $routeFrom = null;

    #[ORM\Column(name: 'route_to', length: 190, nullable: true)]
    private ?string $routeTo = null;

    #[ORM\Column(type: 'text')]
    private string $details;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_OPEN])]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(name: 'resolved_at', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(name: 'customer_id', length: 36, nullable: true)]
    private ?string $customerId = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'notification_status', length: 20, options: ['default' => 'not_attempted'])]
    private string $notificationStatus = 'not_attempted';

    #[ORM\Column(name: 'notification_attempts', options: ['default' => 0])]
    private int $notificationAttempts = 0;

    #[ORM\Column(name: 'notification_next_at', nullable: true)]
    private ?\DateTimeImmutable $notificationNextAt = null;

    #[ORM\Column(name: 'notification_lease_until', nullable: true)]
    private ?\DateTimeImmutable $notificationLeaseUntil = null;

    #[ORM\Column(name: 'notification_lease_token', length: 36, nullable: true)]
    private ?string $notificationLeaseToken = null;

    #[ORM\Column(name: 'notification_sent_at', nullable: true)]
    private ?\DateTimeImmutable $notificationSentAt = null;

    public function queueNotification(): void
    {
        $this->notificationStatus = 'queued';
        $this->notificationNextAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function __construct(
        string $name,
        string $email,
        string $phone,
        string $details,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->email = strtolower($email);
        $this->phone = $phone;
        $this->details = $details;
        $this->status = self::STATUS_OPEN;
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

    public function email(): string
    {
        return $this->email;
    }

    public function phone(): string
    {
        return $this->phone;
    }

    public function preferredDate(): ?string
    {
        return $this->preferredDate;
    }

    public function setPreferredDate(?string $preferredDate): void
    {
        $this->preferredDate = $preferredDate !== null && trim($preferredDate) !== ''
            ? trim($preferredDate)
            : null;
    }

    public function routeFrom(): ?string
    {
        return $this->routeFrom;
    }

    public function setRouteFrom(?string $routeFrom): void
    {
        $this->routeFrom = $routeFrom !== null && trim($routeFrom) !== ''
            ? trim($routeFrom)
            : null;
    }

    public function routeTo(): ?string
    {
        return $this->routeTo;
    }

    public function setRouteTo(?string $routeTo): void
    {
        $this->routeTo = $routeTo !== null && trim($routeTo) !== ''
            ? trim($routeTo)
            : null;
    }

    public function details(): string
    {
        return $this->details;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    public function setStatus(string $status): void
    {
        $status = trim($status);
        if (!in_array($status, [self::STATUS_OPEN, self::STATUS_RESOLVED], true)) {
            return;
        }

        $this->status = $status;
        if ($status === self::STATUS_RESOLVED) {
            $this->resolvedAt ??= new \DateTimeImmutable();
        } else {
            $this->resolvedAt = null;
        }
    }

    public function markResolved(): void
    {
        $this->setStatus(self::STATUS_RESOLVED);
    }

    public function markOpen(): void
    {
        $this->setStatus(self::STATUS_OPEN);
    }

    public function resolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function customerId(): ?string
    {
        return $this->customerId;
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
