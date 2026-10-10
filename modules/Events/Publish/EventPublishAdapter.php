<?php

declare(strict_types=1);

namespace Modules\Events\Publish;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Http\Router;
use Gereq\Publish\ChangeCard;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableAdapterInterface;
use Gereq\Publish\StagingStore;
use Modules\Events\Application\EventGallery;
use Modules\Events\Application\EventMediaLibrary;
use Modules\Events\Domain\CatalogEvent;
use Modules\Events\Domain\EventFavorite;
use Modules\Events\Domain\EventInterest;

final class EventPublishAdapter implements PublishableAdapterInterface
{
    public const CATEGORY = 'events';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StagingStore $store,
        private readonly Router $router,
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
            $entry = $this->store->find(self::CATEGORY, $key);
            if ($entry !== null) {
                $out[$key] = $entry->payload()['production_snapshot'] ?? null;
                continue;
            }
            $event = $this->em->find(CatalogEvent::class, $key);
            if ($event instanceof CatalogEvent) {
                $out[$key] = self::capture($event);
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
            $event = $this->em->find(CatalogEvent::class, $entry->resourceKey());
            if ($event instanceof CatalogEvent && $event->status() !== 'published') {
                $event->publish();
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
        $href = $this->router->url('admin.events.show', ['id' => $entry->resourceKey()]);

        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            return new ChangeCard('Smazat: ' . $title, $slug, $href);
        }
        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            return new ChangeCard('Nové: ' . $title, $slug, $href);
        }

        return new ChangeCard($title, $slug, $href);
    }

    private function hardDelete(string $eventId): void
    {
        foreach ($this->em->getRepository(EventFavorite::class)->findBy(['eventId' => $eventId]) as $row) {
            $this->em->remove($row);
        }
        foreach ($this->em->getRepository(EventInterest::class)->findBy(['eventId' => $eventId]) as $row) {
            $this->em->remove($row);
        }
        $event = $this->em->find(CatalogEvent::class, $eventId);
        if ($event instanceof CatalogEvent) {
            $this->em->remove($event);
        }
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    public static function capture(CatalogEvent $event, bool $hideDates = false): array
    {
        return [
            'id' => $event->id(),
            'slug' => $event->slug(),
            'title' => $event->title(),
            'description' => $event->description(),
            'long_description' => $event->longDescription(),
            'sections' => $event->sections(),
            'gallery' => $event->gallery(),
            'category' => $event->category(),
            'color' => $event->color(),
            'image_key' => $event->imageKey(),
            'media_asset_id' => $event->mediaAssetId(),
            'place' => $event->place(),
            'region' => $event->region(),
            'starts_at' => $event->startsAt()->format(\DateTimeInterface::ATOM),
            'ends_at' => $event->endsAt()?->format(\DateTimeInterface::ATOM),
            'show_starts_at' => $event->showStartsAt(),
            'show_ends_at' => $event->showEndsAt(),
            'time_label' => $event->timeLabel(),
            'hide_dates' => $hideDates,
            'price' => $event->price(),
            'capacity' => $event->capacity(),
            'organizer' => $event->organizer(),
            'external_url' => $event->externalUrl(),
            'status' => $event->status(),
            'spotlight' => $event->spotlight(),
            'created_at' => $event->createdAt()->format(\DateTimeInterface::ATOM),
            'submitted_by_customer_id' => $event->submittedByCustomerId(),
        ];
    }

    /**
     * @param array<string, mixed> $snap
     * @return array<string, mixed>
     */
    public static function publicFields(array $snap): array
    {
        $start = self::parseDate($snap['starts_at'] ?? null) ?? new \DateTimeImmutable();
        $end = self::parseDate($snap['ends_at'] ?? null);
        $hideDates = !empty($snap['hide_dates']);
        $showStart = !$hideDates && array_key_exists('show_starts_at', $snap)
            ? !empty($snap['show_starts_at'])
            : !$hideDates;
        $showEnd = !$hideDates && array_key_exists('show_ends_at', $snap)
            ? !empty($snap['show_ends_at']) && $end !== null
            : (!$hideDates && $end !== null);
        $days = [1 => 'Po', 2 => 'Út', 3 => 'St', 4 => 'Čt', 5 => 'Pá', 6 => 'So', 7 => 'Ne'];
        $date = \Modules\Events\Application\EventCatalog::publicDateLabel($start, $end, $showStart, $showEnd);
        $mediaId = isset($snap['media_asset_id']) ? (string) $snap['media_asset_id'] : '';
        $imageUrl = EventMediaLibrary::publicUrl($mediaId !== '' ? $mediaId : null);
        $gallery = is_array($snap['gallery'] ?? null) ? $snap['gallery'] : [];
        $spotlight = (string) ($snap['spotlight'] ?? 'none');
        $time = trim((string) ($snap['time_label'] ?? ''));
        if ($hideDates || (!$showStart && !$showEnd)) {
            $date = '';
            $time = '';
        } elseif ($time === '') {
            $tmp = new CatalogEvent('x', 'Ostatní', 'x', 'Domažlice', $start);
            $tmp->setEndsAt($end);
            $tmp->setShowStartsAt($showStart);
            $tmp->setShowEndsAt($showEnd);
            $time = \Modules\Events\Application\EventCatalog::scheduleTime($tmp);
        }

        return [
            'id' => (string) ($snap['id'] ?? ''),
            'slug' => (string) ($snap['slug'] ?? ''),
            'title' => (string) ($snap['title'] ?? ''),
            'description' => (string) ($snap['description'] ?? ''),
            'long_description' => $snap['long_description'] ?? null,
            'sections' => is_array($snap['sections'] ?? null) ? $snap['sections'] : null,
            'gallery' => EventGallery::publicItems($gallery),
            'category' => (string) ($snap['category'] ?? ''),
            'color' => (string) ($snap['color'] ?? 'green'),
            'image_key' => (string) ($snap['image_key'] ?? 'other'),
            'media_asset_id' => $mediaId !== '' ? $mediaId : null,
            'image_url' => $imageUrl,
            'image' => $imageUrl ?? (string) ($snap['image_key'] ?? 'other'),
            'place' => (string) ($snap['place'] ?? ''),
            'region' => (string) ($snap['region'] ?? ''),
            'starts_at' => $start->format(\DateTimeInterface::ATOM),
            'ends_at' => $end?->format(\DateTimeInterface::ATOM),
            'show_starts_at' => $showStart,
            'show_ends_at' => $showEnd,
            'date' => $date,
            'day' => $showStart ? ($days[(int) $start->format('N')] ?? null) : null,
            'time' => $time,
            'hide_dates' => $hideDates || (!$showStart && !$showEnd),
            'price' => $snap['price'] ?? null,
            'capacity' => $snap['capacity'] ?? null,
            'organizer' => $snap['organizer'] ?? null,
            'external_url' => $snap['external_url'] ?? null,
            'status' => (string) ($snap['status'] ?? 'published'),
            'spotlight' => $spotlight === 'none' || $spotlight === '' ? null : $spotlight,
            'created_at' => (string) ($snap['created_at'] ?? $start->format(\DateTimeInterface::ATOM)),
        ];
    }

    public static function snapshotIsLive(array $snap, ?\DateTimeImmutable $now = null): bool
    {
        if (($snap['status'] ?? '') !== 'published') {
            return false;
        }
        if (!empty($snap['hide_dates'])) {
            return true;
        }
        $showStart = array_key_exists('show_starts_at', $snap) ? !empty($snap['show_starts_at']) : true;
        $showEnd = array_key_exists('show_ends_at', $snap) ? !empty($snap['show_ends_at']) : true;
        $end = self::parseDate($snap['ends_at'] ?? null);
        // Hidden schedule on the public site = ongoing (clubs, courses) — never treat as elapsed.
        if (!$showStart && !($showEnd && $end !== null)) {
            return true;
        }
        $now ??= new \DateTimeImmutable();
        $until = $end ?? self::parseDate($snap['starts_at'] ?? null);
        if ($until === null) {
            return true;
        }

        return $until >= $now;
    }

    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
