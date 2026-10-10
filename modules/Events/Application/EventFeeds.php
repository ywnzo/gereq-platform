<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Support\Str;
use Modules\Vikendo\Application\VikendoClient;
use Modules\Events\Application\Import\FeedDriverFactory;
use Modules\Events\Application\Import\HtmlScrapeMap;
use Modules\Events\Application\Import\VikendoSource;
use Modules\Events\Domain\CatalogEvent;
use Modules\Events\Domain\EventFeed;

final class EventFeeds
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FeedDriverFactory $drivers,
    ) {
    }

    /** @return list<EventFeed> */
    public function all(): array
    {
        $this->ensureDefaults();

        return $this->em->getRepository(EventFeed::class)->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']);
    }

    /** @return list<EventFeed> */
    public function enabled(): array
    {
        $this->ensureDefaults();

        return $this->em->getRepository(EventFeed::class)->findBy(
            ['enabled' => true],
            ['sortOrder' => 'ASC', 'name' => 'ASC'],
        );
    }

    public function find(string $id): ?EventFeed
    {
        $item = $this->em->find(EventFeed::class, $id);

        return $item instanceof EventFeed ? $item : null;
    }

    public function findByKey(string $key): ?EventFeed
    {
        $item = $this->em->getRepository(EventFeed::class)->findOneBy(['key' => Str::slug($key)]);

        return $item instanceof EventFeed ? $item : null;
    }

    /** @return list<string> */
    public function enabledKeys(): array
    {
        return array_map(static fn (EventFeed $f): string => $f->key(), $this->enabled());
    }

    public function driverFactory(): FeedDriverFactory
    {
        return $this->drivers;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): EventFeed
    {
        $feed = new EventFeed(
            (string) ($data['key'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['driver'] ?? 'ical'),
            (string) ($data['url'] ?? ''),
            (string) ($data['region'] ?? CatalogEvent::REGIONS[0]),
        );
        $this->apply($feed, $data);
        $this->assertUniqueKey($feed);
        $this->em->persist($feed);
        $this->em->flush();

        return $feed;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(EventFeed $feed, array $data): EventFeed
    {
        $this->apply($feed, $data);
        $this->assertUniqueKey($feed);
        $this->em->flush();

        return $feed;
    }

    public function delete(EventFeed $feed): void
    {
        $this->em->remove($feed);
        $this->em->flush();
    }

    public function ensureDefaults(): void
    {
        try {
            $count = (int) $this->em->createQueryBuilder()
                ->select('COUNT(f.id)')
                ->from(EventFeed::class, 'f')
                ->getQuery()
                ->getSingleScalarResult();
        } catch (\Throwable) {
            return;
        }
        if ($count === 0) {
            foreach ($this->defaultRows() as $i => $row) {
                $feed = new EventFeed($row['key'], $row['name'], $row['driver'], $row['url'], $row['region']);
                $feed->setOrganizer($row['organizer'] ?? null);
                $feed->setEnabled(true);
                $feed->setSortOrder($i * 10);
                $feed->setConfig($row['config'] ?? null);
                $this->em->persist($feed);
            }
            $this->em->flush();
        }

        $this->ensureVikendoFeed();
    }

    /** Přidá Vikendo feed, pokud chybí (i na existujících instalacích). */
    public function ensureVikendoFeed(): void
    {
        if (!class_exists(VikendoClient::class)) {
            return;
        }
        try {
            if ($this->findByKey('vikendo') instanceof EventFeed) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $row = $this->vikendoDefaultRow();
        $feed = new EventFeed($row['key'], $row['name'], $row['driver'], $row['url'], $row['region']);
        $feed->setOrganizer($row['organizer'] ?? null);
        $feed->setEnabled(true);
        $feed->setSortOrder(5);
        $feed->setConfig($row['config'] ?? null);
        $this->em->persist($feed);
        $this->em->flush();
    }

    /** @return list<array<string, mixed>> */
    public function defaultRows(): array
    {
        return [
            $this->vikendoDefaultRow(),
            [
                'key' => 'domazlice',
                'name' => 'MKS Domažlice (JSON)',
                'driver' => 'tribe_json',
                'url' => 'https://idomazlice.cz/wp-json/tribe/events/v1/events',
                'region' => 'Domažlice',
                'organizer' => 'MKS Domažlice',
                'config' => ['category' => 'mks', 'per_page' => 50],
            ],
            [
                'key' => 'tachov',
                'name' => 'MKS Tachov (HTML)',
                'driver' => 'html_list',
                'url' => 'https://www.mkstc.cz/udalosti.html',
                'region' => 'Tachov',
                'organizer' => 'MKS Tachov',
                'config' => ['max_pages' => 8],
            ],
            [
                'key' => 'klatovy',
                'name' => 'KD Klatovy (městský kalendář)',
                'driver' => 'klatovy_asp',
                'url' => 'https://www.klatovy.cz/klatovy/kalendar-akci.asp',
                'region' => 'Klatovy',
                'organizer' => 'MKS Klatovy',
                'config' => ['venues' => ['KT_KD']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function vikendoDefaultRow(): array
    {
        return [
            'key' => 'vikendo',
            'name' => 'Vikendo.cz (Plzeňský kraj)',
            'driver' => 'vikendo',
            'url' => VikendoClient::DEFAULT_BASE_URL,
            'region' => 'Plzeň-město',
            'organizer' => 'Vikendo',
            'config' => [
                'district_eids' => VikendoSource::DEFAULT_DISTRICT_EIDS,
                'per_page' => 100,
                'max_pages' => 40,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function apply(EventFeed $feed, array $data): void
    {
        if (isset($data['key'])) {
            $feed->setKey((string) $data['key']);
        }
        if (isset($data['name'])) {
            $feed->setName((string) $data['name']);
        }
        if (isset($data['driver'])) {
            $feed->setDriver((string) $data['driver']);
        }
        if (isset($data['url'])) {
            $feed->setUrl((string) $data['url']);
        }
        if (isset($data['region'])) {
            $feed->setRegion((string) $data['region']);
        }
        if (array_key_exists('organizer', $data)) {
            $feed->setOrganizer(isset($data['organizer']) ? (string) $data['organizer'] : null);
        }
        $feed->setEnabled($this->bool($data['enabled'] ?? $feed->enabled()));
        if (isset($data['sort_order']) && is_numeric($data['sort_order'])) {
            $feed->setSortOrder((int) $data['sort_order']);
        }
        $feed->setConfig($this->buildConfig($feed->driver(), $data));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function buildConfig(string $driver, array $data): ?array
    {
        $config = [];
        if ($driver === 'tribe_json') {
            $cat = trim((string) ($data['config_category'] ?? $data['category'] ?? 'mks'));
            if ($cat !== '') {
                $config['category'] = $cat;
            }
            $per = (int) ($data['config_per_page'] ?? $data['per_page'] ?? 50);
            $config['per_page'] = max(1, min(100, $per > 0 ? $per : 50));
        }
        if ($driver === 'html_list') {
            $pages = (int) ($data['config_max_pages'] ?? $data['max_pages'] ?? 8);
            $config['max_pages'] = max(1, min(30, $pages > 0 ? $pages : 8));
            $fetchDetails = $data['config_fetch_details'] ?? $data['fetch_details'] ?? true;
            $config['fetch_details'] = $this->bool($fetchDetails);
            $rawMap = [];
            foreach (array_keys(HtmlScrapeMap::DEFAULTS) as $key) {
                $raw = trim((string) ($data['config_map_' . $key] ?? $data['map_' . $key] ?? ''));
                if ($raw !== '') {
                    $rawMap[$key] = $raw;
                }
            }
            $map = (new HtmlScrapeMap($rawMap))->overrides();
            if ($map !== []) {
                $config['map'] = $map;
            }
        }
        if ($driver === 'klatovy_asp') {
            $venues = trim((string) ($data['config_venues'] ?? $data['venues'] ?? 'KT_KD'));
            $parts = preg_split('/[\s,;]+/', $venues) ?: [];
            $list = array_values(array_filter(array_map('trim', $parts)));
            $config['venues'] = $list !== [] ? $list : ['KT_KD'];
        }
        if ($driver === 'vikendo') {
            $districts = trim((string) ($data['config_district_eids'] ?? $data['district_eids'] ?? ''));
            if ($districts !== '') {
                $parts = preg_split('/[\s,;]+/', $districts) ?: [];
                $list = array_values(array_filter(array_map('trim', $parts)));
                $config['district_eids'] = $list !== [] ? $list : VikendoSource::DEFAULT_DISTRICT_EIDS;
            } else {
                $config['district_eids'] = VikendoSource::DEFAULT_DISTRICT_EIDS;
            }
            $per = (int) ($data['config_per_page'] ?? $data['per_page'] ?? 100);
            $config['per_page'] = max(1, min(100, $per > 0 ? $per : 100));
            $pages = (int) ($data['config_max_pages'] ?? $data['max_pages'] ?? 40);
            $config['max_pages'] = max(1, min(100, $pages > 0 ? $pages : 40));
            $startFrom = trim((string) ($data['config_start_from'] ?? $data['start_from'] ?? ''));
            if ($startFrom !== '') {
                $config['start_from'] = $startFrom;
            }
        }
        // Optional raw JSON override
        if (!empty($data['config_json']) && is_string($data['config_json'])) {
            $decoded = json_decode($data['config_json'], true);
            if (is_array($decoded)) {
                $config = array_merge($config, $decoded);
            }
        }

        return $config !== [] ? $config : null;
    }

    private function assertUniqueKey(EventFeed $feed): void
    {
        $found = $this->em->getRepository(EventFeed::class)->findOneBy(['key' => $feed->key()]);
        if ($found instanceof EventFeed && $found->id() !== $feed->id()) {
            throw new \InvalidArgumentException('events.feeds.error.duplicate_key');
        }
        if ($feed->key() === '' || $feed->name() === '' || $feed->url() === '') {
            throw new \InvalidArgumentException('events.feeds.error.required');
        }
        if (!in_array($feed->driver(), EventFeed::DRIVERS, true)) {
            throw new \InvalidArgumentException('events.feeds.error.bad_driver');
        }
        if (!in_array($feed->region(), CatalogEvent::REGIONS, true)) {
            throw new \InvalidArgumentException('events.feeds.error.bad_region');
        }
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
        }

        return (bool) $value;
    }
}
