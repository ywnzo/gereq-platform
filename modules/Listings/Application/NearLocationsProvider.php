<?php

declare(strict_types=1);

namespace Modules\Listings\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Listings\Domain\ListingNearLocation;
use Modules\Listings\Domain\NearLocation;

final class NearLocationsProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(string $id): ?NearLocation
    {
        $item = $this->em->find(NearLocation::class, $id);

        return $item instanceof NearLocation ? $item : null;
    }

    /** @return list<NearLocation> */
    public function allActive(): array
    {
        /** @var list<NearLocation> $items */
        $items = $this->em->getRepository(NearLocation::class)->findBy(
            ['active' => true],
            ['sortOrder' => 'ASC', 'name' => 'ASC'],
        );

        return $items;
    }

    /** @return list<NearLocation> */
    public function all(): array
    {
        /** @var list<NearLocation> $items */
        $items = $this->em->getRepository(NearLocation::class)->findBy(
            [],
            ['sortOrder' => 'ASC', 'name' => 'ASC'],
        );

        return $items;
    }

    /** @return array{value: string, label: string}[] */
    public function options(bool $activeOnly = true, ?array $excludeIds = null): array
    {
        $exclude = array_flip($excludeIds ?? []);
        $out = [];
        foreach ($activeOnly ? $this->allActive() : $this->all() as $item) {
            if (isset($exclude[$item->id()])) {
                continue;
            }
            $label = $item->name();
            if ($item->city()) {
                $label .= ' · ' . $item->city();
            }
            $out[] = ['value' => $item->id(), 'label' => $label];
        }

        return $out;
    }

    /** @return list<ListingNearLocation> */
    public function forListing(string $listingId): array
    {
        /** @var list<ListingNearLocation> $items */
        $items = $this->em->getRepository(ListingNearLocation::class)->findBy(
            ['listingId' => $listingId],
            ['sortOrder' => 'ASC', 'createdAt' => 'ASC'],
        );

        return $items;
    }

    public function findLink(string $listingId, string $linkId): ?ListingNearLocation
    {
        $link = $this->em->find(ListingNearLocation::class, $linkId);
        if (!$link instanceof ListingNearLocation || $link->listingId() !== $listingId) {
            return null;
        }

        return $link;
    }

    /** @return array<string, mixed> */
    public function serialize(NearLocation $item): array
    {
        return [
            'id' => $item->id(),
            'name' => $item->name(),
            'category' => $item->category(),
            'address' => $item->address(),
            'city' => $item->city(),
            'description' => $item->description(),
            'latitude' => $item->latitude(),
            'longitude' => $item->longitude(),
            'active' => $item->active(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function serializeForListing(
        string $listingId,
        ?float $listingLat = null,
        ?float $listingLng = null,
    ): array {
        $out = [];
        foreach ($this->forListing($listingId) as $link) {
            $place = $this->find($link->nearLocationId());
            if ($place === null || !$place->active()) {
                continue;
            }
            $row = $this->serialize($place);
            $distance = $link->distanceM();
            $walk = $link->walkMinutes();
            $auto = false;
            if ($distance === null || $walk === null) {
                $computed = GeoDistance::between(
                    $listingLat,
                    $listingLng,
                    $place->latitude(),
                    $place->longitude(),
                );
                if ($computed !== null) {
                    $distance ??= $computed['distance_m'];
                    $walk ??= $computed['walk_minutes'];
                    $auto = true;
                }
            }
            $row['link_id'] = $link->id();
            $row['distance_m'] = $distance;
            $row['walk_minutes'] = $walk;
            $row['distance_auto'] = $auto;
            $row['sort_order'] = $link->sortOrder();
            $out[] = $row;
        }

        usort($out, static function (array $a, array $b): int {
            $da = $a['distance_m'];
            $db = $b['distance_m'];
            if ($da === null && $db === null) {
                return ((int) $a['sort_order']) <=> ((int) $b['sort_order']);
            }
            if ($da === null) {
                return 1;
            }
            if ($db === null) {
                return -1;
            }

            return ((int) $da) <=> ((int) $db);
        });

        return $out;
    }

    /**
     * Fill empty distance / walk from GPS; leave explicit values untouched.
     *
     * @return bool True when at least one value was set from coordinates
     */
    public function fillDistanceFromCoords(
        ?float $listingLat,
        ?float $listingLng,
        NearLocation $place,
        ListingNearLocation $link,
        ?int $distanceM,
        ?int $walkMinutes,
    ): bool {
        $distance = $distanceM;
        $walk = $walkMinutes;
        $auto = false;

        if ($distance === null || $walk === null) {
            $computed = GeoDistance::between(
                $listingLat,
                $listingLng,
                $place->latitude(),
                $place->longitude(),
            );
            if ($computed !== null) {
                if ($distance === null) {
                    $distance = $computed['distance_m'];
                    $auto = true;
                }
                if ($walk === null) {
                    $walk = $computed['walk_minutes'];
                    $auto = true;
                }
            }
        }

        $link->setDistanceM($distance);
        $link->setWalkMinutes($walk);

        return $auto;
    }
}
