<?php

declare(strict_types=1);

namespace Modules\Listings\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Listings\Domain\City;
use Modules\Listings\Domain\Listing;

final class CitiesProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(string $id): ?City
    {
        $city = $this->em->find(City::class, $id);

        return $city instanceof City ? $city : null;
    }

    public function findByName(string $name): ?City
    {
        $city = $this->em->getRepository(City::class)->findOneBy(['name' => $name]);

        return $city instanceof City ? $city : null;
    }

    /** @return list<City> */
    public function allActive(): array
    {
        /** @var list<City> $items */
        $items = $this->em->getRepository(City::class)->findBy(
            ['active' => true],
            ['sortOrder' => 'ASC', 'name' => 'ASC'],
        );

        return $items;
    }

    /** @return list<City> */
    public function all(): array
    {
        /** @var list<City> $items */
        $items = $this->em->getRepository(City::class)->findBy(
            [],
            ['sortOrder' => 'ASC', 'name' => 'ASC'],
        );

        return $items;
    }

    /** @return list<Listing> */
    public function listingsForCity(City $city): array
    {
        /** @var list<Listing> $items */
        $items = $this->em->getRepository(Listing::class)->createQueryBuilder('l')
            ->andWhere('l.city = :city')
            ->setParameter('city', $city->name())
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $items;
    }

    /** @return array<string, int> city name => published listing count */
    public function publishedCounts(): array
    {
        $rows = $this->em->getRepository(Listing::class)->createQueryBuilder('l')
            ->select('l.city AS city, COUNT(l.id) AS cnt')
            ->andWhere('l.status = :status')
            ->andWhere('l.city IS NOT NULL')
            ->andWhere("l.city != ''")
            ->setParameter('status', 'published')
            ->groupBy('l.city')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $name = (string) ($row['city'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[$name] = (int) ($row['cnt'] ?? 0);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function serialize(City $city, ?int $listingCount = null): array
    {
        return [
            'id' => $city->id(),
            'name' => $city->name(),
            'image_url' => $this->publicImageUrl($city->imageUrl()),
            'sort_order' => $city->sortOrder(),
            'active' => $city->active(),
            'listing_count' => $listingCount,
        ];
    }

    private function publicImageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            if (preg_match('#^https?://(?:127\.0\.0\.1|localhost|0\.0\.0\.0|\[::1\])(?::\d+)?(/.*)$#i', $path, $m) === 1) {
                return $m[1];
            }

            return $path;
        }

        return '/' . ltrim($path, '/');
    }
}
