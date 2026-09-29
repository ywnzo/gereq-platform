<?php

declare(strict_types=1);

namespace Gereq\Notifications\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'notification_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_notif_pref', columns: ['user_id', 'channel', 'event_type'])]
class NotificationPreference
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'user_id', length: 36)]
    private string $userId;

    #[ORM\Column(length: 32)]
    private string $channel;

    #[ORM\Column(name: 'event_type', length: 120)]
    private string $eventType;

    #[ORM\Column]
    private bool $enabled = true;

    public function __construct(string $userId, string $channel, string $eventType, bool $enabled = true)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->userId = $userId;
        $this->channel = $channel;
        $this->eventType = $eventType;
        $this->enabled = $enabled;
    }

    public function id(): string
    {
        return $this->id;
    }
    public function userId(): string
    {
        return $this->userId;
    }
    public function channel(): string
    {
        return $this->channel;
    }
    public function eventType(): string
    {
        return $this->eventType;
    }
    public function enabled(): bool
    {
        return $this->enabled;
    }
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
}
