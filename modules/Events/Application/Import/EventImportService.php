<?php

declare(strict_types=1);

namespace Modules\Events\Application\Import;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Support\Str;
use Modules\Events\Application\EventFeeds;
use Modules\Events\Application\EventGallery;
use Modules\Events\Application\EventMediaLibrary;
use Modules\Events\Domain\CatalogEvent;
use Modules\Events\Domain\EventFeed;

final class EventImportService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventFeeds $feeds,
    ) {
    }

    /** @return list<string> */
    public function sourceKeys(): array
    {
        return $this->feeds->enabledKeys();
    }

    /**
     * @return array{
     *   source: string,
     *   name: string,
     *   fetched: int,
     *   created: int,
     *   updated: int,
     *   skipped: int,
     *   archived: int,
     *   errors: list<string>
     * }
     */
    public function import(string $sourceKey, bool $archiveMissing = true, bool $dryRun = false): array
    {
        $feed = $this->feeds->findByKey($sourceKey);
        if (!$feed instanceof EventFeed) {
            throw new \InvalidArgumentException('Neznámý zdroj: ' . $sourceKey);
        }
        $result = [
            'source' => $feed->key(),
            'name' => $feed->name(),
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'archived' => 0,
            'errors' => [],
        ];

        if (!$feed->enabled() && !$dryRun) {
            $result['errors'][] = 'Zdroj je vypnutý — import přeskočen.';

            return $result;
        }

        try {
            $source = $this->feeds->driverFactory()->make($feed);
            $rows = $source->fetch();
        } catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
            if (!$dryRun) {
                $feed->markImportResult(0, 0, 0, $e->getMessage());
                $this->em->flush();
            }

            return $result;
        }

        $result['fetched'] = count($rows);
        $seenIds = [];
        $media = EventMediaLibrary::fromBasePath($this->em, base_path());
        $now = new \DateTimeImmutable();
        $batch = 0;
        $batchSize = 25;

        foreach ($rows as $row) {
            try {
                if (!$this->em->isOpen()) {
                    $result['errors'][] = 'EntityManager je uzavřený — import přerušen.';
                    break;
                }
                $row = EventAvailability::enrich($row);
                $seenIds[] = $row->sourceId;
                $action = $this->upsert($row, $media, $dryRun, $now);
                $result[$action]++;
                if (!$dryRun && ($action === 'created' || $action === 'updated')) {
                    $result['archived'] += $this->archiveCrossSourceClones($row);
                }
                $batch++;
                if (!$dryRun && $batch >= $batchSize) {
                    $this->em->flush();
                    $this->em->clear();
                    $batch = 0;
                    $reloaded = $this->feeds->findByKey($sourceKey);
                    if ($reloaded instanceof EventFeed) {
                        $feed = $reloaded;
                    }
                    $media = EventMediaLibrary::fromBasePath($this->em, base_path());
                }
            } catch (\Throwable $e) {
                $result['errors'][] = ($row->title ?? '?') . ': ' . $e->getMessage();
                $result['skipped']++;
                if (!$this->em->isOpen()) {
                    $result['errors'][] = 'EntityManager uzavřen po chybě — zbytek importu přeskočen.';
                    break;
                }
                try {
                    $this->em->clear();
                } catch (\Throwable) {
                }
                $reloaded = $this->feeds->findByKey($sourceKey);
                if ($reloaded instanceof EventFeed) {
                    $feed = $reloaded;
                }
                $media = EventMediaLibrary::fromBasePath($this->em, base_path());
                $batch = 0;
            }
        }

        if (!$dryRun && $this->em->isOpen()) {
            try {
                $this->em->flush();
                $error = $result['errors'] !== [] ? implode('; ', array_slice($result['errors'], 0, 3)) : null;
                $feed = $this->feeds->findByKey($sourceKey) ?? $feed;
                $feed->markImportResult($result['fetched'], $result['created'], $result['updated'], $error);
                $this->em->flush();
            } catch (\Throwable $e) {
                $result['errors'][] = 'Uložení výsledku importu selhalo: ' . $e->getMessage();
            }
        }

        if ($archiveMissing && !$dryRun && $this->em->isOpen()) {
            try {
                $result['archived'] += $this->archiveMissing($feed->key(), $seenIds);
            } catch (\Throwable $e) {
                $result['errors'][] = 'Archivace chybějících selhala: ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * @param list<string>|null $only
     * @return list<array<string, mixed>>
     */
    public function importMany(?array $only = null, bool $archiveMissing = true, bool $dryRun = false): array
    {
        $keys = $only ?? $this->sourceKeys();
        $out = [];
        foreach ($keys as $key) {
            $out[] = $this->import($key, $archiveMissing, $dryRun);
        }

        return $out;
    }

    private function upsert(
        NormalizedEvent $row,
        EventMediaLibrary $media,
        bool $dryRun,
        \DateTimeImmutable $now,
    ): string {
        $existing = $this->em->getRepository(CatalogEvent::class)->findOneBy([
            'source' => $row->source,
            'sourceId' => $row->sourceId,
        ]);

        if (!$existing instanceof CatalogEvent) {
            if ($dryRun) {
                return 'created';
            }
            $event = new CatalogEvent(
                $row->title,
                $row->category,
                $row->place,
                $row->region,
                $row->startsAt,
            );
            $event->setSource($row->source, $row->sourceId);
            $this->applyRow($event, $row);
            $event->publish();
            $event->markSynced($now);
            $this->em->persist($event);
            $this->ensureUniqueSlug($event);
            if ($row->imageUrl) {
                $media->attachCoverFromUrl($event, $row->imageUrl, 'other');
            }

            return 'created';
        }

        if ($dryRun) {
            return 'updated';
        }

        if ($existing->status() === 'rejected') {
            $existing->markSynced($now);

            return 'skipped';
        }

        $existing->setTitle($row->title);
        $this->applyRow($existing, $row);
        if ($existing->status() === 'archived') {
            if ($existing->endsAtOrStart() >= $now) {
                $existing->publish();
            }
        } elseif ($existing->status() !== 'published') {
            $existing->publish();
        }
        $existing->markSynced($now);
        $this->ensureUniqueSlug($existing);
        if ($row->imageUrl && !$media->coverMatchesUrl($existing, $row->imageUrl)) {
            $media->attachCoverFromUrl($existing, $row->imageUrl, 'other');
        }

        return 'updated';
    }

    private function applyRow(CatalogEvent $event, NormalizedEvent $row): void
    {
        $event->setCategory($row->category);
        $event->setPlace($row->place);
        $event->setRegion($row->region);
        $event->setStartsAt($row->startsAt);
        $event->setEndsAt($row->endsAt);
        $event->setDescription($row->description !== '' ? $row->description : $row->title);
        $event->setLongDescription($row->longDescription);
        $event->setPrice($row->price);
        $event->setOrganizer($row->organizer);
        $event->setExternalUrl($row->externalUrl);
        $event->setTimeLabel($row->timeLabel);
        $event->setColor($row->color);
        if ($row->capacity !== null && $row->capacity !== '') {
            $event->setCapacity($row->capacity);
        }
        if ($row->gallery !== []) {
            $event->setGallery(EventGallery::normalize($row->gallery));
        }
    }

    /**
     * Archive published clones from other import sources (same day + region + title).
     * Keeps Domazlice JSON/iCal twins from both appearing on the public catalog.
     */
    private function archiveCrossSourceClones(NormalizedEvent $row): int
    {
        $norm = self::normalizeTitle($row->title);
        if ($norm === '') {
            return 0;
        }
        $day = $row->startsAt->format('Y-m-d');
        /** @var list<CatalogEvent> $candidates */
        $candidates = $this->em->getRepository(CatalogEvent::class)->createQueryBuilder('e')
            ->where('e.status = :published')
            ->andWhere('e.region = :region')
            ->andWhere('e.source IS NOT NULL')
            ->andWhere('e.source != :source')
            ->andWhere('e.startsAt >= :dayStart')
            ->andWhere('e.startsAt < :dayEnd')
            ->setParameter('published', 'published')
            ->setParameter('region', $row->region)
            ->setParameter('source', $row->source)
            ->setParameter('dayStart', new \DateTimeImmutable($day . ' 00:00:00', $row->startsAt->getTimezone()))
            ->setParameter('dayEnd', new \DateTimeImmutable($day . ' 00:00:00', $row->startsAt->getTimezone())->modify('+1 day'))
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($candidates as $event) {
            if (self::normalizeTitle($event->title()) !== $norm) {
                continue;
            }
            // Same-day double bill (e.g. 16:00 + 19:00) — keep when start times differ by ≥ 2h.
            $delta = abs($event->startsAt()->getTimestamp() - $row->startsAt->getTimestamp());
            if ($delta >= 7200) {
                continue;
            }
            $event->archive();
            $count++;
        }

        return $count;
    }

    private static function normalizeTitle(string $title): string
    {
        $title = mb_strtolower(trim($title));
        $title = preg_replace('/[^\p{L}\p{N}]+/u', '', $title) ?? '';

        return $title;
    }

    /** @param list<string> $seenIds */
    private function archiveMissing(string $sourceKey, array $seenIds): int
    {
        $qb = $this->em->getRepository(CatalogEvent::class)->createQueryBuilder('e')
            ->where('e.source = :source')
            ->andWhere('e.status = :published')
            ->setParameter('source', $sourceKey)
            ->setParameter('published', 'published');
        if ($seenIds !== []) {
            $qb->andWhere('e.sourceId NOT IN (:ids)')->setParameter('ids', $seenIds);
        }
        /** @var list<CatalogEvent> $rows */
        $rows = $qb->getQuery()->getResult();
        $count = 0;
        foreach ($rows as $event) {
            $event->archive();
            $count++;
        }
        if ($count > 0) {
            $this->em->flush();
        }

        return $count;
    }

    private function ensureUniqueSlug(CatalogEvent $event): void
    {
        $base = $event->slug() !== '' ? $event->slug() : Str::slug($event->title());
        if ($base === '') {
            $base = 'akce';
        }
        if ($event->source() && $event->sourceId()) {
            $preferred = Str::slug($event->title() . '-' . $event->source() . '-' . $event->sourceId());
            if ($preferred !== '') {
                $base = $preferred;
            }
        }
        if (mb_strlen($base) > 160) {
            $base = rtrim(mb_substr($base, 0, 160), '-');
        }
        $slug = $base;
        $i = 2;
        while (true) {
            $found = $this->em->getRepository(CatalogEvent::class)->findOneBy(['slug' => $slug]);
            if (!$found instanceof CatalogEvent || $found->id() === $event->id()) {
                break;
            }
            $slug = $base . '-' . $i;
            $i++;
        }
        $event->setSlug($slug);
    }
}
