<?php

declare(strict_types=1);

namespace Modules\Engage\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Module\CapabilityRegistry;
use Gereq\Setting\Settings;
use Modules\Cms\Domain\Page;
use Modules\Engage\Domain\Campaign;
use Modules\Engage\Domain\Spot;

final class EngageProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CapabilityRegistry $capabilities,
        private readonly Settings $settings,
    ) {
    }

    public function findCampaign(string $id): ?Campaign
    {
        $item = $this->em->find(Campaign::class, $id);

        return $item instanceof Campaign ? $item : null;
    }

    public function findCampaignBySlug(string $slug): ?Campaign
    {
        $item = $this->em->getRepository(Campaign::class)->findOneBy([
            'slug' => Campaign::normalizeSlug($slug),
        ]);

        return $item instanceof Campaign ? $item : null;
    }

    public function findSpot(string $id): ?Spot
    {
        $item = $this->em->find(Spot::class, $id);

        return $item instanceof Spot ? $item : null;
    }

    public function findSpotByKey(string $key): ?Spot
    {
        $item = $this->em->getRepository(Spot::class)->findOneBy([
            'placementKey' => Spot::normalizeKey($key),
        ]);

        return $item instanceof Spot ? $item : null;
    }

    /**
     * @return list<array{id: string, label: string, available: bool, route: ?string, hint: string}>
     */
    public function bridges(): array
    {
        return [
            [
                'id' => 'cms',
                'label' => 'engage.bridge.cms',
                'available' => $this->capabilities->has('cms'),
                'route' => 'admin.cms.pages.index',
                'hint' => 'engage.bridge.cms_hint',
            ],
            [
                'id' => 'webplus',
                'label' => 'engage.bridge.webplus',
                'available' => $this->capabilities->has('webplus'),
                'route' => 'admin.webplus.metas.index',
                'hint' => 'engage.bridge.webplus_hint',
            ],
            [
                'id' => 'newsletter',
                'label' => 'engage.bridge.newsletter',
                'available' => $this->capabilities->has('newsletter'),
                'route' => 'admin.newsletter.campaigns.index',
                'hint' => 'engage.bridge.newsletter_hint',
            ],
            [
                'id' => 'emailing',
                'label' => 'engage.bridge.emailing',
                'available' => $this->capabilities->has('emailing'),
                'route' => 'admin.emailing.mailbox',
                'hint' => 'engage.bridge.emailing_hint',
            ],
            [
                'id' => 'listings',
                'label' => 'engage.bridge.listings',
                'available' => $this->capabilities->has('listings'),
                'route' => 'admin.listings.index',
                'hint' => 'engage.bridge.listings_hint',
            ],
            [
                'id' => 'products',
                'label' => 'engage.bridge.products',
                'available' => $this->capabilities->has('products'),
                'route' => null,
                'hint' => 'engage.bridge.products_hint',
            ],
            [
                'id' => 'customers',
                'label' => 'engage.bridge.customers',
                'available' => $this->capabilities->has('contacts') || $this->capabilities->has('customers'),
                'route' => 'admin.customers.index',
                'hint' => 'engage.bridge.customers_hint',
            ],
        ];
    }

    /** @return array{campaigns: int, spots: int, active_campaigns: int, active_spots: int} */
    public function stats(): array
    {
        $campaigns = (int) $this->em->getRepository(Campaign::class)
            ->createQueryBuilder('c')->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
        $activeCampaigns = (int) $this->em->getRepository(Campaign::class)
            ->createQueryBuilder('c')->select('COUNT(c.id)')->where('c.active = true')->getQuery()->getSingleScalarResult();
        $spots = (int) $this->em->getRepository(Spot::class)
            ->createQueryBuilder('s')->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        $activeSpots = (int) $this->em->getRepository(Spot::class)
            ->createQueryBuilder('s')->select('COUNT(s.id)')->where('s.active = true')->getQuery()->getSingleScalarResult();

        return [
            'campaigns' => $campaigns,
            'spots' => $spots,
            'active_campaigns' => $activeCampaigns,
            'active_spots' => $activeSpots,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeCampaign(Campaign $campaign, bool $touch = false): array
    {
        if ($touch) {
            $campaign->touchSeen();
            $this->em->flush();
        }

        $base = rtrim((string) $this->settings->get('webplus.frontend_url', $this->settings->get('webplus.base_url', '')), '/');
        $landing = $campaign->landingPath() ?: '/';
        $trackingPath = $landing . (str_contains($landing, '?') ? '&' : '?') . 'mkind=' . rawurlencode($campaign->slug());

        return [
            'id' => $campaign->id(),
            'slug' => $campaign->slug(),
            'name' => $campaign->name(),
            'notes' => $campaign->notes(),
            'active' => $campaign->isActive(),
            'landing_path' => $campaign->landingPath(),
            'cms_page_id' => $campaign->cmsPageId(),
            'cta' => [
                'label' => $campaign->ctaLabel(),
                'url' => $campaign->ctaUrl(),
            ],
            'utm' => [
                'source' => $campaign->utmSource(),
                'medium' => $campaign->utmMedium(),
                'campaign' => $campaign->utmCampaign() ?: $campaign->slug(),
            ],
            'og_image' => $campaign->ogImageUrl(),
            'tracking' => [
                'param' => 'mkind',
                'path' => $trackingPath,
                'url' => $base !== '' ? $base . $trackingPath : $trackingPath,
            ],
            'first_seen_at' => $campaign->firstSeenAt()?->format(\DateTimeInterface::ATOM),
            'last_seen_at' => $campaign->lastSeenAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $campaign->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeSpot(Spot $spot): array
    {
        $resolvedUrl = $spot->ctaUrl();
        $resolvedTitle = $spot->title();
        $resolvedBody = $spot->body();

        if ($spot->source() === 'cms_page' && $spot->cmsPageId() && class_exists(Page::class)) {
            $page = $this->em->find(Page::class, $spot->cmsPageId());
            if ($page instanceof Page) {
                $resolvedTitle = $resolvedTitle !== '' ? $resolvedTitle : $page->title();
                if ($resolvedUrl === null || $resolvedUrl === '') {
                    $resolvedUrl = '/' . ltrim($page->slug(), '/');
                }
                if (($resolvedBody === null || $resolvedBody === '') && method_exists($page, 'metaDescription')) {
                    $resolvedBody = $page->metaDescription();
                }
            }
        }

        return [
            'id' => $spot->id(),
            'placement_key' => $spot->placementKey(),
            'title' => $resolvedTitle,
            'body' => $resolvedBody,
            'source' => $spot->source(),
            'ref_id' => $spot->refId(),
            'cms_page_id' => $spot->cmsPageId(),
            'campaign_id' => $spot->campaignId(),
            'cta' => [
                'label' => $spot->ctaLabel(),
                'url' => $resolvedUrl,
            ],
            'image' => $spot->imageUrl(),
            'sort_order' => $spot->sortOrder(),
            'active' => $spot->isActive(),
            'updated_at' => $spot->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeSpots(?string $placementKey = null): array
    {
        $qb = $this->em->getRepository(Spot::class)->createQueryBuilder('s')
            ->where('s.active = true')
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('s.title', 'ASC');
        if ($placementKey !== null && $placementKey !== '') {
            $qb->andWhere('s.placementKey = :key')->setParameter('key', Spot::normalizeKey($placementKey));
        }

        /** @var list<Spot> $items */
        $items = $qb->getQuery()->getResult();
        $out = [];
        foreach ($items as $item) {
            $out[] = $this->serializeSpot($item);
        }

        return $out;
    }

    /** @return list<array{id: string, label: string}> */
    public function cmsPages(): array
    {
        if (!$this->capabilities->has('cms') || !class_exists(Page::class)) {
            return [];
        }
        try {
            /** @var list<Page> $pages */
            $pages = $this->em->getRepository(Page::class)->findBy([], ['title' => 'ASC'], 500);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($pages as $page) {
            $out[] = [
                'id' => $page->id(),
                'label' => $page->title() . ' (/' . $page->slug() . ')',
            ];
        }

        return $out;
    }

    /** @return list<array{id: string, label: string}> */
    public function campaignOptions(): array
    {
        /** @var list<Campaign> $items */
        $items = $this->em->getRepository(Campaign::class)->findBy([], ['name' => 'ASC'], 500);
        $out = [];
        foreach ($items as $item) {
            $out[] = [
                'id' => $item->id(),
                'label' => $item->name() . ' (' . $item->slug() . ')',
            ];
        }

        return $out;
    }
}
