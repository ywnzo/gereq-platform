<?php

declare(strict_types=1);

namespace Modules\WebPlus\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'webplus_metas')]
#[ORM\UniqueConstraint(name: 'uniq_webplus_metas_path', columns: ['path'])]
#[ORM\Index(name: 'idx_webplus_metas_cms_page', columns: ['cms_page_id'])]
#[ORM\Index(name: 'idx_webplus_metas_active', columns: ['is_active'])]
#[FormEntity('webplus.meta.save')]
#[FormGenerator(
    exclude: ['id', 'jsonLd', 'createdAt', 'updatedAt'],
    form: 'webplus.meta.save',
    cols: 1,
)]
class WebMeta
{
    public const ROBOTS = [
        'index,follow',
        'index,nofollow',
        'noindex,follow',
        'noindex,nofollow',
    ];

    public const OG_TYPES = [
        'website',
        'article',
        'product',
        'profile',
    ];

    public const TWITTER_CARDS = [
        'summary',
        'summary_large_image',
    ];

    public const CHANGEFREQS = [
        'always',
        'hourly',
        'daily',
        'weekly',
        'monthly',
        'yearly',
        'never',
    ];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    /** Public path key, e.g. `/about` or `/` */
    #[ORM\Column(length: 255)]
    #[FormField(required: true, label: 'webplus.field.path', hint: 'webplus.field.path.hint', order: 10, group: 'identity')]
    private string $path;

    #[ORM\Column(name: 'cms_page_id', length: 36, nullable: true)]
    #[FormField(label: 'webplus.field.cms_page_id', hint: 'webplus.field.cms_page_id.hint', order: 20, group: 'identity')]
    private ?string $cmsPageId = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    #[FormField(name: 'active', label: 'webplus.field.active', type: 'switch', order: 30, group: 'identity')]
    private bool $active = true;

    // ── SEO ──────────────────────────────────────────────

    #[ORM\Column(name: 'seo_title', length: 190, nullable: true)]
    #[FormField(label: 'webplus.field.seo_title', order: 40, group: 'seo')]
    private ?string $seoTitle = null;

    #[ORM\Column(name: 'seo_description', type: 'text', nullable: true)]
    #[FormField(label: 'webplus.field.seo_description', type: 'textarea', order: 50, group: 'seo')]
    private ?string $seoDescription = null;

    #[ORM\Column(name: 'canonical_url', length: 2048, nullable: true)]
    #[FormField(label: 'webplus.field.canonical_url', order: 60, group: 'seo')]
    private ?string $canonicalUrl = null;

    #[ORM\Column(length: 64)]
    #[FormField(
        required: true,
        label: 'webplus.field.robots',
        type: 'select',
        choicesFrom: 'ROBOTS',
        order: 70,
        group: 'seo',
    )]
    private string $robots = 'index,follow';

    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(label: 'webplus.field.keywords', type: 'textarea', hint: 'webplus.field.keywords.hint', order: 80, group: 'seo')]
    private ?string $keywords = null;

    // ── GEO (classic + generative) ───────────────────────

    #[ORM\Column(name: 'geo_region', length: 64, nullable: true)]
    #[FormField(label: 'webplus.field.geo_region', hint: 'webplus.field.geo_region.hint', order: 90, group: 'geo')]
    private ?string $geoRegion = null;

    #[ORM\Column(name: 'geo_placename', length: 190, nullable: true)]
    #[FormField(label: 'webplus.field.geo_placename', order: 100, group: 'geo')]
    private ?string $geoPlacename = null;

    #[ORM\Column(name: 'geo_position', length: 64, nullable: true)]
    #[FormField(label: 'webplus.field.geo_position', hint: 'webplus.field.geo_position.hint', order: 110, group: 'geo')]
    private ?string $geoPosition = null;

    /** Citation-friendly summary for generative engines (GEO) */
    #[ORM\Column(name: 'ai_summary', type: 'text', nullable: true)]
    #[FormField(
        label: 'webplus.field.ai_summary',
        type: 'textarea',
        hint: 'webplus.field.ai_summary.hint',
        order: 120,
        group: 'geo',
    )]
    private ?string $aiSummary = null;

    /** @var array<string, mixed>|list<mixed>|null */
    #[ORM\Column(name: 'json_ld', type: 'json', nullable: true)]
    private ?array $jsonLd = null;

    // ── Open Graph ───────────────────────────────────────

    #[ORM\Column(name: 'og_title', length: 190, nullable: true)]
    #[FormField(label: 'webplus.field.og_title', order: 130, group: 'og')]
    private ?string $ogTitle = null;

    #[ORM\Column(name: 'og_description', type: 'text', nullable: true)]
    #[FormField(label: 'webplus.field.og_description', type: 'textarea', order: 140, group: 'og')]
    private ?string $ogDescription = null;

    #[ORM\Column(name: 'og_image_url', length: 2048, nullable: true)]
    private ?string $ogImageUrl = null;

    #[ORM\Column(name: 'og_type', length: 32)]
    #[FormField(
        required: true,
        label: 'webplus.field.og_type',
        type: 'select',
        choicesFrom: 'OG_TYPES',
        order: 160,
        group: 'og',
    )]
    private string $ogType = 'website';

    #[ORM\Column(name: 'og_url', length: 2048, nullable: true)]
    #[FormField(label: 'webplus.field.og_url', order: 170, group: 'og')]
    private ?string $ogUrl = null;

    #[ORM\Column(name: 'og_locale', length: 16, nullable: true)]
    #[FormField(label: 'webplus.field.og_locale', hint: 'webplus.field.og_locale.hint', order: 180, group: 'og')]
    private ?string $ogLocale = null;

    // ── Twitter ──────────────────────────────────────────

    #[ORM\Column(name: 'twitter_card', length: 32)]
    #[FormField(
        required: true,
        label: 'webplus.field.twitter_card',
        type: 'select',
        choicesFrom: 'TWITTER_CARDS',
        order: 190,
        group: 'twitter',
    )]
    private string $twitterCard = 'summary_large_image';

    #[ORM\Column(name: 'twitter_title', length: 190, nullable: true)]
    #[FormField(label: 'webplus.field.twitter_title', order: 200, group: 'twitter')]
    private ?string $twitterTitle = null;

    #[ORM\Column(name: 'twitter_description', type: 'text', nullable: true)]
    #[FormField(label: 'webplus.field.twitter_description', type: 'textarea', order: 210, group: 'twitter')]
    private ?string $twitterDescription = null;

    #[ORM\Column(name: 'twitter_image_url', length: 2048, nullable: true)]
    private ?string $twitterImageUrl = null;

    // ── Sitemap ──────────────────────────────────────────

    #[ORM\Column(name: 'sitemap_include', type: 'boolean')]
    #[FormField(label: 'webplus.field.sitemap_include', type: 'switch', order: 230, group: 'sitemap')]
    private bool $sitemapInclude = true;

    #[ORM\Column(name: 'sitemap_priority', length: 8, nullable: true)]
    #[FormField(label: 'webplus.field.sitemap_priority', hint: 'webplus.field.sitemap_priority.hint', order: 240, group: 'sitemap')]
    private ?string $sitemapPriority = '0.5';

    #[ORM\Column(name: 'sitemap_changefreq', length: 16, nullable: true)]
    #[FormField(
        label: 'webplus.field.sitemap_changefreq',
        type: 'select',
        choicesFrom: 'CHANGEFREQS',
        order: 250,
        group: 'sitemap',
    )]
    private ?string $sitemapChangefreq = 'weekly';

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $path)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->path = self::normalizePath($path);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function cmsPageId(): ?string
    {
        return $this->cmsPageId;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function seoTitle(): ?string
    {
        return $this->seoTitle;
    }

    public function seoDescription(): ?string
    {
        return $this->seoDescription;
    }

    public function canonicalUrl(): ?string
    {
        return $this->canonicalUrl;
    }

    public function robots(): string
    {
        return $this->robots;
    }

    public function keywords(): ?string
    {
        return $this->keywords;
    }

    public function geoRegion(): ?string
    {
        return $this->geoRegion;
    }

    public function geoPlacename(): ?string
    {
        return $this->geoPlacename;
    }

    public function geoPosition(): ?string
    {
        return $this->geoPosition;
    }

    public function aiSummary(): ?string
    {
        return $this->aiSummary;
    }

    /** @return array<string, mixed>|list<mixed>|null */
    public function jsonLd(): ?array
    {
        return $this->jsonLd;
    }

    public function ogTitle(): ?string
    {
        return $this->ogTitle;
    }

    public function ogDescription(): ?string
    {
        return $this->ogDescription;
    }

    public function ogImageUrl(): ?string
    {
        return $this->ogImageUrl;
    }

    public function ogType(): string
    {
        return $this->ogType;
    }

    public function ogUrl(): ?string
    {
        return $this->ogUrl;
    }

    public function ogLocale(): ?string
    {
        return $this->ogLocale;
    }

    public function twitterCard(): string
    {
        return $this->twitterCard;
    }

    public function twitterTitle(): ?string
    {
        return $this->twitterTitle;
    }

    public function twitterDescription(): ?string
    {
        return $this->twitterDescription;
    }

    public function twitterImageUrl(): ?string
    {
        return $this->twitterImageUrl;
    }

    public function sitemapInclude(): bool
    {
        return $this->sitemapInclude;
    }

    public function sitemapPriority(): ?string
    {
        return $this->sitemapPriority;
    }

    public function sitemapChangefreq(): ?string
    {
        return $this->sitemapChangefreq;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setPath(string $path): void
    {
        $this->path = self::normalizePath($path);
        $this->touch();
    }

    public function setCmsPageId(?string $cmsPageId): void
    {
        $trimmed = $cmsPageId !== null ? trim($cmsPageId) : '';
        $this->cmsPageId = $trimmed !== '' ? $trimmed : null;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    public function setSeoTitle(?string $seoTitle): void
    {
        $this->seoTitle = self::nullIfEmpty($seoTitle);
        $this->touch();
    }

    public function setSeoDescription(?string $seoDescription): void
    {
        $this->seoDescription = self::nullIfEmpty($seoDescription);
        $this->touch();
    }

    public function setCanonicalUrl(?string $canonicalUrl): void
    {
        $this->canonicalUrl = self::nullIfEmpty($canonicalUrl);
        $this->touch();
    }

    public function setRobots(string $robots): void
    {
        $robots = strtolower(trim($robots));
        $this->robots = in_array($robots, self::ROBOTS, true) ? $robots : 'index,follow';
        $this->touch();
    }

    public function setKeywords(?string $keywords): void
    {
        $this->keywords = self::nullIfEmpty($keywords);
        $this->touch();
    }

    public function setGeoRegion(?string $geoRegion): void
    {
        $this->geoRegion = self::nullIfEmpty($geoRegion);
        $this->touch();
    }

    public function setGeoPlacename(?string $geoPlacename): void
    {
        $this->geoPlacename = self::nullIfEmpty($geoPlacename);
        $this->touch();
    }

    public function setGeoPosition(?string $geoPosition): void
    {
        $this->geoPosition = self::nullIfEmpty($geoPosition);
        $this->touch();
    }

    public function setAiSummary(?string $aiSummary): void
    {
        $this->aiSummary = self::nullIfEmpty($aiSummary);
        $this->touch();
    }

    /** @param array<string, mixed>|list<mixed>|null $jsonLd */
    public function setJsonLd(?array $jsonLd): void
    {
        $this->jsonLd = $jsonLd === [] ? null : $jsonLd;
        $this->touch();
    }

    public function setOgTitle(?string $ogTitle): void
    {
        $this->ogTitle = self::nullIfEmpty($ogTitle);
        $this->touch();
    }

    public function setOgDescription(?string $ogDescription): void
    {
        $this->ogDescription = self::nullIfEmpty($ogDescription);
        $this->touch();
    }

    public function setOgImageUrl(?string $ogImageUrl): void
    {
        $this->ogImageUrl = self::nullIfEmpty($ogImageUrl);
        $this->touch();
    }

    public function setOgType(string $ogType): void
    {
        $ogType = strtolower(trim($ogType));
        $this->ogType = in_array($ogType, self::OG_TYPES, true) ? $ogType : 'website';
        $this->touch();
    }

    public function setOgUrl(?string $ogUrl): void
    {
        $this->ogUrl = self::nullIfEmpty($ogUrl);
        $this->touch();
    }

    public function setOgLocale(?string $ogLocale): void
    {
        $this->ogLocale = self::nullIfEmpty($ogLocale);
        $this->touch();
    }

    public function setTwitterCard(string $twitterCard): void
    {
        $twitterCard = strtolower(trim($twitterCard));
        $this->twitterCard = in_array($twitterCard, self::TWITTER_CARDS, true) ? $twitterCard : 'summary_large_image';
        $this->touch();
    }

    public function setTwitterTitle(?string $twitterTitle): void
    {
        $this->twitterTitle = self::nullIfEmpty($twitterTitle);
        $this->touch();
    }

    public function setTwitterDescription(?string $twitterDescription): void
    {
        $this->twitterDescription = self::nullIfEmpty($twitterDescription);
        $this->touch();
    }

    public function setTwitterImageUrl(?string $twitterImageUrl): void
    {
        $this->twitterImageUrl = self::nullIfEmpty($twitterImageUrl);
        $this->touch();
    }

    public function setSitemapInclude(bool $sitemapInclude): void
    {
        $this->sitemapInclude = $sitemapInclude;
        $this->touch();
    }

    public function setSitemapPriority(?string $sitemapPriority): void
    {
        $this->sitemapPriority = self::nullIfEmpty($sitemapPriority);
        $this->touch();
    }

    public function setSitemapChangefreq(?string $sitemapChangefreq): void
    {
        $value = $sitemapChangefreq !== null ? strtolower(trim($sitemapChangefreq)) : '';
        if ($value === '' || !in_array($value, self::CHANGEFREQS, true)) {
            $this->sitemapChangefreq = 'weekly';
        } else {
            $this->sitemapChangefreq = $value;
        }
        $this->touch();
    }

    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '/';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        $path = preg_replace('~/+~', '/', $path) ?? $path;

        return rtrim($path, '/') ?: '/';
    }

    public function isPathTemplate(): bool
    {
        return str_contains($this->path, '{') && str_contains($this->path, '}');
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
