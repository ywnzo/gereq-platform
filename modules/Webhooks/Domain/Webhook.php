<?php

declare(strict_types=1);

namespace Modules\Webhooks\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'webhooks')]
#[ORM\Index(name: 'idx_webhooks_status', columns: ['status'])]
class Webhook
{
    public const STATUSES = ['active', 'paused', 'failing'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\Column(length: 128)]
    private string $secret;

    #[ORM\Column(name: 'previous_secret', length: 128, nullable: true)]
    private ?string $previousSecret = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $events = [];

    #[ORM\Column(length: 32)]
    private string $status = 'active';

    #[ORM\Column(name: 'timeout_seconds')]
    private int $timeoutSeconds = 10;

    #[ORM\Column(name: 'consecutive_failures')]
    private int $consecutiveFailures = 0;

    #[ORM\Column(name: 'last_delivery_at', nullable: true)]
    private ?\DateTimeImmutable $lastDeliveryAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $events
     */
    public function __construct(string $name, string $url, array $events = ['*'], int $timeoutSeconds = 10)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->url = $url;
        $this->secret = self::generateSecret();
        $this->events = array_values($events);
        $this->timeoutSeconds = max(1, $timeoutSeconds);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function generateSecret(): string
    {
        return 'whsec_' . bin2hex(random_bytes(24));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function previousSecret(): ?string
    {
        return $this->previousSecret;
    }

    /** @return list<string> */
    public function events(): array
    {
        return $this->events;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function lastDeliveryAt(): ?\DateTimeImmutable
    {
        return $this->lastDeliveryAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isDeliverable(): bool
    {
        return in_array($this->status, ['active', 'failing'], true);
    }

    public function matchesEvent(string $type): bool
    {
        foreach ($this->events as $pattern) {
            if ($pattern === '*') {
                return true;
            }
            if ($pattern === $type) {
                return true;
            }
            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -1);
                if (str_starts_with($type, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function rotateSecret(): string
    {
        $this->previousSecret = $this->secret;
        $this->secret = self::generateSecret();
        $this->touch();

        return $this->secret;
    }

    public function markSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->lastDeliveryAt = new \DateTimeImmutable();
        if ($this->status === 'failing') {
            $this->status = 'active';
        }
        $this->touch();
    }

    public function markFailure(): void
    {
        $this->consecutiveFailures++;
        $this->lastDeliveryAt = new \DateTimeImmutable();
        if ($this->status === 'active') {
            $this->status = 'failing';
        }
        $this->touch();
    }

    public function pause(): void
    {
        $this->status = 'paused';
        $this->touch();
    }

    public function activate(): void
    {
        $this->status = 'active';
        $this->consecutiveFailures = 0;
        $this->touch();
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
        $this->touch();
    }

    /** @param list<string> $events */
    public function setEvents(array $events): void
    {
        $this->events = array_values($events);
        $this->touch();
    }

    public function setTimeoutSeconds(int $timeoutSeconds): void
    {
        $this->timeoutSeconds = max(1, $timeoutSeconds);
        $this->touch();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'active' => 'webhooks.status.active',
            'paused' => 'webhooks.status.paused',
            'failing' => 'webhooks.status.failing',
            default => $this->status,
        };
    }

    public function secretPreview(): string
    {
        if (strlen($this->secret) <= 12) {
            return '••••••••';
        }

        return substr($this->secret, 0, 8) . '…' . substr($this->secret, -4);
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
