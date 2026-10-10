<?php

declare(strict_types=1);

namespace Modules\Events\Publish;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Http\Router;
use Gereq\Publish\ChangeCard;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableAdapterInterface;
use Gereq\Publish\StagingStore;
use Modules\Events\Application\EventMediaLibrary;
use Modules\Events\Domain\EventCategory;

final class EventCategoryPublishAdapter implements PublishableAdapterInterface
{
    public const CATEGORY = 'events.categories';

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
            $item = $this->em->find(EventCategory::class, $key);
            if ($item instanceof EventCategory) {
                $out[$key] = self::capture($item);
            }
        }

        return $out;
    }

    public function apply(ContentStagingEntry $entry): void
    {
        if ($entry->action() !== ContentStagingEntry::ACTION_DELETE) {
            return;
        }
        $item = $this->em->find(EventCategory::class, $entry->resourceKey());
        if ($item instanceof EventCategory) {
            $this->em->remove($item);
            $this->em->flush();
        }
    }

    public function describe(ContentStagingEntry $entry): ChangeCard
    {
        $snap = $entry->payload()['production_snapshot'] ?? [];
        $name = is_array($snap) ? (string) ($snap['name'] ?? $entry->label()) : $entry->label();
        if ($name === '') {
            $name = $entry->label();
        }
        $slug = is_array($snap) ? (string) ($snap['slug'] ?? '') : '';
        $href = $this->router->url('admin.events.categories.edit', ['id' => $entry->resourceKey()]);

        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            return new ChangeCard('Smazat: ' . $name, $slug, $href);
        }
        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            return new ChangeCard('Nové: ' . $name, $slug, $href);
        }

        return new ChangeCard($name, $slug, $href);
    }

    /** @return array<string, mixed> */
    public static function capture(EventCategory $item): array
    {
        return [
            'id' => $item->id(),
            'slug' => $item->slug(),
            'name' => $item->name(),
            'blurb' => $item->blurb(),
            'icon' => $item->icon(),
            'color_hex' => $item->colorHex(),
            'tint' => $item->tint(),
            'image_key' => $item->imageKey(),
            'media_asset_id' => $item->mediaAssetId(),
            'event_color' => $item->eventColor(),
            'kicker' => $item->kicker(),
            'show_on_home' => $item->showOnHome(),
            'assignable' => $item->assignable(),
            'hide_dates' => $item->hideDates(),
            'sort_order' => $item->sortOrder(),
        ];
    }

    /**
     * @param array<string, mixed> $snap
     * @param array<string, string> $photos
     * @return array<string, mixed>
     */
    public static function cardFromSnapshot(array $snap, array $photos = []): array
    {
        $key = (string) ($snap['image_key'] ?? 'other');
        $mediaId = isset($snap['media_asset_id']) ? (string) $snap['media_asset_id'] : '';
        $assignable = (bool) ($snap['assignable'] ?? false);
        $slug = (string) ($snap['slug'] ?? '');

        return [
            'name' => (string) ($snap['name'] ?? ''),
            'image' => $key,
            'image_url' => EventMediaLibrary::publicUrl($mediaId !== '' ? $mediaId : null)
                ?? ($photos[$key] ?? null),
            'icon' => (string) ($snap['icon'] ?? 'more_horiz'),
            'color' => (string) ($snap['color_hex'] ?? '#526877'),
            'tint' => (string) ($snap['tint'] ?? '#f7f9fa'),
            'filter' => $assignable ? $slug : '',
            'blurb' => (string) ($snap['blurb'] ?? ''),
        ];
    }
}
