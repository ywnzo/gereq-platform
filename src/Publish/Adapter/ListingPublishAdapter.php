<?php

declare(strict_types=1);

namespace Gereq\Publish\Adapter;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\ChangeCard;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableAdapterInterface;
use Gereq\Publish\StagingStore;
use Modules\Listings\Domain\Listing;
use Modules\Listings\Domain\ListingImage;
use Modules\Listings\Domain\ListingNearLocation;

final class ListingPublishAdapter implements PublishableAdapterInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StagingStore $store,
    ) {
    }

    public function settingsKeys(): array
    {
        return [];
    }

    public function snapshot(array $resourceKeys): array
    {
        $out = [];
        foreach ($resourceKeys as $key) {
            $entry = $this->store->find('listings', $key);
            if ($entry !== null) {
                $out[$key] = $entry->payload()['production_snapshot'] ?? null;
                continue;
            }
            $listing = $this->em->find(Listing::class, $key);
            if ($listing instanceof Listing) {
                $out[$key] = self::capture($this->em, $listing);
            }
        }

        return $out;
    }

    public function apply(ContentStagingEntry $entry): void
    {
        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            $this->hardDelete($entry->resourceKey());

            return;
        }

        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            $listing = $this->em->find(Listing::class, $entry->resourceKey());
            if ($listing instanceof Listing && $listing->status() !== 'published') {
                $listing->publish();
                $this->em->flush();
            }
        }
    }

    public function describe(ContentStagingEntry $entry): ChangeCard
    {
        $snap = $entry->payload()['production_snapshot'] ?? [];
        $title = is_array($snap) ? (string) ($snap['title'] ?? $entry->label()) : $entry->label();
        if ($title === '') {
            $title = $entry->label();
        }
        $slug = is_array($snap) ? (string) ($snap['slug'] ?? '') : '';

        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            return new ChangeCard('Smazat: ' . $title, $slug);
        }
        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            return new ChangeCard('Nové: ' . $title, $slug);
        }

        return new ChangeCard($title, $slug);
    }

    private function hardDelete(string $listingId): void
    {
        foreach ($this->em->getRepository(ListingImage::class)->findBy(['listingId' => $listingId]) as $image) {
            $this->em->remove($image);
        }
        if (class_exists(ListingNearLocation::class)) {
            foreach ($this->em->getRepository(ListingNearLocation::class)->findBy(['listingId' => $listingId]) as $link) {
                $this->em->remove($link);
            }
        }
        $listing = $this->em->find(Listing::class, $listingId);
        if ($listing instanceof Listing) {
            $this->em->remove($listing);
        }
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    public static function capture(EntityManagerInterface $em, Listing $listing): array
    {
        /** @var list<ListingImage> $images */
        $images = $em->getRepository(ListingImage::class)->findBy(
            ['listingId' => $listing->id()],
            ['sortOrder' => 'ASC'],
        );

        return [
            'id' => $listing->id(),
            'title' => $listing->title(),
            'slug' => $listing->slug(),
            'status' => $listing->status(),
            'market_status' => $listing->marketStatus(),
            'offer_type' => $listing->offerType(),
            'property_type' => $listing->propertyType(),
            'featured' => $listing->featured(),
            'price' => $listing->price(),
            'currency' => $listing->currency(),
            'city' => $listing->city(),
            'description' => method_exists($listing, 'description') ? $listing->description() : null,
            'images' => array_map(static fn (ListingImage $img): array => [
                'id' => $img->id(),
                'media_asset_id' => $img->mediaAssetId(),
                'external_url' => $img->externalUrl(),
                'sort_order' => $img->sortOrder(),
            ], $images),
        ];
    }
}
