<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Customers\Domain\Customer;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\StagingStore;
use Gereq\Support\Str;
use Modules\Events\Domain\CatalogEvent;
use Modules\Events\Domain\EventFavorite;
use Modules\Events\Domain\EventInterest;
use Modules\Events\Publish\EventPublishAdapter;

final class EventCatalog
{
    /** @var array<string, Customer|null> */
    private array $customerCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventCategories $categories,
        private readonly EventTrust $trust,
        private readonly StagingStore $staging,
    ) {
    }

    public function ensureShowDatesColumns(): void
    {
        try {
            $conn = $this->em->getConnection();
            $table = $conn->createSchemaManager()->introspectTable('kdeaco_events');
            $platform = $conn->getDatabasePlatform();
            $isPg = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;
            if (!$table->hasColumn('show_starts_at')) {
                $conn->executeStatement($isPg
                    ? 'ALTER TABLE kdeaco_events ADD COLUMN show_starts_at BOOLEAN NOT NULL DEFAULT TRUE'
                    : 'ALTER TABLE kdeaco_events ADD COLUMN show_starts_at INTEGER NOT NULL DEFAULT 1');
            }
            if (!$table->hasColumn('show_ends_at')) {
                $conn->executeStatement($isPg
                    ? 'ALTER TABLE kdeaco_events ADD COLUMN show_ends_at BOOLEAN NOT NULL DEFAULT TRUE'
                    : 'ALTER TABLE kdeaco_events ADD COLUMN show_ends_at INTEGER NOT NULL DEFAULT 1');
            }
        } catch (\Throwable) {
            // Tabulka ještě nemusí existovat — file migrace ji doplní.
        }
    }

    /**
     * SchemaTool historically dropped unmapped search_vector while leaving the FTS trigger —
     * INSERT then fails with "record new has no field search_vector".
     */
    public function ensureSearchVector(): void
    {
        try {
            $conn = $this->em->getConnection();
            $platform = $conn->getDatabasePlatform();
            if (!$platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                return;
            }
            $table = $conn->createSchemaManager()->introspectTable('kdeaco_events');
            if (!$table->hasColumn('search_vector')) {
                $conn->executeStatement('ALTER TABLE kdeaco_events ADD COLUMN search_vector tsvector');
            }
            $conn->executeStatement(
                'CREATE OR REPLACE FUNCTION kdeaco_events_search_vector_update() RETURNS trigger AS $$
                BEGIN
                    NEW.search_vector :=
                        setweight(to_tsvector(\'simple\', coalesce(NEW.title, \'\')), \'A\')
                        || setweight(to_tsvector(\'simple\', coalesce(NEW.place, \'\')), \'A\')
                        || setweight(to_tsvector(\'simple\', coalesce(NEW.region, \'\')), \'B\')
                        || setweight(to_tsvector(\'simple\', coalesce(NEW.category, \'\')), \'B\')
                        || setweight(to_tsvector(\'simple\', coalesce(NEW.description, \'\')), \'C\')
                        || setweight(to_tsvector(\'simple\', coalesce(NEW.organizer, \'\')), \'C\');
                    RETURN NEW;
                END
                $$ LANGUAGE plpgsql',
            );
            $conn->executeStatement('DROP TRIGGER IF EXISTS trg_kdeaco_events_search_vector ON kdeaco_events');
            $conn->executeStatement(
                'CREATE TRIGGER trg_kdeaco_events_search_vector
                 BEFORE INSERT OR UPDATE OF title, place, region, category, description, organizer
                 ON kdeaco_events
                 FOR EACH ROW EXECUTE PROCEDURE kdeaco_events_search_vector_update()',
            );
            $conn->executeStatement(
                'CREATE INDEX IF NOT EXISTS idx_kdeaco_events_search_vector ON kdeaco_events USING gin (search_vector)',
            );
        } catch (\Throwable) {
            // FTS optional — LIKE/trigram still works when Postgres bits fail.
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<CatalogEvent>, total: int}
     */
    public function search(
        string $query = '',
        array $filters = [],
        int $page = 1,
        int $perPage = 25,
        string $sort = 'startsAt',
        string $direction = 'asc',
        bool $publishedOnly = true,
    ): array {
        $qb = $this->em->getRepository(CatalogEvent::class)->createQueryBuilder('e');
        if ($publishedOnly) {
            $this->expireElapsed();
            $this->constrainPublicSearch($qb);
        } elseif (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $qb->andWhere('e.status = :st')->setParameter('st', $filters['status']);
        }

        $q = trim($query);
        if ($q !== '') {
            $this->constrainTextSearch($qb, $q, $publishedOnly);
        }

        foreach (['category', 'region', 'spotlight'] as $key) {
            if (!isset($filters[$key]) || !is_string($filters[$key]) || $filters[$key] === '') {
                continue;
            }
            $qb->andWhere('e.' . $key . ' = :' . $key)->setParameter($key, $filters[$key]);
        }

        if (($filters['week'] ?? null) === true || ($filters['week'] ?? '') === '1') {
            $qb->andWhere("e.spotlight = 'week'");
        }
        if (($filters['month'] ?? null) === true || ($filters['month'] ?? '') === '1') {
            $qb->andWhere("e.spotlight = 'month'");
        }

        $dateFilter = trim((string) ($filters['date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter) === 1) {
            $dayStart = new \DateTimeImmutable($dateFilter . ' 00:00:00');
            $dayEnd = new \DateTimeImmutable($dateFilter . ' 23:59:59');
            $qb->andWhere('e.startsAt <= :dayEnd AND COALESCE(e.endsAt, e.startsAt) >= :dayStart')
                ->setParameter('dayStart', $dayStart)
                ->setParameter('dayEnd', $dayEnd);
            $dateless = $this->categories->hideDatesSlugs();
            if ($dateless !== []) {
                $qb->andWhere('e.category NOT IN (:dateFilterDateless)')
                    ->setParameter('dateFilterDateless', $dateless);
            }
        }

        $allowedSort = ['startsAt', 'createdAt', 'title', 'status', 'region', 'category', 'place'];
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'startsAt';
        }
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
        if ($publishedOnly) {
            $qb->addSelect("(CASE WHEN e.spotlight = 'month' THEN 0 WHEN e.spotlight = 'week' THEN 1 ELSE 2 END) AS HIDDEN spotlight_rank")
                ->orderBy('spotlight_rank', 'ASC')
                ->addOrderBy('e.' . $sort, $direction);
        } else {
            $qb->orderBy('e.' . $sort, $direction);
        }

        $total = (int) (clone $qb)
            ->select('COUNT(e.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $items = $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /** Text search across the full table (FTS when available, otherwise LIKE). */
    private function constrainTextSearch(\Doctrine\ORM\QueryBuilder $qb, string $query, bool $publishedOnly): void
    {
        $ids = $this->lookupSearchIds($query, $publishedOnly);
        if ($ids !== null) {
            if ($ids === []) {
                $qb->andWhere('1 = 0');

                return;
            }
            $qb->andWhere('e.id IN (:searchIds)')->setParameter('searchIds', $ids);

            return;
        }

        $qb->andWhere(
            'LOWER(e.title) LIKE :q OR LOWER(e.place) LIKE :q OR LOWER(e.description) LIKE :q'
            . ' OR LOWER(e.region) LIKE :q OR LOWER(e.category) LIKE :q'
            . ' OR (e.organizer IS NOT NULL AND LOWER(e.organizer) LIKE :q)',
        )->setParameter('q', '%' . mb_strtolower($query) . '%');
    }

    /**
     * PostgreSQL path: FTS + trigram-friendly LIKE over the whole table.
     *
     * @return list<string>|null null = use DQL fallback
     */
    private function lookupSearchIds(string $query, bool $publishedOnly): ?array
    {
        $conn = $this->em->getConnection();
        if (!$conn->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            return null;
        }

        $like = '%' . mb_strtolower($query) . '%';
        $statusSql = $publishedOnly ? "status = 'published' AND " : '';
        $likeSql = 'lower(title) LIKE :like'
            . ' OR lower(place) LIKE :like'
            . ' OR lower(description) LIKE :like'
            . ' OR lower(region) LIKE :like'
            . ' OR lower(category) LIKE :like'
            . ' OR lower(coalesce(organizer, \'\')) LIKE :like';

        $attempts = [
            [
                'sql' => $statusSql . '((search_vector @@ plainto_tsquery(\'simple\', :qts)) OR ' . $likeSql . ')',
                'params' => ['qts' => $query, 'like' => $like],
            ],
            [
                'sql' => $statusSql . '(' . $likeSql . ')',
                'params' => ['like' => $like],
            ],
        ];
        foreach ($attempts as $attempt) {
            try {
                /** @var list<string> $ids */
                $ids = $conn->fetchFirstColumn(
                    'SELECT id FROM kdeaco_events WHERE ' . $attempt['sql'],
                    $attempt['params'],
                );

                return array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== ''));
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    public function findBySlug(string $slug): ?CatalogEvent
    {
        $event = $this->em->getRepository(CatalogEvent::class)->findOneBy(['slug' => $slug]);

        return $event instanceof CatalogEvent ? $event : null;
    }

    public function find(string $id): ?CatalogEvent
    {
        $event = $this->em->find(CatalogEvent::class, $id);

        return $event instanceof CatalogEvent ? $event : null;
    }

    /** Drop leftover staging rows — events use approval status only. */
    public function discardStaging(?string $id = null): void
    {
        if ($id !== null) {
            $entry = $this->staging->find(EventPublishAdapter::CATEGORY, $id);
            if ($entry instanceof ContentStagingEntry) {
                $this->staging->remove($entry);
            }

            return;
        }

        try {
            /** @var list<ContentStagingEntry> $stale */
            $stale = $this->em->createQueryBuilder()
                ->select('e')
                ->from(ContentStagingEntry::class, 'e')
                ->where('e.category = :cat')
                ->setParameter('cat', EventPublishAdapter::CATEGORY)
                ->getQuery()
                ->getResult();
        } catch (\Throwable) {
            return;
        }
        if ($stale !== []) {
            $this->staging->removeMany($stale);
        }
    }

    public function delete(CatalogEvent $event): void
    {
        $id = $event->id();
        $this->discardStaging($id);
        foreach ($this->em->getRepository(EventFavorite::class)->findBy(['eventId' => $id]) as $row) {
            $this->em->remove($row);
        }
        foreach ($this->em->getRepository(EventInterest::class)->findBy(['eventId' => $id]) as $row) {
            $this->em->remove($row);
        }
        $this->em->remove($event);
        $this->em->flush();
    }

    public function isLiveOnPublic(CatalogEvent $event, ?\DateTimeImmutable $now = null): bool
    {
        if ($event->status() !== 'published') {
            return false;
        }

        return $this->isEvergreen($event) || $event->isPubliclyVisible($now);
    }

    /** Ongoing / no public schedule — must not expire into archive or drop from the catalog. */
    public function isEvergreen(CatalogEvent $event): bool
    {
        if ($this->categories->hidesDates($event->category())) {
            return true;
        }

        return !$event->showStartsAt() && !($event->showEndsAt() && $event->endsAt() !== null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function submit(array $data, string $customerId): CatalogEvent
    {
        // Heal FTS column before INSERT — SchemaTool can race the trigger otherwise.
        $this->ensureSearchVector();

        $customerId = trim($customerId);
        if ($customerId === '') {
            throw new \InvalidArgumentException('Akci může vložit jen přihlášený uživatel.');
        }
        $event = new CatalogEvent(
            (string) $data['title'],
            (string) $data['category'],
            (string) $data['place'],
            (string) $data['region'],
            $this->startDateFromInput($data),
        );
        $this->apply($event, $data);
        $ends = trim((string) ($data['ends_at'] ?? ''));
        $event->setEndsAt($ends !== '' ? $this->parseDate($ends) : null);
        if (trim((string) ($data['time'] ?? $data['time_label'] ?? '')) === '') {
            $event->setTimeLabel($this->categories->hidesDates($event->category()) ? '' : self::scheduleTime($event));
        }
        if (($data['color'] ?? '') === '') {
            $event->setColor($this->categories->colorFor($event->category()));
        }
        if (($data['image'] ?? $data['image_key'] ?? '') === '') {
            $event->setImageKey($this->categories->imageFor($event->category()));
        }
        $event->setSubmittedByCustomerId($customerId);
        if ($this->trust->isTrusted($customerId)) {
            // Trusted organizers go live immediately — no admin approval step.
            $event->publish();
        } else {
            $event->markPending();
        }
        $this->ensureUniqueSlug($event);
        $this->em->persist($event);
        $this->em->flush();
        $media = EventMediaLibrary::fromBasePath($this->em, base_path());
        if ($event->mediaAssetId() === null) {
            $media->attachCover($event, $event->imageKey());
        } else {
            $media->bindExistingCover($event);
        }
        $this->em->flush();

        return $event;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveAdmin(CatalogEvent $event, array $data): CatalogEvent
    {
        $this->apply($event, $data);
        if (isset($data['title'])) {
            $event->setTitle((string) $data['title']);
        }
        if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
            $event->setSlug((string) $data['slug']);
        }
        if (isset($data['starts_at']) && (string) $data['starts_at'] !== '') {
            $event->setStartsAt($this->parseDate((string) $data['starts_at']));
        }
        if (array_key_exists('ends_at', $data)) {
            $ends = trim((string) ($data['ends_at'] ?? ''));
            $event->setEndsAt($ends !== '' ? $this->parseDate($ends) : null);
        }
        $status = (string) ($data['status'] ?? $event->status());
        if ($status === 'published') {
            $event->publish();
            $this->discardStaging($event->id());
        } elseif ($status === 'archived') {
            $event->archive();
            $this->discardStaging($event->id());
        } elseif ($status === 'rejected') {
            $reason = trim((string) ($data['rejection_reason'] ?? $event->rejectionReason() ?? ''));
            if ($reason !== '') {
                $event->reject($reason);
            }
            $this->discardStaging($event->id());
        } elseif ($status === 'pending') {
            $event->markPending();
            $this->discardStaging($event->id());
        }
        // Unknown / empty status: keep the current one (do not force pending/archive).
        if (isset($data['spotlight'])) {
            $this->assignSpotlight($event, (string) $data['spotlight'], false);
        }
        $this->ensureUniqueSlug($event);
        $this->em->flush();

        return $event;
    }

    public function createAdmin(array $data): CatalogEvent
    {
        $event = new CatalogEvent(
            (string) $data['title'],
            (string) $data['category'],
            (string) $data['place'],
            (string) $data['region'],
            $this->startDateFromInput($data),
        );
        $this->em->persist($event);
        $this->saveAdmin($event, $data);

        return $event;
    }

    /**
     * Owner correction. Goes back to pending until an admin approves again.
     *
     * @param array<string, mixed> $data
     */
    public function reviseOwned(CatalogEvent $event, array $data, string $customerId): CatalogEvent
    {
        $customerId = trim($customerId);
        if ($customerId === '' || $event->submittedByCustomerId() !== $customerId) {
            throw new \InvalidArgumentException('Tuhle akci může upravit jen ten, kdo ji přidal.');
        }
        if ($event->status() === 'archived') {
            throw new \InvalidArgumentException('Archivovanou akci už upravit nejde.');
        }
        $this->discardStaging($event->id());
        $previousRegion = $event->region();
        $previousGallery = $event->gallery();
        $incomingGallery = is_array($data['gallery'] ?? null) ? $data['gallery'] : [];
        unset($data['gallery']);
        $this->apply($event, $data);
        if (isset($data['title'])) {
            $event->setTitle((string) $data['title']);
        }
        $startRaw = trim((string) ($data['starts_at'] ?? ''));
        if ($startRaw !== '') {
            $event->setStartsAt($this->parseDate($startRaw));
        }
        if (array_key_exists('ends_at', $data)) {
            $ends = trim((string) ($data['ends_at'] ?? ''));
            $event->setEndsAt($ends !== '' ? $this->parseDate($ends) : null);
        }
        if (trim((string) ($data['time'] ?? $data['time_label'] ?? '')) === '') {
            $event->setTimeLabel($this->categories->hidesDates($event->category()) ? '' : self::scheduleTime($event));
        }
        if (!empty($data['gallery_sync'])) {
            $keep = is_array($data['gallery_keep'] ?? null) ? $data['gallery_keep'] : [];
            $keepIds = array_map(static fn (mixed $id): string => trim((string) $id), $keep);
            $kept = [];
            foreach ($previousGallery as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = trim((string) ($item['media_asset_id'] ?? ''));
                if ($id !== '' && in_array($id, $keepIds, true)) {
                    $kept[] = $item;
                }
            }
            $event->setGallery(array_slice([...$kept, ...$incomingGallery], 0, 8));
        } elseif ($incomingGallery !== []) {
            $event->setGallery(array_slice([...$previousGallery, ...$incomingGallery], 0, 8));
        }
        if (isset($data['region']) && (string) $data['region'] !== $previousRegion && $event->spotlight() !== 'none') {
            $event->setSpotlight('none');
        }
        $event->markPending();
        $this->ensureUniqueSlug($event);
        $this->em->flush();

        return $event;
    }

    public function toggleFavorite(CatalogEvent $event, string $customerId): bool
    {
        $existing = $this->em->getRepository(EventFavorite::class)->findOneBy([
            'eventId' => $event->id(),
            'customerId' => $customerId,
        ]);
        if ($existing instanceof EventFavorite) {
            $this->em->remove($existing);
            $this->em->flush();

            return false;
        }

        $this->em->persist(new EventFavorite($event->id(), $customerId));
        $this->em->flush();

        return true;
    }

    /** @return list<string> */
    public function favoriteEventIds(string $customerId): array
    {
        $rows = $this->em->getRepository(EventFavorite::class)->findBy(
            ['customerId' => $customerId],
            ['createdAt' => 'DESC'],
        );

        return array_map(static fn (EventFavorite $f): string => $f->eventId(), $rows);
    }

    /**
     * @return list<array{favorite: EventFavorite, customer: Customer|null}>
     */
    public function saversForEvent(string $eventId): array
    {
        $rows = $this->em->getRepository(EventFavorite::class)->findBy(
            ['eventId' => $eventId],
            ['createdAt' => 'DESC'],
        );
        $out = [];
        foreach ($rows as $favorite) {
            $customer = $this->em->find(Customer::class, $favorite->customerId());
            $out[] = [
                'favorite' => $favorite,
                'customer' => $customer instanceof Customer ? $customer : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{favorite: EventFavorite, event: CatalogEvent}>
     */
    public function favoritesForCustomer(string $customerId): array
    {
        $rows = $this->em->getRepository(EventFavorite::class)->findBy(
            ['customerId' => $customerId],
            ['createdAt' => 'DESC'],
        );
        $out = [];
        foreach ($rows as $favorite) {
            $event = $this->find($favorite->eventId());
            if ($event instanceof CatalogEvent) {
                $out[] = [
                    'favorite' => $favorite,
                    'event' => $event,
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<CatalogEvent>
     */
    public function submittedByCustomer(string $customerId): array
    {
        return $this->em->getRepository(CatalogEvent::class)->findBy(
            ['submittedByCustomerId' => $customerId],
            ['createdAt' => 'DESC'],
        );
    }

    /**
     * @return list<array{interest: EventInterest, event: CatalogEvent}>
     */
    public function interestsForCustomer(string $customerId): array
    {
        $rows = $this->em->getRepository(EventInterest::class)->findBy(
            ['customerId' => $customerId],
            ['createdAt' => 'DESC'],
        );
        $out = [];
        foreach ($rows as $interest) {
            $event = $this->find($interest->eventId());
            if ($event instanceof CatalogEvent) {
                $out[] = [
                    'interest' => $interest,
                    'event' => $event,
                ];
            }
        }

        return $out;
    }

    /**
     * @param list<string> $eventIds
     * @return array<string, int>
     */
    public function favoriteCounts(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $map = array_fill_keys($eventIds, 0);
        $rows = $this->em->createQueryBuilder()
            ->select('f.eventId AS eventId, COUNT(f.id) AS cnt')
            ->from(EventFavorite::class, 'f')
            ->where('f.eventId IN (:ids)')
            ->setParameter('ids', $eventIds)
            ->groupBy('f.eventId')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $map[(string) $row['eventId']] = (int) $row['cnt'];
        }

        return $map;
    }

    public function addInterest(CatalogEvent $event, string $customerId): bool
    {
        if ($this->isInterested($event->id(), $customerId)) {
            return false;
        }
        $this->em->persist(new EventInterest($event->id(), $customerId));
        $this->em->flush();

        return true;
    }

    public function isInterested(string $eventId, string $customerId): bool
    {
        return $this->em->getRepository(EventInterest::class)->findOneBy([
            'eventId' => $eventId,
            'customerId' => $customerId,
        ]) instanceof EventInterest;
    }

    public function interestCount(string $eventId): int
    {
        return (int) $this->em->getRepository(EventInterest::class)->count(['eventId' => $eventId]);
    }

    /**
     * @return list<array{interest: EventInterest, customer: Customer|null}>
     */
    public function interestsForEvent(string $eventId): array
    {
        $rows = $this->em->getRepository(EventInterest::class)->findBy(
            ['eventId' => $eventId],
            ['createdAt' => 'DESC'],
        );
        $out = [];
        foreach ($rows as $interest) {
            $customer = $this->em->find(Customer::class, $interest->customerId());
            $out[] = [
                'interest' => $interest,
                'customer' => $customer instanceof Customer ? $customer : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $eventIds
     * @return array<string, int>
     */
    public function interestCounts(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $map = array_fill_keys($eventIds, 0);
        $rows = $this->em->createQueryBuilder()
            ->select('i.eventId AS eventId, COUNT(i.id) AS cnt')
            ->from(EventInterest::class, 'i')
            ->where('i.eventId IN (:ids)')
            ->setParameter('ids', $eventIds)
            ->groupBy('i.eventId')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $map[(string) $row['eventId']] = (int) $row['cnt'];
        }

        return $map;
    }

    public function isFavorite(string $eventId, string $customerId): bool
    {
        return $this->em->getRepository(EventFavorite::class)->findOneBy([
            'eventId' => $eventId,
            'customerId' => $customerId,
        ]) instanceof EventFavorite;
    }

    public function purgeCustomer(string $customerId): void
    {
        $this->em->createQueryBuilder()
            ->delete(EventFavorite::class, 'f')
            ->where('f.customerId = :id')
            ->setParameter('id', $customerId)
            ->getQuery()
            ->execute();
        $this->em->createQueryBuilder()
            ->delete(EventInterest::class, 'i')
            ->where('i.customerId = :id')
            ->setParameter('id', $customerId)
            ->getQuery()
            ->execute();
        $events = $this->em->getRepository(CatalogEvent::class)->findBy(['submittedByCustomerId' => $customerId]);
        foreach ($events as $event) {
            $event->setSubmittedByCustomerId(null);
        }
        $this->trust->forget($customerId);
        $this->em->flush();
    }

    public function assignSpotlight(CatalogEvent $event, string $spotlight, bool $flush = true): void
    {
        if (!in_array($spotlight, CatalogEvent::SPOTLIGHTS, true)) {
            $spotlight = 'none';
        }
        if ($spotlight !== 'none') {
            // Up to 3 week picks and 1 month pick per district, not for the whole region.
            $limit = $spotlight === 'week' ? 3 : 1;
            $others = $this->em->getRepository(CatalogEvent::class)->findBy([
                'spotlight' => $spotlight,
                'region' => $event->region(),
            ]);
            $peers = [];
            foreach ($others as $other) {
                if ($other instanceof CatalogEvent && $other->id() !== $event->id()) {
                    $peers[] = $other;
                }
            }
            usort(
                $peers,
                static fn (CatalogEvent $a, CatalogEvent $b): int => $a->updatedAt() <=> $b->updatedAt(),
            );
            $overflow = count($peers) - ($limit - 1);
            if ($overflow > 0) {
                foreach (array_slice($peers, 0, $overflow) as $other) {
                    $other->setSpotlight('none');
                }
            }
        }
        $event->setSpotlight($spotlight);
        if ($flush) {
            $this->em->flush();
        }
    }

    public function expireElapsed(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        try {
            return (int) $this->em->createQuery(
                'UPDATE Modules\Events\Domain\CatalogEvent e
                 SET e.status = :archived, e.archivedAt = :now, e.updatedAt = :now, e.spotlight = :none
                 WHERE e.status = :published
                   AND COALESCE(e.endsAt, e.startsAt) < :now
                   AND e.category NOT IN (:dateless)
                   AND NOT (e.showStartsAt = false AND (e.showEndsAt = false OR e.endsAt IS NULL))',
            )
                ->setParameter('archived', 'archived')
                ->setParameter('none', 'none')
                ->setParameter('published', 'published')
                ->setParameter('now', $now)
                ->setParameter('dateless', $this->datelessCategoryParam())
                ->execute();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function seedIfEmpty(): int
    {
        try {
            $dir = rtrim(base_path(), '/') . '/storage/cache';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return 0;
            }
            $version = 'v' . count((new EventDemoSeeder($this->em))->demoRows()) . '-districts';
            $flag = $dir . '/kdeaco-events-seed.' . $version;
            if (is_file($flag)) {
                return 0;
            }
            $handle = @fopen($dir . '/kdeaco-events-seed.lock', 'c');
            if ($handle === false) {
                return 0;
            }
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);

                return 0;
            }
            try {
                if (is_file($flag)) {
                    return 0;
                }
                $this->media()->urls();
                $n = (new EventDemoSeeder($this->em, $this->media()))->runMissing();
                @file_put_contents($flag, (string) time());

                return $n;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<string, string> */
    public function mediaUrls(): array
    {
        return $this->media()->urls();
    }

    private function media(): EventMediaLibrary
    {
        return EventMediaLibrary::fromBasePath($this->em, base_path());
    }

    /**
     * @param list<string|null> $ids
     * @return array<string, Customer>
     */
    public function customersByIds(array $ids): array
    {
        $want = [];
        foreach ($ids as $id) {
            if (is_string($id) && $id !== '') {
                $want[$id] = $id;
            }
        }
        $missing = [];
        foreach ($want as $id) {
            if (!array_key_exists($id, $this->customerCache)) {
                $missing[] = $id;
            }
        }
        if ($missing !== []) {
            $rows = $this->em->createQueryBuilder()
                ->select('c')
                ->from(Customer::class, 'c')
                ->where('c.id IN (:ids)')
                ->setParameter('ids', $missing)
                ->getQuery()
                ->getResult();
            foreach ($missing as $id) {
                $this->customerCache[$id] = null;
            }
            foreach ($rows as $customer) {
                if ($customer instanceof Customer) {
                    $this->customerCache[$customer->id()] = $customer;
                }
            }
        }
        $out = [];
        foreach ($want as $id) {
            $customer = $this->customerCache[$id] ?? null;
            if ($customer instanceof Customer) {
                $out[$id] = $customer;
            }
        }

        return $out;
    }

    public function customerById(?string $id): ?Customer
    {
        if ($id === null || $id === '') {
            return null;
        }
        $found = $this->customersByIds([$id]);

        return $found[$id] ?? null;
    }

    /**
     * @param list<CatalogEvent> $events
     * @return list<array<string, mixed>>
     */
    public function serializeMany(array $events, ?string $customerId = null, bool $forOwner = false): array
    {
        if ($events === []) {
            return [];
        }
        $ids = array_map(static fn (CatalogEvent $event): string => $event->id(), $events);
        $interestCounts = $this->interestCounts($ids);
        $favoriteIds = $customerId !== null ? $this->favoriteIdsAmong($customerId, $ids) : [];
        $interestedIds = $customerId !== null ? $this->interestedIdsAmong($customerId, $ids) : [];

        return array_map(
            fn (CatalogEvent $event): array => $this->serialize(
                $event,
                $customerId,
                $forOwner,
                [
                    'interest_count' => $interestCounts[$event->id()] ?? 0,
                    'favorite' => isset($favoriteIds[$event->id()]),
                    'interested' => isset($interestedIds[$event->id()]),
                ],
            ),
            $events,
        );
    }

    /**
     * @param list<string> $eventIds
     * @return array<string, true>
     */
    public function favoriteIdsAmong(string $customerId, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $rows = $this->em->createQueryBuilder()
            ->select('f.eventId')
            ->from(EventFavorite::class, 'f')
            ->where('f.customerId = :cid')
            ->andWhere('f.eventId IN (:ids)')
            ->setParameter('cid', $customerId)
            ->setParameter('ids', $eventIds)
            ->getQuery()
            ->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['eventId']] = true;
        }

        return $out;
    }

    /**
     * @param list<string> $eventIds
     * @return array<string, true>
     */
    public function interestedIdsAmong(string $customerId, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $rows = $this->em->createQueryBuilder()
            ->select('i.eventId')
            ->from(EventInterest::class, 'i')
            ->where('i.customerId = :cid')
            ->andWhere('i.eventId IN (:ids)')
            ->setParameter('cid', $customerId)
            ->setParameter('ids', $eventIds)
            ->getQuery()
            ->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['eventId']] = true;
        }

        return $out;
    }

    /**
     * @param array{interest_count?: int, favorite?: bool, interested?: bool}|null $precomputed
     * @return array<string, mixed>
     */
    public function serialize(CatalogEvent $event, ?string $customerId = null, bool $forOwner = false, ?array $precomputed = null): array
    {
        $days = [1 => 'Po', 2 => 'Út', 3 => 'St', 4 => 'Čt', 5 => 'Pá', 6 => 'So', 7 => 'Ne'];
        $start = $event->startsAt();
        $end = $event->endsAt();
        $showStart = $event->showStartsAt();
        $showEnd = $event->showEndsAt() && $end !== null;
        $date = self::publicDateLabel($start, $end, $showStart, $showEnd);
        $payload = [
            'id' => $event->id(),
            'slug' => $event->slug(),
            'title' => $event->title(),
            'description' => $event->description(),
            'long_description' => $event->longDescription(),
            'sections' => $event->sections(),
            'gallery' => EventGallery::publicItems($event->gallery()),
            'category' => $event->category(),
            'kicker' => $this->categories->kickerFor($event->category()),
            'color' => $event->color(),
            'image_key' => $event->imageKey(),
            'media_asset_id' => $event->mediaAssetId(),
            'image_url' => EventMediaLibrary::publicUrl($event->mediaAssetId()),
            'image' => EventMediaLibrary::publicUrl($event->mediaAssetId()) ?? $event->imageKey(),
            'place' => $event->place(),
            'region' => $event->region(),
            'starts_at' => $start->format(\DateTimeInterface::ATOM),
            'ends_at' => $end?->format(\DateTimeInterface::ATOM),
            'show_starts_at' => $showStart,
            'show_ends_at' => $showEnd,
            'date' => $date,
            'day' => $showStart ? ($days[(int) $start->format('N')] ?? null) : null,
            'time' => self::scheduleTime($event),
            'hide_dates' => false,
            'price' => $event->price(),
            'capacity' => $event->capacity(),
            'organizer' => $event->organizer(),
            'external_url' => $event->externalUrl(),
            'status' => $event->status(),
            'rejection_reason' => $event->rejectionReason(),
            'rejected_at' => $event->rejectedAt()?->format(\DateTimeInterface::ATOM),
            'spotlight' => $event->spotlight() === 'none' ? null : $event->spotlight(),
            'interest_count' => $precomputed['interest_count'] ?? $this->interestCount($event->id()),
            'created_at' => $event->createdAt()->format(\DateTimeInterface::ATOM),
            'submitted_by' => null,
        ];
        if ($customerId !== null) {
            $payload['favorite'] = array_key_exists('favorite', $precomputed ?? [])
                ? (bool) $precomputed['favorite']
                : $this->isFavorite($event->id(), $customerId);
            $payload['interested'] = array_key_exists('interested', $precomputed ?? [])
                ? (bool) $precomputed['interested']
                : $this->isInterested($event->id(), $customerId);
        }
        $category = (string) ($payload['category'] ?? $event->category());
        $showStart = !empty($payload['show_starts_at']);
        $showEnd = !empty($payload['show_ends_at']);
        if ($this->categories->hidesDatesPublic($category) || !empty($payload['hide_dates'])) {
            $payload['date'] = '';
            $payload['day'] = null;
            $payload['time'] = '';
            $payload['hide_dates'] = true;
            $payload['show_starts_at'] = false;
            $payload['show_ends_at'] = false;
        } elseif (!$showStart && !$showEnd) {
            $payload['date'] = '';
            $payload['day'] = null;
            $payload['time'] = '';
            $payload['hide_dates'] = true;
        }

        return $payload;
    }

    /** @param array<string, mixed> $data */
    private function apply(CatalogEvent $event, array $data): void
    {
        if (isset($data['description'])) {
            $event->setDescription((string) $data['description']);
        }
        if (array_key_exists('long_description', $data)) {
            $event->setLongDescription(isset($data['long_description']) ? (string) $data['long_description'] : null);
        }
        if (isset($data['category'])) {
            $event->setCategory((string) $data['category']);
        }
        if (isset($data['color'])) {
            $event->setColor((string) $data['color']);
        }
        if (isset($data['image']) || isset($data['image_key'])) {
            $event->setImageKey((string) ($data['image'] ?? $data['image_key']));
        }
        if (array_key_exists('media_asset_id', $data)) {
            $event->setMediaAssetId(isset($data['media_asset_id']) ? (string) $data['media_asset_id'] : null);
        }
        if (isset($data['place'])) {
            $event->setPlace((string) $data['place']);
        }
        if (isset($data['region'])) {
            $event->setRegion((string) $data['region']);
        }
        if (isset($data['time']) || isset($data['time_label'])) {
            $event->setTimeLabel((string) ($data['time'] ?? $data['time_label'] ?? ''));
        }
        if (array_key_exists('price', $data)) {
            $event->setPrice(isset($data['price']) ? (string) $data['price'] : null);
        }
        if (array_key_exists('capacity', $data)) {
            $event->setCapacity(isset($data['capacity']) ? (string) $data['capacity'] : null);
        }
        if (array_key_exists('organizer', $data)) {
            $event->setOrganizer(isset($data['organizer']) ? (string) $data['organizer'] : null);
        }
        if (array_key_exists('external_url', $data)) {
            $event->setExternalUrl(isset($data['external_url']) ? (string) $data['external_url'] : null);
        }
        if (array_key_exists('sections', $data) && is_array($data['sections'])) {
            $event->setSections($data['sections']);
        }
        if (array_key_exists('gallery', $data) && is_array($data['gallery'])) {
            $event->setGallery(EventGallery::normalize($data['gallery']));
        }
        if (array_key_exists('show_starts_at', $data) || array_key_exists('show_ends_at', $data)) {
            if (array_key_exists('show_starts_at', $data)) {
                $event->setShowStartsAt(self::truthy($data['show_starts_at']));
            }
            if (array_key_exists('show_ends_at', $data)) {
                $event->setShowEndsAt(self::truthy($data['show_ends_at']));
            }
        }
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        $raw = strtolower(trim((string) $value));

        return in_array($raw, ['1', 'true', 'on', 'yes'], true);
    }

    private function constrainPublicSearch(\Doctrine\ORM\QueryBuilder $qb): void
    {
        $now = new \DateTimeImmutable();
        $dateless = $this->categories->hideDatesSlugs();
        // Evergreen: hide_dates category, or start/end hidden on the public site.
        $evergreen = '(e.showStartsAt = false AND (e.showEndsAt = false OR e.endsAt IS NULL))';
        $live = $dateless === []
            ? 'e.status = :pub AND (' . $evergreen . ' OR COALESCE(e.endsAt, e.startsAt) >= :liveAt)'
            : 'e.status = :pub AND (e.category IN (:dateless) OR ' . $evergreen . ' OR COALESCE(e.endsAt, e.startsAt) >= :liveAt)';
        if ($dateless !== []) {
            $qb->setParameter('dateless', $dateless);
        }
        $qb->andWhere($live);
        $qb->setParameter('pub', 'published')->setParameter('liveAt', $now);
    }

    private function ensureUniqueSlug(CatalogEvent $event): void
    {
        $base = $event->slug() !== '' ? $event->slug() : Str::slug($event->title());
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

    public static function scheduleTime(CatalogEvent $event): string
    {
        if (!$event->showStartsAt() && !($event->showEndsAt() && $event->endsAt() !== null)) {
            return '';
        }
        if ($event->timeLabel() !== '') {
            return $event->timeLabel();
        }
        $start = $event->startsAt();
        $end = $event->endsAt();
        $showStart = $event->showStartsAt();
        $showEnd = $event->showEndsAt() && $end !== null;
        if ($showStart && !$showEnd) {
            return $start->format('H:i');
        }
        if (!$showStart && $showEnd) {
            return $end->format('H:i');
        }
        if ($end === null) {
            return $start->format('H:i');
        }
        if ($end->format('Y-m-d') === $start->format('Y-m-d')) {
            return $start->format('H:i') . '–' . $end->format('H:i');
        }

        return $start->format('j. n. H:i') . ' – ' . $end->format('j. n. H:i');
    }

    public static function publicDateLabel(
        \DateTimeImmutable $start,
        ?\DateTimeImmutable $end,
        bool $showStart,
        bool $showEnd,
    ): string {
        if ($showStart && $showEnd && $end !== null && $end->format('Y-m-d') !== $start->format('Y-m-d')) {
            return $start->format('j. n. Y') . ' – ' . $end->format('j. n. Y');
        }
        if ($showStart) {
            return $start->format('j. n. Y');
        }
        if ($showEnd && $end !== null) {
            return $end->format('j. n. Y');
        }

        return '';
    }

    public static function tryParseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach ([\DateTimeInterface::ATOM, 'Y-m-d\\TH:i', 'Y-m-d H:i', 'Y-m-d', 'j. n. Y H:i', 'j. n. Y'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp($ts);
    }

    private function parseDate(string $value): \DateTimeImmutable
    {
        $dt = self::tryParseDate($value);
        if ($dt instanceof \DateTimeImmutable) {
            return $dt;
        }

        throw new \InvalidArgumentException('Neplatné datum: ' . $value);
    }

    /** @param array<string, mixed> $data */
    private function startDateFromInput(array $data): \DateTimeImmutable
    {
        $raw = trim((string) ($data['starts_at'] ?? ''));
        if ($raw !== '') {
            return $this->parseDate($raw);
        }

        return new \DateTimeImmutable();
    }

    /** @return list<string> */
    private function datelessCategoryParam(): array
    {
        $slugs = $this->categories->hideDatesSlugs();

        return $slugs !== [] ? $slugs : ['__none__'];
    }
}
