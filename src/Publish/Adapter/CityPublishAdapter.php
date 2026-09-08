<?php

declare(strict_types=1);

namespace Gereq\Publish\Adapter;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\ChangeCard;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableAdapterInterface;
use Gereq\Publish\StagingStore;
use Modules\Listings\Domain\City;

final class CityPublishAdapter implements PublishableAdapterInterface
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
            $entry = $this->store->find('listings.cities', $key);
            if ($entry !== null) {
                $out[$key] = $entry->payload()['production_snapshot'] ?? null;
                continue;
            }
            $city = $this->em->find(City::class, $key);
            if ($city instanceof City) {
                $out[$key] = self::capture($city);
            }
        }

        return $out;
    }

    public function apply(ContentStagingEntry $entry): void
    {
        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            $city = $this->em->find(City::class, $entry->resourceKey());
            if ($city instanceof City) {
                $this->em->remove($city);
                $this->em->flush();
            }
        }
    }

    public function describe(ContentStagingEntry $entry): ChangeCard
    {
        $snap = $entry->payload()['production_snapshot'] ?? [];
        $name = is_array($snap) ? (string) ($snap['name'] ?? $entry->label()) : $entry->label();
        if ($name === '') {
            $name = $entry->label();
        }

        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            return new ChangeCard('Smazat: ' . $name);
        }
        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            return new ChangeCard('Nové: ' . $name);
        }

        return new ChangeCard($name);
    }

    /** @return array<string, mixed> */
    public static function capture(City $city): array
    {
        return [
            'id' => $city->id(),
            'name' => $city->name(),
            'image_url' => $city->imageUrl(),
            'active' => $city->active(),
            'sort_order' => $city->sortOrder(),
        ];
    }
}
