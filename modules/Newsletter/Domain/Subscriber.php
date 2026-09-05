<?php

declare(strict_types=1);

namespace Modules\Newsletter\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'newsletter_subscribers')]
#[ORM\UniqueConstraint(name: 'uniq_newsletter_subscribers_email', columns: ['email'])]
#[ORM\Index(name: 'idx_newsletter_subscribers_status', columns: ['status'])]
class Subscriber
{
    public const STATUSES = ['subscribed', 'unsubscribed', 'bounced'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    private string $email;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 32)]
    private string $status = 'subscribed';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(name: 'subscribed_at')]
    private \DateTimeImmutable $subscribedAt;

    #[ORM\Column(name: 'unsubscribed_at', nullable: true)]
    private ?\DateTimeImmutable $unsubscribedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email, ?string $name = null)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->email = strtolower(trim($email));
        $this->name = $name !== null && $name !== '' ? $name : null;
        $now = new \DateTimeImmutable();
        $this->subscribedAt = $now;
        $this->createdAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function subscribedAt(): \DateTimeImmutable
    {
        return $this->subscribedAt;
    }

    public function unsubscribedAt(): ?\DateTimeImmutable
    {
        return $this->unsubscribedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setName(?string $name): void
    {
        $this->name = $name !== '' ? $name : null;
    }

    public function setSource(?string $source): void
    {
        $this->source = $source !== '' ? $source : null;
    }

    public function subscribe(): void
    {
        $this->status = 'subscribed';
        $this->subscribedAt = new \DateTimeImmutable();
        $this->unsubscribedAt = null;
    }

    public function unsubscribe(): void
    {
        $this->status = 'unsubscribed';
        $this->unsubscribedAt = new \DateTimeImmutable();
    }

    public function markBounced(): void
    {
        $this->status = 'bounced';
    }

    public function statusLabel(): string
    {
        return 'newsletter.subscriber.status.' . $this->status;
    }
}
