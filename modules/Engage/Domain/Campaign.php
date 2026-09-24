<?php

declare(strict_types=1);

namespace Modules\Engage\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'engage_campaigns')]
#[ORM\UniqueConstraint(name: 'uniq_engage_campaigns_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_engage_campaigns_active', columns: ['is_active'])]
#[FormEntity('engage.campaign.save')]
#[FormGenerator(
    exclude: ['id', 'createdAt', 'updatedAt', 'firstSeenAt', 'lastSeenAt'],
    form: 'engage.campaign.save',
    cols: 1,
)]
class Campaign
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 80)]
    #[FormField(required: true, label: 'engage.field.slug', hint: 'engage.field.slug.hint', order: 10)]
    private string $slug;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'engage.field.name', order: 20)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(label: 'engage.field.notes', type: 'textarea', order: 30)]
    private ?string $notes = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    #[FormField(name: 'active', label: 'engage.field.active', type: 'switch', order: 40)]
    private bool $active = true;

    /** Landing path on the storefront, e.g. / or /nabidky */
    #[ORM\Column(name: 'landing_path', length: 255, nullable: true)]
    #[FormField(label: 'engage.field.landing_path', hint: 'engage.field.landing_path.hint', order: 50)]
    private ?string $landingPath = null;

    #[ORM\Column(name: 'cms_page_id', length: 36, nullable: true)]
    private ?string $cmsPageId = null;

    #[ORM\Column(name: 'cta_label', length: 120, nullable: true)]
    #[FormField(label: 'engage.field.cta_label', order: 60)]
    private ?string $ctaLabel = null;

    #[ORM\Column(name: 'cta_url', length: 2048, nullable: true)]
    #[FormField(label: 'engage.field.cta_url', order: 70)]
    private ?string $ctaUrl = null;

    #[ORM\Column(name: 'utm_source', length: 64, nullable: true)]
    #[FormField(label: 'engage.field.utm_source', order: 80)]
    private ?string $utmSource = null;

    #[ORM\Column(name: 'utm_medium', length: 64, nullable: true)]
    #[FormField(label: 'engage.field.utm_medium', order: 90)]
    private ?string $utmMedium = null;

    #[ORM\Column(name: 'utm_campaign', length: 120, nullable: true)]
    #[FormField(label: 'engage.field.utm_campaign', order: 100)]
    private ?string $utmCampaign = null;

    #[ORM\Column(name: 'og_image_url', length: 2048, nullable: true)]
    private ?string $ogImageUrl = null;

    #[ORM\Column(name: 'first_seen_at', nullable: true)]
    private ?\DateTimeImmutable $firstSeenAt = null;

    #[ORM\Column(name: 'last_seen_at', nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $slug, string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->slug = self::normalizeSlug($slug);
        $this->name = trim($name);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function landingPath(): ?string
    {
        return $this->landingPath;
    }

    public function cmsPageId(): ?string
    {
        return $this->cmsPageId;
    }

    public function ctaLabel(): ?string
    {
        return $this->ctaLabel;
    }

    public function ctaUrl(): ?string
    {
        return $this->ctaUrl;
    }

    public function utmSource(): ?string
    {
        return $this->utmSource;
    }

    public function utmMedium(): ?string
    {
        return $this->utmMedium;
    }

    public function utmCampaign(): ?string
    {
        return $this->utmCampaign;
    }

    public function ogImageUrl(): ?string
    {
        return $this->ogImageUrl;
    }

    public function firstSeenAt(): ?\DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function lastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = self::normalizeSlug($slug);
        $this->touch();
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
        $this->touch();
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = self::nullIfEmpty($notes);
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    public function setLandingPath(?string $path): void
    {
        $path = self::nullIfEmpty($path);
        if ($path !== null && !str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        $this->landingPath = $path;
        $this->touch();
    }

    public function setCmsPageId(?string $cmsPageId): void
    {
        $this->cmsPageId = self::nullIfEmpty($cmsPageId);
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

    public function setUtmSource(?string $value): void
    {
        $this->utmSource = self::nullIfEmpty($value);
        $this->touch();
    }

    public function setUtmMedium(?string $value): void
    {
        $this->utmMedium = self::nullIfEmpty($value);
        $this->touch();
    }

    public function setUtmCampaign(?string $value): void
    {
        $this->utmCampaign = self::nullIfEmpty($value);
        $this->touch();
    }

    public function setOgImageUrl(?string $url): void
    {
        $this->ogImageUrl = self::nullIfEmpty($url);
        $this->touch();
    }

    public function touchSeen(): void
    {
        $now = new \DateTimeImmutable();
        if ($this->firstSeenAt === null) {
            $this->firstSeenAt = $now;
        }
        $this->lastSeenAt = $now;
        $this->touch();
    }

    public static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9\-_+]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-_');

        return $slug !== '' ? $slug : 'campaign';
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
