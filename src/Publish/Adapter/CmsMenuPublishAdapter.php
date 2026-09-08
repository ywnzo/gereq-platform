<?php

declare(strict_types=1);

namespace Gereq\Publish\Adapter;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\ChangeCard;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableAdapterInterface;
use Gereq\Publish\StagingStore;
use Modules\Cms\Domain\SiteMenu;
use Modules\Cms\Domain\SiteMenuItem;

final class CmsMenuPublishAdapter implements PublishableAdapterInterface
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
            $entry = $this->store->find('cms.menus', $key);
            if ($entry !== null) {
                $out[$key] = $entry->payload()['production_snapshot'] ?? null;
                continue;
            }
            $menu = $this->em->find(SiteMenu::class, $key);
            if ($menu instanceof SiteMenu) {
                $out[$key] = self::capture($this->em, $menu);
            }
        }

        return $out;
    }

    public function apply(ContentStagingEntry $entry): void
    {
        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            $menuId = $entry->resourceKey();
            foreach ($this->em->getRepository(SiteMenuItem::class)->findBy(['menuId' => $menuId]) as $row) {
                $this->em->remove($row);
            }
            $menu = $this->em->find(SiteMenu::class, $menuId);
            if ($menu instanceof SiteMenu) {
                $this->em->remove($menu);
            }
            $this->em->flush();
        }
        // CREATE / UPDATE: working copy already holds desired state.
    }

    public function describe(ContentStagingEntry $entry): ChangeCard
    {
        $snap = $entry->payload()['production_snapshot'] ?? [];
        $name = is_array($snap) ? (string) ($snap['name'] ?? $entry->label()) : $entry->label();
        if ($name === '') {
            $name = $entry->label();
        }
        $location = is_array($snap) ? (string) ($snap['location'] ?? '') : '';

        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            return new ChangeCard('Smazat: ' . $name, $location);
        }
        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            return new ChangeCard('Nové: ' . $name, $location);
        }

        return new ChangeCard($name, $location);
    }

    /** @return array<string, mixed> */
    public static function capture(EntityManagerInterface $em, SiteMenu $menu): array
    {
        /** @var list<SiteMenuItem> $items */
        $items = $em->getRepository(SiteMenuItem::class)->findBy(
            ['menuId' => $menu->id()],
            ['sortOrder' => 'ASC'],
        );

        return [
            'id' => $menu->id(),
            'name' => $menu->name(),
            'location' => $menu->location(),
            'items' => array_map(static fn (SiteMenuItem $i): array => [
                'id' => $i->id(),
                'label' => $i->label(),
                'url' => $i->url(),
                'page_id' => $i->pageId(),
                'parent_id' => $i->parentId(),
                'sort_order' => $i->sortOrder(),
            ], $items),
        ];
    }
}
