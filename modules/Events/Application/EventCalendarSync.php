<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Event\EventEnvelope;
use Modules\Events\Domain\CatalogEvent;

/**
 * Mirrors KdeAco catalog events into the admin Calendar board when the
 * calendar module is enabled (entity link type: catalog_event).
 */
final class EventCalendarSync
{
    public const LINK_TYPE = 'catalog_event';

    /** @var array<string, string> */
    private const COLOR_MAP = [
        'purple' => 'violet',
        'green' => 'green',
        'orange' => 'amber',
        'pink' => 'red',
        'yellow' => 'amber',
        'red' => 'red',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventCatalog $catalog,
    ) {
    }

    public function available(): bool
    {
        return class_exists(\Modules\Calendar\Domain\CalendarEvent::class)
            && class_exists(\Modules\Calendar\Domain\CalendarEventLink::class);
    }

    public function handleEnvelope(EventEnvelope $envelope): void
    {
        if (!$this->available()) {
            return;
        }

        $type = $envelope->type;
        if (!str_starts_with($type, 'events.')) {
            return;
        }

        $id = (string) ($envelope->resource['id'] ?? $envelope->data['id'] ?? '');
        if ($id === '') {
            return;
        }

        try {
            if (in_array($type, ['events.archived', 'events.rejected', 'events.deleted'], true)) {
                $this->cancel($id);

                return;
            }

            if (!in_array($type, ['events.created', 'events.updated', 'events.published', 'events.submitted'], true)) {
                return;
            }

            $event = $this->catalog->find($id);
            if ($event === null) {
                return;
            }
            $this->sync($event);
        } catch (\Throwable) {
            // best effort — never break the Events admin flow
        }
    }

    public function sync(CatalogEvent $event): void
    {
        if (!$this->available()) {
            return;
        }

        /** @var class-string $linkClass */
        $linkClass = \Modules\Calendar\Domain\CalendarEventLink::class;
        /** @var class-string $calClass */
        $calClass = \Modules\Calendar\Domain\CalendarEvent::class;

        $link = $this->em->getRepository($linkClass)->findOneBy([
            'entityType' => self::LINK_TYPE,
            'entityId' => $event->id(),
        ]);

        $starts = $event->startsAt();
        $ends = $event->endsAt() ?? $starts->modify('+2 hours');
        if ($ends < $starts) {
            $ends = $starts->modify('+2 hours');
        }

        $status = match ($event->status()) {
            'published' => 'confirmed',
            'pending' => 'tentative',
            default => 'cancelled',
        };
        $color = self::COLOR_MAP[$event->color()] ?? 'blue';
        $location = trim($event->place() . ($event->region() !== '' ? ', ' . $event->region() : ''));
        $description = trim($event->description());
        if ($event->externalUrl()) {
            $description = ($description !== '' ? $description . "\n\n" : '') . $event->externalUrl();
        }

        if ($link !== null) {
            $cal = $this->em->find($calClass, $link->eventId());
            if ($cal !== null) {
                $cal->setTitle($event->title());
                $cal->setStartsAt($starts);
                $cal->setEndsAt($ends);
                $cal->setAllDay($this->looksAllDay($starts, $event->endsAt()));
                $cal->setColor($color);
                $cal->setStatus($status);
                $cal->setLocation($location !== '' ? $location : null);
                $cal->setDescription($description !== '' ? $description : null);
                $this->em->flush();

                return;
            }
        }

        if (in_array($event->status(), ['archived', 'rejected'], true)) {
            return;
        }

        $cal = new $calClass($event->title(), $starts, $ends, 'other');
        $cal->setAllDay($this->looksAllDay($starts, $event->endsAt()));
        $cal->setColor($color);
        $cal->setStatus($status);
        $cal->setLocation($location !== '' ? $location : null);
        $cal->setDescription($description !== '' ? $description : null);
        $this->em->persist($cal);
        $this->em->persist(new $linkClass($cal->id(), self::LINK_TYPE, $event->id()));
        $this->em->flush();
    }

    public function cancel(string $catalogEventId): void
    {
        if (!$this->available()) {
            return;
        }

        /** @var class-string $linkClass */
        $linkClass = \Modules\Calendar\Domain\CalendarEventLink::class;
        /** @var class-string $calClass */
        $calClass = \Modules\Calendar\Domain\CalendarEvent::class;

        $link = $this->em->getRepository($linkClass)->findOneBy([
            'entityType' => self::LINK_TYPE,
            'entityId' => $catalogEventId,
        ]);
        if ($link === null) {
            return;
        }

        $cal = $this->em->find($calClass, $link->eventId());
        if ($cal === null) {
            return;
        }
        $cal->setStatus('cancelled');
        $this->em->flush();
    }

    /** One-shot backfill of published / pending catalog events into the calendar. */
    public function syncAll(): int
    {
        if (!$this->available()) {
            return 0;
        }

        $items = $this->em->getRepository(CatalogEvent::class)->createQueryBuilder('e')
            ->where('e.status IN (:st)')
            ->setParameter('st', ['published', 'pending'])
            ->orderBy('e.startsAt', 'ASC')
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($items as $event) {
            if (!$event instanceof CatalogEvent) {
                continue;
            }
            $this->sync($event);
            $count++;
        }

        return $count;
    }

    /**
     * Attach catalog cover + price onto serialized calendar feed rows.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function enrichFeed(array $rows): array
    {
        if ($rows === [] || !$this->available()) {
            return $rows;
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return $rows;
        }

        /** @var class-string $linkClass */
        $linkClass = \Modules\Calendar\Domain\CalendarEventLink::class;
        /** @var list<object> $links */
        $links = $this->em->getRepository($linkClass)->createQueryBuilder('l')
            ->where('l.eventId IN (:ids)')
            ->andWhere('l.entityType = :type')
            ->setParameter('ids', $ids)
            ->setParameter('type', self::LINK_TYPE)
            ->getQuery()
            ->getResult();

        /** @var array<string, string> $catalogByCal */
        $catalogByCal = [];
        foreach ($links as $link) {
            if (!method_exists($link, 'eventId') || !method_exists($link, 'entityId')) {
                continue;
            }
            $catalogByCal[(string) $link->eventId()] = (string) $link->entityId();
        }
        if ($catalogByCal === []) {
            return $rows;
        }

        /** @var list<CatalogEvent> $catalog */
        $catalog = $this->em->getRepository(CatalogEvent::class)->createQueryBuilder('e')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', array_values(array_unique(array_values($catalogByCal))))
            ->getQuery()
            ->getResult();

        /** @var array<string, CatalogEvent> $byId */
        $byId = [];
        foreach ($catalog as $event) {
            $byId[$event->id()] = $event;
        }

        foreach ($rows as $i => $row) {
            $calId = (string) ($row['id'] ?? '');
            $catalogId = $catalogByCal[$calId] ?? null;
            if ($catalogId === null || !isset($byId[$catalogId])) {
                continue;
            }
            $event = $byId[$catalogId];
            $price = trim((string) ($event->price() ?? ''));
            $rows[$i]['catalog_event_id'] = $event->id();
            $rows[$i]['image_url'] = EventMediaLibrary::publicUrl($event->mediaAssetId(), 160);
            $rows[$i]['price'] = $price !== '' ? $price : null;
            $rows[$i]['price_badge'] = $this->priceBadge($price);
            $rows[$i]['catalog_url'] = '/admin/events/' . $event->id();
        }

        return $rows;
    }

    private function priceBadge(string $price): ?string
    {
        if ($price === '') {
            return null;
        }
        if (preg_match('/(\d[\d\s]*)/', $price, $m) === 1) {
            $amount = (int) preg_replace('/\s+/', '', $m[1]);
            if ($amount >= 180 && $amount <= 220) {
                return 'dvojkilo';
            }
        }

        return $price;
    }

    private function looksAllDay(\DateTimeImmutable $starts, ?\DateTimeImmutable $ends): bool
    {
        if ($starts->format('H:i:s') !== '00:00:00') {
            return false;
        }
        if ($ends === null) {
            return true;
        }

        return $ends->format('H:i:s') === '00:00:00' || $ends->format('H:i:s') === '23:59:59';
    }
}
