<?php

declare(strict_types=1);

namespace Modules\Cms\Publish;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\ChangeCard;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableAdapterInterface;
use Gereq\Publish\StagingStore;
use Modules\Cms\Domain\NavLink;

/** Cms nav links (freeze / staged delete / staged create). */
final class CmsLinkPublishAdapter implements PublishableAdapterInterface
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
            $entry = $this->store->find('cms.links', $key);
            if ($entry !== null) {
                $out[$key] = $entry->payload()['production_snapshot'] ?? null;
                continue;
            }
            $link = $this->em->find(NavLink::class, $key);
            if ($link instanceof NavLink) {
                $out[$key] = self::capture($link);
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
            $link = $this->em->find(NavLink::class, $entry->resourceKey());
            if ($link instanceof NavLink && !$link->isPublished()) {
                $link->setStatus('published');
                $this->em->flush();
            }
        }
    }

    public function discard(ContentStagingEntry $entry): void
    {
        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            $this->hardDelete($entry->resourceKey());

            return;
        }

        if ($entry->action() !== ContentStagingEntry::ACTION_UPDATE) {
            return;
        }

        $snap = $entry->payload()['production_snapshot'] ?? null;
        if (!is_array($snap)) {
            return;
        }

        $link = $this->em->find(NavLink::class, $entry->resourceKey());
        if (!$link instanceof NavLink) {
            return;
        }

        $this->restoreFromSnapshot($link, $snap);
    }

    public function restore(string $resourceKey, ?array $snapshot): void
    {
        if ($snapshot === null) {
            $this->hardDelete($resourceKey);

            return;
        }

        $link = $this->em->find(NavLink::class, $resourceKey);
        if (!$link instanceof NavLink) {
            return;
        }

        $this->restoreFromSnapshot($link, $snapshot);
    }

    public function describe(ContentStagingEntry $entry): ChangeCard
    {
        $snap = $entry->payload()['production_snapshot'] ?? [];
        $title = is_array($snap) ? (string) ($snap['title'] ?? $entry->label()) : $entry->label();
        if ($title === '') {
            $title = $entry->label();
        }
        $url = is_array($snap) ? (string) ($snap['url'] ?? '') : '';
        $summary = $url !== '' ? $url : $entry->label();

        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            return new ChangeCard('Smazat: ' . $title, $summary);
        }
        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            return new ChangeCard('Nové: ' . $title, $summary);
        }

        return new ChangeCard($title, $summary);
    }

    /** @param array<string, mixed> $snap */
    private function restoreFromSnapshot(NavLink $link, array $snap): void
    {
        $link->setTitle((string) ($snap['title'] ?? $link->title()));
        $link->setUrl((string) ($snap['url'] ?? $link->url()));
        if (isset($snap['status']) && is_string($snap['status'])) {
            $link->setStatus($snap['status']);
        }
        if (isset($snap['menu_locations']) && is_array($snap['menu_locations'])) {
            $link->setMenuLocations($snap['menu_locations']);
        } elseif (isset($snap['menu_location']) && is_string($snap['menu_location'])) {
            $link->setMenuLocations($snap['menu_location']);
        } else {
            $link->setMenuLocations([]);
        }
        $link->setMenuSortOrder((int) ($snap['menu_sort_order'] ?? 0));
        $this->em->flush();
    }

    private function hardDelete(string $id): void
    {
        $link = $this->em->find(NavLink::class, $id);
        if ($link instanceof NavLink) {
            $this->em->remove($link);
            $this->em->flush();
        }
    }

    /** @return array<string, mixed> */
    public static function capture(NavLink $link): array
    {
        return [
            'id' => $link->id(),
            'title' => $link->title(),
            'url' => $link->url(),
            'status' => $link->status(),
            'menu_locations' => $link->menuLocations(),
            'menu_sort_order' => $link->menuSortOrder(),
            'published_at' => $link->publishedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
