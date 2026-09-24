<?php

declare(strict_types=1);

namespace Modules\Engage\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

/**
 * Dynamic marketing content spot — composes copy/CTA and optionally links CMS / listings / products.
 * Does not duplicate CMS pages or newsletter campaigns; it references them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'engage_spots')]
#[ORM\UniqueConstraint(name: 'uniq_engage_spots_key', columns: ['placement_key'])]
#[ORM\Index(name: 'idx_engage_spots_active', columns: ['is_active'])]
#[ORM\Index(name: 'idx_engage_spots_campaign', columns: ['campaign_id'])]
#[FormEntity('engage.spot.save')]
#[FormGenerator(
    exclude: ['id', 'createdAt', 'updatedAt', 'imageUrl', 'refId', 'campaignId', 'cmsPageId'],
    form: 'engage.spot.save',
    cols: 1,
)]
class Spot
{
    public const SOURCES = [
        'custom',
        'cms_page',
        'listings_path',
        'product',
        'external',
    ];

    public const PLACEMENTS = [
        'homepage_hero',
        'homepage_banner',
        'listings_promo',
        'footer_cta',
        'popup',
        'custom',
    ];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    /** Stable key for FE lookup, e.g. homepage_hero */
    #[ORM\Column(name: 'placement_key', length: 80)]
    #[FormField(required: true, label: 'engage.field.placement_key', hint: 'engage.field.placement_key.hint', order: 10)]
    private string $placementKey;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'engage.field.title', order: 20)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(label: 'engage.field.body', type: 'textarea', order: 30)]
    private ?string $body = null;

    #[ORM\Column(length: 32)]
    #[FormField(
        required: true,
        label: 'engage.field.source',
        type: 'select',
        choicesFrom: 'SOURCES',
        order: 40,
    )]
    private string $source = 'custom';

    /** Optional FK into CMS / product / listing depending on source */
    #[ORM\Column(name: 'ref_id', length: 36, nullable: true)]
    private ?string $refId = null;

    #[ORM\Column(name: 'cms_page_id', length: 36, nullable: true)]
    private ?string $cmsPageId = null;

    #[ORM\Column(name: 'campaign_id', length: 36, nullable: true)]
    private ?string $campaignId = null;

    #[ORM\Column(name: 'cta_label', length: 120, nullable: true)]
    #[FormField(label: 'engage.field.cta_label', order: 50)]
    private ?string $ctaLabel = null;

    #[ORM\Column(name: 'cta_url', length: 2048, nullable: true)]
    #[FormField(label: 'engage.field.cta_url', hint: 'engage.field.cta_url.spot_hint', order: 60)]
    private ?string $ctaUrl = null;

    #[ORM\Column(name: 'image_url', length: 2048, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(name: 'sort_order')]
    #[FormField(label: 'engage.field.sort_order', order: 70)]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    #[FormField(name: 'active', label: 'engage.field.active', type: 'switch', order: 80)]
    private bool $active = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $placementKey, string $title)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->placementKey = self::normalizeKey($placementKey);
        $this->title = trim($title);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function placementKey(): string
    {
        return $this->placementKey;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function refId(): ?string
    {
        return $this->refId;
    }

    public function cmsPageId(): ?string
    {
        return $this->cmsPageId;
    }

    public function campaignId(): ?string
    {
        return $this->campaignId;
    }

    public function ctaLabel(): ?string
    {
        return $this->ctaLabel;
    }

    public function ctaUrl(): ?string
    {
        return $this->ctaUrl;
    }

    public function imageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setPlacementKey(string $key): void
    {
        $this->placementKey = self::normalizeKey($key);
        $this->touch();
    }

    public function setTitle(string $title): void
    {
        $this->title = trim($title);
        $this->touch();
    }

    public function setBody(?string $body): void
    {
        $this->body = self::nullIfEmpty($body);
        $this->touch();
    }

    public function setSource(string $source): void
    {
        $this->source = in_array($source, self::SOURCES, true) ? $source : 'custom';
        $this->touch();
    }

    public function setRefId(?string $refId): void
    {
        $this->refId = self::nullIfEmpty($refId);
        $this->touch();
    }

    public function setCmsPageId(?string $cmsPageId): void
    {
        $this->cmsPageId = self::nullIfEmpty($cmsPageId);
        $this->touch();
    }

    public function setCampaignId(?string $campaignId): void
    {
        $this->campaignId = self::nullIfEmpty($campaignId);
        $this->touch();
    }

    public function setCtaLabel(?string $label): void
    {
        $this->ctaLabel = self::nullIfEmpty($label);
        $this->touch();
    }

    public function setCtaUrl(?string $url): void
    {
        $this->ctaUrl = self::nullIfEmpty($url);
        $this->touch();
    }

    public function setImageUrl(?string $url): void
    {
        $this->imageUrl = self::nullIfEmpty($url);
        $this->touch();
    }

    public function setSortOrder(int $order): void
    {
        $this->sortOrder = $order;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    public static function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9\-_]+/', '_', $key) ?? $key;
        $key = trim($key, '_-');

        return $key !== '' ? $key : 'custom';
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
