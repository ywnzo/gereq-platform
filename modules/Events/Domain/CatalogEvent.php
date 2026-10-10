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
#[ORM\Table(name: 'kdeaco_events')]
#[ORM\UniqueConstraint(name: 'uniq_kdeaco_events_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_kdeaco_events_source', columns: ['source', 'source_id'])]
#[ORM\Index(name: 'idx_kdeaco_events_status', columns: ['status'])]
#[ORM\Index(name: 'idx_kdeaco_events_starts', columns: ['starts_at'])]
#[ORM\Index(name: 'idx_kdeaco_events_region', columns: ['region'])]
#[ORM\Index(name: 'idx_kdeaco_events_source', columns: ['source'])]
#[ORM\Index(name: 'idx_kdeaco_events_status_starts', columns: ['status', 'starts_at'])]
#[ORM\Index(name: 'idx_kdeaco_events_status_region_starts', columns: ['status', 'region', 'starts_at'])]
#[ORM\Index(name: 'idx_kdeaco_events_status_category', columns: ['status', 'category'])]
#[ORM\Index(name: 'idx_kdeaco_events_spotlight_region', columns: ['spotlight', 'region'])]
#[ORM\Index(name: 'idx_kdeaco_events_ends', columns: ['ends_at'])]
#[FormEntity('events.save')]
#[FormGenerator(
    exclude: ['id', 'submittedByCustomerId', 'approvedAt', 'archivedAt', 'rejectedAt', 'rejectionReason', 'createdAt', 'updatedAt', 'sections', 'gallery', 'imageKey', 'mediaAssetId', 'source', 'sourceId', 'syncedAt', 'searchVector'],
    form: 'events.save',
    cols: 2,
)]
class CatalogEvent
{
    public const STATUSES = ['pending', 'published', 'archived', 'rejected'];

    public const SPOTLIGHTS = ['none', 'week', 'month'];

    public const CATEGORIES = ['Zábava', 'Sport', 'Pro děti', 'Kultura', 'Ostatní'];

    public const REGIONS = [
        'Domažlice',
        'Klatovy',
        'Plzeň-město',
        'Plzeň-jih',
        'Plzeň-sever',
        'Rokycany',
        'Tachov',
    ];

    public const COLORS = ['purple', 'green', 'orange', 'pink', 'yellow', 'red'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'events.field.title', extra: 'field--full', group: 'basic', order: 10)]
    private string $title;

    #[ORM\Column(length: 190)]
    #[FormField(label: 'events.field.slug', group: 'basic', options: ['placeholder' => 'auto'], order: 20)]
    private string $slug;

    #[ORM\Column(type: 'text')]
    #[FormField(required: true, label: 'events.field.description', type: 'textarea', extra: 'field--full', group: 'content', hint: 'events.field.description.hint', order: 10)]
    private string $description = '';

    #[ORM\Column(name: 'long_description', type: 'text', nullable: true)]
    #[FormField(label: 'events.field.long_description', type: 'editor', extra: 'field--full', group: 'content', hint: 'events.field.long_description.hint', order: 20)]
    private ?string $longDescription = null;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $sections = null;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $gallery = null;

    #[ORM\Column(length: 40)]
    #[FormField(required: true, label: 'events.field.category', type: 'select', choicesFrom: 'CATEGORIES', group: 'basic', order: 30)]
    private string $category;

    #[ORM\Column(length: 32)]
    #[FormField(label: 'events.field.color', type: 'select', choicesFrom: 'COLORS', choiceLabel: 'events.color.', group: 'publish', order: 30)]
    private string $color = 'purple';

    #[ORM\Column(name: 'image_key', length: 64)]
    private string $imageKey = 'other';

    #[ORM\Column(name: 'media_asset_id', length: 36, nullable: true)]
    private ?string $mediaAssetId = null;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'events.field.place', extra: 'field--full', group: 'basic', order: 50)]
    private string $place;

    #[ORM\Column(length: 40)]
    #[FormField(required: true, label: 'events.field.region', type: 'select', choicesFrom: 'REGIONS', group: 'basic', order: 40)]
    private string $region;

    #[ORM\Column(name: 'starts_at')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(name: 'ends_at', nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    /** When false, start date/time is stored but not shown on the public site. */
    #[ORM\Column(name: 'show_starts_at', type: 'boolean', options: ['default' => true])]
    private bool $showStartsAt = true;

    /** When false, end date/time is stored but not shown on the public site. */
    #[ORM\Column(name: 'show_ends_at', type: 'boolean', options: ['default' => true])]
    private bool $showEndsAt = true;

    #[ORM\Column(length: 32)]
    #[FormField(label: 'events.field.time_label', group: 'schedule', hint: 'events.field.time_label.hint', order: 30)]
    private string $timeLabel = '';

    #[ORM\Column(length: 80, nullable: true)]
    #[FormField(label: 'events.field.price', group: 'practical', order: 10)]
    private ?string $price = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[FormField(label: 'events.field.capacity', group: 'practical', order: 20)]
    private ?string $capacity = null;

    #[ORM\Column(length: 190, nullable: true)]
    #[FormField(label: 'events.field.organizer', extra: 'field--full', group: 'practical', order: 30)]
    private ?string $organizer = null;

    #[ORM\Column(name: 'external_url', length: 500, nullable: true)]
    #[FormField(label: 'events.field.external_url', extra: 'field--full', group: 'practical', hint: 'events.field.external_url.hint', order: 40)]
    private ?string $externalUrl = null;

    /** Import provenance key, e.g. domazlice|tachov|klatovy. Null = manual. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $source = null;

    /** Stable id within source (tribe id, slug, hash…). */
    #[ORM\Column(name: 'source_id', length: 190, nullable: true)]
    private ?string $sourceId = null;

    #[ORM\Column(name: 'synced_at', nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    #[ORM\Column(length: 16)]
    #[FormField(label: 'events.field.status', type: 'select', choicesFrom: 'STATUSES', choiceLabel: 'events.status.', group: 'publish', order: 10)]
    private string $status = 'pending';

    #[ORM\Column(length: 16)]
    #[FormField(label: 'events.field.spotlight', type: 'select', choicesFrom: 'SPOTLIGHTS', choiceLabel: 'events.spotlight.', group: 'publish', order: 20)]
    private string $spotlight = 'none';

    #[ORM\Column(name: 'submitted_by_customer_id', length: 36, nullable: true)]
    private ?string $submittedByCustomerId = null;

    #[ORM\Column(name: 'approved_at', nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(name: 'archived_at', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(name: 'rejection_reason', type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(name: 'rejected_at', nullable: true)]
    private ?\DateTimeImmutable $rejectedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /**
     * Postgres FTS document maintained by trg_kdeaco_events_search_vector.
     * Mapped so SchemaTool does not DROP the column after file migrations.
     */
    #[ORM\Column(
        name: 'search_vector',
        type: 'text',
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: 'tsvector DEFAULT NULL',
    )]
    private ?string $searchVector = null;

    public function __construct(string $title, string $category, string $place, string $region, \DateTimeImmutable $startsAt)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->title = trim($title);
        $this->slug = Str::slug($this->title);
        $this->category = $category;
        $this->place = $place;
        $this->region = $region;
        $this->startsAt = $startsAt;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function longDescription(): ?string
    {
        return $this->longDescription;
    }

    /** @return list<array<string, mixed>> */
    public function sections(): array
    {
        return is_array($this->sections) ? array_values($this->sections) : [];
    }

    /** @return list<array<string, mixed>> */
    public function gallery(): array
    {
        return is_array($this->gallery) ? array_values($this->gallery) : [];
    }

    public function category(): string
    {
        return $this->category;
    }

    public function color(): string
    {
        return $this->color;
    }

    public function imageKey(): string
    {
        return $this->imageKey;
    }

    public function mediaAssetId(): ?string
    {
        return $this->mediaAssetId;
    }

    public function place(): string
    {
        return $this->place;
    }

    public function city(): string
    {
        $place = trim($this->place);
        if ($place === '') {
            return $this->regionTown();
        }
        if (preg_match('/^(?P<city>[^\d,|]{2,40}?)\s+[–—-]\s+\S/u', $place, $match) === 1) {
            $city = trim($match['city']);
            if ($city !== '') {
                return $city;
            }
        }
        if (preg_match('/\b\d{3}\s?\d{2}\s+(?P<city>\p{Lu}\p{L}+(?:[\s-]\p{Lu}\p{L}+){0,2})\s*$/u', $place, $match) === 1) {
            return trim($match['city']);
        }
        if (preg_match('/(?P<city>\p{Lu}\p{L}+(?:[\s-]\p{Lu}\p{L}+){0,2})\s*,\s*\d{3}\s?\d{2}\b/u', $place, $match) === 1) {
            return trim($match['city']);
        }
        $regionTown = $this->regionTown();
        if ($regionTown !== '' && preg_match('/\b' . preg_quote($regionTown, '/') . '\b/u', $place) === 1) {
            return $regionTown;
        }
        if ($this->looksLikeTownName($place)) {
            return $place;
        }

        return $regionTown;
    }

    private function regionTown(): string
    {
        return match ($this->region) {
            'Plzeň-město', 'Plzeň-jih', 'Plzeň-sever' => 'Plzeň',
            default => $this->region,
        };
    }

    private function looksLikeTownName(string $place): bool
    {
        if (str_contains($place, ',') || str_contains($place, '|') || mb_strlen($place) > 32) {
            return false;
        }
        $words = preg_split('/\s+/u', $place) ?: [];
        if ($words === [] || count($words) > 3) {
            return false;
        }
        foreach ($words as $word) {
            if (preg_match('/^\p{Lu}/u', $word) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<self> $items
     * @return array<string, list<self>>
     */
    public static function groupByCity(array $items, string $direction = 'asc'): array
    {
        $groups = [];
        foreach ($items as $item) {
            $groups[$item->city()][] = $item;
        }
        $desc = strtolower($direction) === 'desc';
        $collator = class_exists(\Collator::class) ? new \Collator('cs_CZ') : null;
        uksort($groups, static function (string $left, string $right) use ($desc, $collator): int {
            $cmp = $collator !== null
                ? (int) ($collator->compare($left, $right) ?: 0)
                : strcasecmp($left, $right);

            return $desc ? -$cmp : $cmp;
        });

        return $groups;
    }

    public function region(): string
    {
        return $this->region;
    }

    public function startsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function showStartsAt(): bool
    {
        return $this->showStartsAt;
    }

    public function showEndsAt(): bool
    {
        return $this->showEndsAt;
    }

    public function timeLabel(): string
    {
        return $this->timeLabel;
    }

    public function price(): ?string
    {
        return $this->price;
    }

    public function capacity(): ?string
    {
        return $this->capacity;
    }

    public function organizer(): ?string
    {
        return $this->organizer;
    }

    public function externalUrl(): ?string
    {
        return $this->externalUrl;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function sourceId(): ?string
    {
        return $this->sourceId;
    }

    public function syncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function statusLabel(): string
    {
        return 'events.status.' . $this->status;
    }

    public function spotlight(): string
    {
        return $this->spotlight;
    }

    public function submittedByCustomerId(): ?string
    {
        return $this->submittedByCustomerId;
    }

    public function approvedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function rejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function rejectedAt(): ?\DateTimeImmutable
    {
        return $this->rejectedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setTitle(string $title): void
    {
        $this->title = trim($title);
        $this->touch();
    }

    public function setSlug(string $slug): void
    {
        $slug = Str::slug($slug);
        if ($slug !== '') {
            $this->slug = $slug;
        }
        $this->touch();
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    public function setLongDescription(?string $longDescription): void
    {
        $this->longDescription = $longDescription !== null && trim($longDescription) !== '' ? $longDescription : null;
        $this->touch();
    }

    /** @param list<array<string, mixed>>|null $sections */
    public function setSections(?array $sections): void
    {
        $this->sections = $sections !== null && $sections !== [] ? array_values($sections) : null;
        $this->touch();
    }

    /** @param list<array<string, mixed>>|null $gallery */
    public function setGallery(?array $gallery): void
    {
        $this->gallery = $gallery !== null && $gallery !== [] ? array_values($gallery) : null;
        $this->touch();
    }

    public function setCategory(string $category): void
    {
        $this->category = $category;
        $this->touch();
    }

    public function setColor(string $color): void
    {
        $this->color = $color !== '' ? $color : 'purple';
        $this->touch();
    }

    public function setImageKey(string $imageKey): void
    {
        $this->imageKey = $imageKey !== '' ? $imageKey : 'other';
        $this->touch();
    }

    public function setMediaAssetId(?string $mediaAssetId): void
    {
        $id = $mediaAssetId !== null ? trim($mediaAssetId) : '';
        $this->mediaAssetId = $id !== '' ? $id : null;
        $this->touch();
    }

    public function setPlace(string $place): void
    {
        $this->place = $place;
        $this->touch();
    }

    public function setRegion(string $region): void
    {
        $this->region = $region;
        $this->touch();
    }

    public function setStartsAt(\DateTimeImmutable $startsAt): void
    {
        $this->startsAt = $startsAt;
        $this->touch();
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): void
    {
        $this->endsAt = $endsAt;
        $this->touch();
    }

    public function setShowStartsAt(bool $show): void
    {
        $this->showStartsAt = $show;
        $this->touch();
    }

    public function setShowEndsAt(bool $show): void
    {
        $this->showEndsAt = $show;
        $this->touch();
    }

    public function setTimeLabel(string $timeLabel): void
    {
        $this->timeLabel = $timeLabel;
        $this->touch();
    }

    public function setPrice(?string $price): void
    {
        $this->price = $price !== null && trim($price) !== '' ? trim($price) : null;
        $this->touch();
    }

    public function setCapacity(?string $capacity): void
    {
        $this->capacity = $capacity !== null && trim($capacity) !== '' ? trim($capacity) : null;
        $this->touch();
    }

    public function setOrganizer(?string $organizer): void
    {
        $this->organizer = $organizer !== null && trim($organizer) !== '' ? trim($organizer) : null;
        $this->touch();
    }

    public function setExternalUrl(?string $url): void
    {
        $this->externalUrl = self::normalizeExternalUrl($url);
        $this->touch();
    }

    public function setSource(?string $source, ?string $sourceId = null): void
    {
        $source = $source !== null ? trim($source) : '';
        $sourceId = $sourceId !== null ? trim($sourceId) : '';
        $this->source = $source !== '' ? $source : null;
        $this->sourceId = $this->source !== null && $sourceId !== '' ? $sourceId : null;
        $this->touch();
    }

    public function markSynced(?\DateTimeImmutable $at = null): void
    {
        $this->syncedAt = $at ?? new \DateTimeImmutable();
        $this->touch();
    }

    public static function normalizeExternalUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    public function setSubmittedByCustomerId(?string $customerId): void
    {
        $this->submittedByCustomerId = $customerId;
        $this->touch();
    }

    public function publish(): void
    {
        $this->status = 'published';
        $this->approvedAt = new \DateTimeImmutable();
        $this->archivedAt = null;
        $this->rejectionReason = null;
        $this->rejectedAt = null;
        $this->touch();
    }

    public function markPending(): void
    {
        $this->status = 'pending';
        $this->approvedAt = null;
        $this->archivedAt = null;
        $this->rejectionReason = null;
        $this->rejectedAt = null;
        $this->touch();
    }

    public function archive(): void
    {
        $this->status = 'archived';
        $this->spotlight = 'none';
        $this->archivedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function reject(string $reason): void
    {
        $reason = trim($reason);
        $this->status = 'rejected';
        $this->rejectionReason = $reason !== '' ? $reason : null;
        $this->rejectedAt = new \DateTimeImmutable();
        $this->approvedAt = null;
        $this->archivedAt = null;
        $this->touch();
    }

    public function setSpotlight(string $spotlight): void
    {
        $this->spotlight = in_array($spotlight, self::SPOTLIGHTS, true) ? $spotlight : 'none';
        $this->touch();
    }

    public function endsAtOrStart(): \DateTimeImmutable
    {
        return $this->endsAt ?? $this->startsAt;
    }

    public function hasElapsed(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return $this->endsAtOrStart() < $now;
    }

    public function isPubliclyVisible(?\DateTimeImmutable $now = null): bool
    {
        return $this->status === 'published' && !$this->hasElapsed($now);
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
