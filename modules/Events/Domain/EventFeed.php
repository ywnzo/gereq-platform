<?php

declare(strict_types=1);

namespace Modules\Events\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Gereq\Support\Str;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'kdeaco_event_feeds')]
#[ORM\UniqueConstraint(name: 'uniq_kdeaco_event_feeds_key', columns: ['feed_key'])]
#[ORM\Index(name: 'idx_kdeaco_event_feeds_enabled', columns: ['enabled'])]
#[FormEntity('events.feed.save')]
#[FormGenerator(
    exclude: ['id', 'config', 'lastSyncedAt', 'lastError', 'lastFetched', 'lastCreated', 'lastUpdated', 'createdAt', 'updatedAt'],
    form: 'events.feed.save',
    cols: 2,
)]
class EventFeed
{
    public const DRIVERS = ['ical', 'tribe_json', 'html_list', 'klatovy_asp', 'vikendo'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'feed_key', length: 40)]
    #[FormField(required: true, label: 'events.feeds.field.key', hint: 'events.feeds.field.key.hint', group: 'basic', order: 10)]
    private string $key;

    #[ORM\Column(length: 120)]
    #[FormField(required: true, label: 'events.feeds.field.name', group: 'basic', order: 20)]
    private string $name;

    #[ORM\Column(length: 32)]
    #[FormField(required: true, label: 'events.feeds.field.driver', type: 'select', choicesFrom: 'DRIVERS', choiceLabel: 'events.feeds.driver.', group: 'basic', order: 30)]
    private string $driver;

    #[ORM\Column(length: 500)]
    #[FormField(required: true, label: 'events.feeds.field.url', extra: 'field--full', hint: 'events.feeds.field.url.hint', group: 'basic', order: 40)]
    private string $url;

    #[ORM\Column(length: 40)]
    #[FormField(required: true, label: 'events.field.region', type: 'select', choicesFrom: 'REGIONS', group: 'basic', order: 50)]
    private string $region;

    #[ORM\Column(length: 190, nullable: true)]
    #[FormField(label: 'events.field.organizer', group: 'basic', order: 60)]
    private ?string $organizer = null;

    #[ORM\Column]
    #[FormField(label: 'events.feeds.field.enabled', type: 'checkbox', group: 'basic', order: 70)]
    private bool $enabled = true;

    #[ORM\Column(name: 'sort_order')]
    #[FormField(label: 'events.feeds.field.sort', group: 'basic', order: 80)]
    private int $sortOrder = 0;

    /** @var array<string, mixed>|null Driver-specific options. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $config = null;

    #[ORM\Column(name: 'last_synced_at', nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'last_fetched')]
    private int $lastFetched = 0;

    #[ORM\Column(name: 'last_created')]
    private int $lastCreated = 0;

    #[ORM\Column(name: 'last_updated')]
    private int $lastUpdated = 0;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $key, string $name, string $driver, string $url, string $region)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->key = Str::slug(trim($key));
        $this->name = trim($name);
        $this->driver = $driver;
        $this->url = trim($url);
        $this->region = $region;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** Proxy CatalogEvent regions for FormGenerator choicesFrom. */
    public const REGIONS = CatalogEvent::REGIONS;

    public function id(): string
    {
        return $this->id;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function region(): string
    {
        return $this->region;
    }

    public function organizer(): ?string
    {
        return $this->organizer;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return is_array($this->config) ? $this->config : [];
    }

    public function configString(string $key, string $default = ''): string
    {
        $value = $this->config()[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function configInt(string $key, int $default = 0): int
    {
        $value = $this->config()[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return list<string> */
    public function configList(string $key): array
    {
        $value = $this->config()[$key] ?? [];
        if (is_string($value)) {
            $parts = preg_split('/[\s,;]+/', $value) ?: [];

            return array_values(array_filter(array_map('trim', $parts)));
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $out[] = trim((string) $item);
            }
        }

        return $out;
    }

    public function lastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function lastFetched(): int
    {
        return $this->lastFetched;
    }

    public function lastCreated(): int
    {
        return $this->lastCreated;
    }

    public function lastUpdated(): int
    {
        return $this->lastUpdated;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setKey(string $key): void
    {
        $slug = Str::slug(trim($key));
        if ($slug !== '') {
            $this->key = $slug;
        }
        $this->touch();
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
        $this->touch();
    }

    public function setDriver(string $driver): void
    {
        $this->driver = in_array($driver, self::DRIVERS, true) ? $driver : 'ical';
        $this->touch();
    }

    public function setUrl(string $url): void
    {
        $this->url = trim($url);
        $this->touch();
    }

    public function setRegion(string $region): void
    {
        $this->region = $region;
        $this->touch();
    }

    public function setOrganizer(?string $organizer): void
    {
        $organizer = $organizer !== null ? trim($organizer) : '';
        $this->organizer = $organizer !== '' ? $organizer : null;
        $this->touch();
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
        $this->touch();
    }

    /** @param array<string, mixed>|null $config */
    public function setConfig(?array $config): void
    {
        $this->config = $config !== null && $config !== [] ? $config : null;
        $this->touch();
    }

    public function markImportResult(int $fetched, int $created, int $updated, ?string $error = null): void
    {
        $this->lastSyncedAt = new \DateTimeImmutable();
        $this->lastFetched = max(0, $fetched);
        $this->lastCreated = max(0, $created);
        $this->lastUpdated = max(0, $updated);
        $error = $error !== null ? trim($error) : '';
        $this->lastError = $error !== '' ? mb_substr($error, 0, 2000) : null;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
