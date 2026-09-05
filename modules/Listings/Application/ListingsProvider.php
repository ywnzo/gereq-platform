<?php

declare(strict_types=1);

namespace Modules\Listings\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Http\Router;
use Modules\Listings\Domain\Listing;
use Modules\Listings\Domain\ListingImage;
use Modules\Media\Domain\MediaAsset;

final class ListingsProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Router $router,
    ) {
    }

    public function find(string $id): ?Listing
    {
        return $this->em->find(Listing::class, $id);
    }

    public function findBySlug(string $slug): ?Listing
    {
        return $this->em->getRepository(Listing::class)->findOneBy(['slug' => $slug]);
    }

    /** @return list<ListingImage> */
    public function gallery(string $listingId): array
    {
        /** @var list<ListingImage> $images */
        $images = $this->em->getRepository(ListingImage::class)->findBy(
            ['listingId' => $listingId],
            ['sortOrder' => 'ASC', 'createdAt' => 'ASC'],
        );

        return $images;
    }

    public function imageUrl(ListingImage $image): ?string
    {
        if ($image->mediaAssetId() && class_exists(MediaAsset::class)) {
            $asset = $this->em->find(MediaAsset::class, $image->mediaAssetId());
            if ($asset instanceof MediaAsset) {
                try {
                    return $this->absoluteUrl($this->router->url('media.public.file', ['id' => $asset->id()]));
                } catch (\Throwable) {
                    return $this->absoluteUrl('/media/' . $asset->id() . '/file');
                }
            }
        }
        if ($image->isExternal()) {
            return $this->absoluteUrl($image->externalUrl());
        }

        return null;
    }

    private function absoluteUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        // Externí URL (Unsplash apod.) necháváme; loopback přepíšeme na relativní cestu,
        // aby FE (VITE_API_URL na LAN) nemířilo na 127.0.0.1 klienta.
        if (preg_match('#^https?://#i', $path) === 1) {
            if (preg_match('#^https?://(?:127\.0\.0\.1|localhost|0\.0\.0\.0|\[::1\])(?::\d+)?(/.*)$#i', $path, $m) === 1) {
                return $m[1];
            }

            return $path;
        }

        return '/' . ltrim($path, '/');
    }

    /** @return list<string> */
    public function galleryUrls(Listing $listing): array
    {
        $urls = [];
        foreach ($this->gallery($listing->id()) as $image) {
            $url = $this->imageUrl($image);
            if ($url !== null && $url !== '') {
                $urls[] = $url;
            }
        }
        if ($urls !== []) {
            return $urls;
        }

        $legacy = $listing->images();
        if ($legacy !== []) {
            return $legacy;
        }

        return $listing->coverImageUrl() ? [$listing->coverImageUrl()] : [];
    }

    public function syncCover(Listing $listing): void
    {
        $gallery = $this->gallery($listing->id());
        $primary = null;
        foreach ($gallery as $image) {
            if ($image->isPrimary()) {
                $primary = $image;
                break;
            }
        }
        $primary ??= $gallery[0] ?? null;
        $url = $primary ? $this->imageUrl($primary) : null;
        $listing->setCoverImageUrl($url);
        if ($url !== null) {
            $urls = [];
            foreach ($gallery as $image) {
                $u = $this->imageUrl($image);
                if ($u) {
                    $urls[] = $u;
                }
            }
            $listing->setImages($urls);
        }
    }

    /**
     * @param array{
     *   city?: ?string,
     *   offer_type?: ?string,
     *   property_type?: ?string,
     *   featured?: ?bool,
     *   min_price?: ?float,
     *   max_price?: ?float,
     *   min_rooms?: ?int,
     *   min_area?: ?float,
     *   market_status?: ?string,
     * } $filters
     * @return list<Listing>
     */
    public function published(array $filters = [], int $limit = 50): array
    {
        $qb = $this->em->getRepository(Listing::class)->createQueryBuilder('l')
            ->andWhere('l.status = :status')
            ->setParameter('status', 'published')
            ->orderBy('l.featured', 'DESC')
            ->addOrderBy('l.publishedAt', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)));

        $city = trim((string) ($filters['city'] ?? ''));
        if ($city !== '') {
            $qb->andWhere('LOWER(l.city) LIKE :city OR LOWER(l.cityPart) LIKE :city OR LOWER(l.street) LIKE :city')
                ->setParameter('city', '%' . mb_strtolower($city) . '%');
        }

        $offer = (string) ($filters['offer_type'] ?? '');
        if (in_array($offer, Listing::OFFERS, true)) {
            $qb->andWhere('l.offerType = :offer')->setParameter('offer', $offer);
        }

        $type = (string) ($filters['property_type'] ?? '');
        if (in_array($type, Listing::TYPES, true)) {
            $qb->andWhere('l.propertyType = :ptype')->setParameter('ptype', $type);
        }

        if (($filters['featured'] ?? null) === true) {
            $qb->andWhere('l.featured = true');
        }

        $market = (string) ($filters['market_status'] ?? '');
        if (in_array($market, Listing::MARKET_STATUSES, true)) {
            $qb->andWhere('l.marketStatus = :mstatus')->setParameter('mstatus', $market);
        }

        if (isset($filters['min_price']) && $filters['min_price'] !== null) {
            $qb->andWhere('l.price >= :minp')->setParameter('minp', number_format((float) $filters['min_price'], 2, '.', ''));
        }
        if (isset($filters['max_price']) && $filters['max_price'] !== null) {
            $qb->andWhere('l.price <= :maxp')->setParameter('maxp', number_format((float) $filters['max_price'], 2, '.', ''));
        }
        if (isset($filters['min_rooms']) && $filters['min_rooms'] !== null) {
            $qb->andWhere('l.rooms >= :minr')->setParameter('minr', (int) $filters['min_rooms']);
        }
        if (isset($filters['min_area']) && $filters['min_area'] !== null) {
            $qb->andWhere('l.areaM2 >= :mina')->setParameter('mina', (float) $filters['min_area']);
        }

        /** @var list<Listing> $items */
        $items = $qb->getQuery()->getResult();

        return $items;
    }

    /** @return list<Listing> */
    public function similar(Listing $listing, int $limit = 3): array
    {
        $qb = $this->em->getRepository(Listing::class)->createQueryBuilder('l')
            ->andWhere('l.status = :status')
            ->andWhere('l.id != :id')
            ->setParameter('status', 'published')
            ->setParameter('id', $listing->id())
            ->setMaxResults(max(1, min(12, $limit)));

        $qb->andWhere('l.city = :city OR l.propertyType = :ptype OR l.offerType = :offer')
            ->setParameter('city', $listing->city())
            ->setParameter('ptype', $listing->propertyType())
            ->setParameter('offer', $listing->offerType())
            ->orderBy('l.featured', 'DESC')
            ->addOrderBy('l.publishedAt', 'DESC');

        /** @var list<Listing> $items */
        $items = $qb->getQuery()->getResult();

        return $items;
    }

    /** @return array<string, mixed> */
    public function serialize(Listing $listing, bool $full = false): array
    {
        $images = array_values(array_filter(
            array_map(fn (string $url): ?string => $this->absoluteUrl($url), $this->galleryUrls($listing)),
        ));
        $cover = $this->absoluteUrl($listing->coverImageUrl()) ?? ($images[0] ?? null);

        $data = [
            'id' => $listing->id(),
            'title' => $listing->title(),
            'slug' => $listing->slug(),
            'status' => $listing->status(),
            'market_status' => $listing->marketStatus(),
            'offer_type' => $listing->offerType(),
            'property_type' => $listing->propertyType(),
            'featured' => $listing->featured(),
            'price' => (float) $listing->price(),
            'currency' => $listing->currency(),
            'area_m2' => $listing->areaM2(),
            'rooms' => $listing->rooms(),
            'floor' => $listing->floor(),
            'floors' => $listing->floors(),
            'city' => $listing->city(),
            'city_part' => $listing->cityPart(),
            'cover_image_url' => $cover,
            'amenities' => $listing->amenityLabels(),
            'energy_rating' => $listing->energyRating(),
            'published_at' => $listing->publishedAt()?->format(\DateTimeInterface::ATOM),
        ];

        if ($full) {
            $data['street'] = $listing->street();
            $data['zip'] = $listing->zip();
            $data['address'] = $listing->address();
            $data['latitude'] = $listing->latitude();
            $data['longitude'] = $listing->longitude();
            $data['description'] = $listing->description();
            $data['images'] = $images;
            $data['balcony'] = $listing->balcony();
            $data['terrace'] = $listing->terrace();
            $data['cellar'] = $listing->cellar();
            $data['garage'] = $listing->garage();
            $data['elevator'] = $listing->elevator();
            $data['parking_lots'] = $listing->parkingLots();
            $data['broker'] = null;
            if ($listing->brokerId()) {
                $broker = $this->em->find(\Modules\Listings\Domain\Broker::class, $listing->brokerId());
                if ($broker instanceof \Modules\Listings\Domain\Broker) {
                    $data['broker'] = [
                        'id' => $broker->id(),
                        'name' => $broker->fullName(),
                        'email' => $broker->email(),
                        'phone' => $broker->phone(),
                        'position' => $broker->position(),
                        'photo_url' => $this->absoluteUrl($broker->photoUrl()),
                        'skills' => $broker->skills(),
                        'description' => $broker->description(),
                    ];
                }
            }
            if ($data['broker'] === null && $listing->brokerName()) {
                $data['broker'] = [
                    'id' => null,
                    'name' => $listing->brokerName(),
                    'email' => $listing->brokerEmail(),
                    'phone' => $listing->brokerPhone(),
                    'position' => $listing->brokerPosition(),
                    'photo_url' => $this->absoluteUrl($listing->brokerPhotoUrl()),
                ];
            }
            $data['created_at'] = $listing->createdAt()->format(\DateTimeInterface::ATOM);
            $data['updated_at'] = $listing->updatedAt()->format(\DateTimeInterface::ATOM);
            $data['near_locations'] = (new NearLocationsProvider($this->em))->serializeForListing(
                $listing->id(),
                $listing->latitude(),
                $listing->longitude(),
            );
        }

        return $data;
    }
}
