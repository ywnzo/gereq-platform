<?php

declare(strict_types=1);

namespace Modules\Events\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Gereq\Admin\Resource\AdminField;
use Gereq\Support\Str;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'kdeaco_event_categories')]
#[ORM\UniqueConstraint(name: 'uniq_kdeaco_event_categories_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_kdeaco_event_categories_sort', columns: ['sort_order'])]
#[FormEntity('events.category.save')]
#[FormGenerator(
    exclude: ['id', 'createdAt', 'updatedAt', 'mediaAssetId'],
    form: 'events.category.save',
    cols: 2,
)]
class EventCategory
{
    public const IMAGE_KEYS = ['music', 'sport', 'kids', 'theatre', 'castle', 'band', 'run', 'other', 'city', 'bike', 'rock', 'child'];

    public const EVENT_COLORS = ['purple', 'green', 'orange', 'pink', 'yellow', 'red'];

    public const ICONS = [
        'music_note', 'album', 'headphones', 'mic', 'piano', 'guitar', 'nightlife', 'festival',
        'celebration', 'local_activity', 'confirmation_number', 'theater_comedy', 'theaters',
        'movie', 'live_tv', 'palette', 'brush', 'photo_camera', 'menu_book', 'museum',
        'account_balance', 'castle', 'church', 'sports_soccer', 'sports_basketball', 'sports_tennis',
        'sports_volleyball', 'sports_handball', 'sports_martial_arts', 'sports_esports', 'stadium',
        'fitness_center', 'pool', 'directions_run', 'directions_bike', 'pedal_bike', 'skateboarding',
        'downhill_skiing', 'ice_skating', 'kayaking', 'sailing', 'surfing', 'hiking', 'terrain',
        'landscape', 'forest', 'park', 'camping', 'beach_access', 'waves', 'child_care', 'toys',
        'stroller', 'family_restroom', 'school', 'groups', 'diversity_3', 'volunteer_activism',
        'restaurant', 'local_cafe', 'local_bar', 'wine_bar', 'cake', 'local_florist', 'spa',
        'self_improvement', 'pets', 'cruelty_free', 'favorite', 'star', 'auto_awesome', 'wb_sunny',
        'nightlight', 'fireworks', 'rocket_launch', 'flight', 'train', 'directions_bus',
        'directions_boat', 'map', 'explore', 'location_on', 'storefront', 'shopping_bag',
        'handyman', 'science', 'computer', 'two_wheeler', 'sports_motorsports', 'more_horiz',
    ];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 80)]
    #[AdminField(type: 'text', label: 'events.categories.field.slug', searchable: true, sortable: true, required: true)]
    #[FormField(required: true, label: 'events.categories.field.slug', hint: 'events.categories.field.slug.hint', group: 'identity', order: 20)]
    private string $slug;

    #[ORM\Column(length: 80)]
    #[AdminField(type: 'text', label: 'events.categories.field.name', searchable: true, sortable: true, required: true)]
    #[FormField(required: true, label: 'events.categories.field.name', hint: 'events.categories.field.name.hint', group: 'identity', order: 10)]
    private string $name;

    #[ORM\Column(length: 190, nullable: true)]
    #[FormField(label: 'events.categories.field.blurb', extra: 'field--full', group: 'identity', order: 30)]
    private ?string $blurb = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[FormField(label: 'events.categories.field.icon', type: 'icon', choicesFrom: 'ICONS', hint: 'events.categories.field.icon.hint', group: 'card', order: 10)]
    private ?string $icon = null;

    #[ORM\Column(name: 'color_hex', length: 16, nullable: true)]
    #[FormField(label: 'events.categories.field.color_hex', type: 'color', group: 'card', order: 30)]
    private ?string $colorHex = null;

    #[ORM\Column(length: 16, nullable: true)]
    #[FormField(label: 'events.categories.field.tint', type: 'color', group: 'card', order: 40)]
    private ?string $tint = null;

    #[ORM\Column(name: 'image_key', length: 64)]
    #[FormField(label: 'events.categories.field.image_key', type: 'select', choicesFrom: 'IMAGE_KEYS', group: 'card', order: 20)]
    private string $imageKey = 'other';

    #[ORM\Column(name: 'media_asset_id', length: 36, nullable: true)]
    private ?string $mediaAssetId = null;

    #[ORM\Column(name: 'event_color', length: 32)]
    #[FormField(label: 'events.categories.field.event_color', type: 'select', choicesFrom: 'EVENT_COLORS', choiceLabel: 'events.color.', group: 'event', order: 10)]
    private string $eventColor = 'purple';

    #[ORM\Column(length: 190, nullable: true)]
    #[FormField(label: 'events.categories.field.kicker', extra: 'field--full', hint: 'events.categories.field.kicker.hint', group: 'event', order: 20)]
    private ?string $kicker = null;

    #[ORM\Column(name: 'show_on_home', type: 'boolean')]
    #[FormField(label: 'events.categories.field.homepage', type: 'switch', hint: 'events.categories.field.homepage.hint', group: 'card', order: 50)]
    private bool $showOnHome = true;

    #[ORM\Column(type: 'boolean')]
    #[FormField(label: 'events.categories.field.assignable', type: 'switch', hint: 'events.categories.field.assignable.hint', group: 'event', order: 30)]
    private bool $assignable = true;

    #[ORM\Column(name: 'hide_dates', type: 'boolean', options: ['default' => false])]
    #[FormField(label: 'events.categories.field.hide_dates', type: 'switch', hint: 'events.categories.field.hide_dates.hint', group: 'event', order: 40)]
    private bool $hideDates = false;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    #[FormField(label: 'events.categories.field.sort', type: 'number', group: 'card', order: 60)]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $slug)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = trim($name);
        $this->slug = trim($slug);
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function blurb(): ?string
    {
        return $this->blurb;
    }

    public function icon(): ?string
    {
        return $this->icon;
    }

    public function colorHex(): ?string
    {
        return $this->colorHex;
    }

    public function tint(): ?string
    {
        return $this->tint;
    }

    public function imageKey(): string
    {
        return $this->imageKey;
    }

    public function mediaAssetId(): ?string
    {
        return $this->mediaAssetId;
    }

    public function eventColor(): string
    {
        return $this->eventColor;
    }

    public function kicker(): ?string
    {
        return $this->kicker;
    }

    public function showOnHome(): bool
    {
        return $this->showOnHome;
    }

    public function assignable(): bool
    {
        return $this->assignable;
    }

    public function hideDates(): bool
    {
        return $this->hideDates;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
        $this->touch();
    }

    public function setSlug(string $slug): void
    {
        $slug = trim($slug);
        $this->slug = $slug !== '' ? $slug : Str::slug($this->name);
        $this->touch();
    }

    public function setBlurb(?string $blurb): void
    {
        $this->blurb = $blurb !== null && trim($blurb) !== '' ? trim($blurb) : null;
        $this->touch();
    }

    public function setIcon(?string $icon): void
    {
        $this->icon = $icon !== null && trim($icon) !== '' ? trim($icon) : null;
        $this->touch();
    }

    public function setColorHex(?string $colorHex): void
    {
        $this->colorHex = $colorHex !== null && trim($colorHex) !== '' ? trim($colorHex) : null;
        $this->touch();
    }

    public function setTint(?string $tint): void
    {
        $this->tint = $tint !== null && trim($tint) !== '' ? trim($tint) : null;
        $this->touch();
    }

    public function setImageKey(string $imageKey): void
    {
        $this->imageKey = $imageKey !== '' ? $imageKey : 'other';
        $this->touch();
    }

    public function setMediaAssetId(?string $mediaAssetId): void
    {
        $this->mediaAssetId = $mediaAssetId !== null && $mediaAssetId !== '' ? $mediaAssetId : null;
        $this->touch();
    }

    public function setEventColor(string $eventColor): void
    {
        $this->eventColor = in_array($eventColor, self::EVENT_COLORS, true) ? $eventColor : 'purple';
        $this->touch();
    }

    public function setKicker(?string $kicker): void
    {
        $this->kicker = $kicker !== null && trim($kicker) !== '' ? trim($kicker) : null;
        $this->touch();
    }

    public function setShowOnHome(bool $showOnHome): void
    {
        $this->showOnHome = $showOnHome;
        $this->touch();
    }

    public function setAssignable(bool $assignable): void
    {
        $this->assignable = $assignable;
        $this->touch();
    }

    public function setHideDates(bool $hideDates): void
    {
        $this->hideDates = $hideDates;
        $this->touch();
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
        $this->touch();
    }

    /** Filter value for the storefront card. Empty = no category filter. */
    public function filter(): string
    {
        return $this->assignable ? $this->slug : '';
    }

    /**
     * @param array<string, string> $photos
     * @return array<string, mixed>
     */
    public function toCard(array $photos = []): array
    {
        $key = $this->imageKey;
        $imageUrl = \Modules\Events\Application\EventMediaLibrary::publicUrl($this->mediaAssetId)
            ?? ($photos[$key] ?? null);

        return [
            'name' => $this->name,
            'image' => $key,
            'image_url' => $imageUrl,
            'icon' => $this->icon ?? 'more_horiz',
            'color' => $this->colorHex ?? '#526877',
            'tint' => $this->tint ?? '#f7f9fa',
            'filter' => $this->filter(),
            'blurb' => $this->blurb ?? '',
        ];
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
