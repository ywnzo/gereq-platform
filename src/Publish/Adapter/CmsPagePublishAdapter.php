<?php

declare(strict_types=1);

namespace Gereq\Publish\Adapter;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\ChangeCard;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableAdapterInterface;
use Gereq\Publish\StagingStore;
use Modules\Cms\Domain\Page;
use Modules\Cms\Domain\PageBlock;

/**
 * CMS pages + blocks (freeze / staged delete / staged create).
 */
final class CmsPagePublishAdapter implements PublishableAdapterInterface
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
            $entry = $this->store->find('cms.pages', $key);
            if ($entry !== null) {
                $out[$key] = $entry->payload()['production_snapshot'] ?? null;
                continue;
            }
            $page = $this->em->find(Page::class, $key);
            if ($page instanceof Page) {
                $out[$key] = self::capture($this->em, $page);
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
            $page = $this->em->find(Page::class, $entry->resourceKey());
            if ($page instanceof Page && !$page->isPublished()) {
                $page->setStatus('published');
                $this->em->flush();
            }
        }
        // UPDATE: working copy already holds desired state.
    }

    public function describe(ContentStagingEntry $entry): ChangeCard
    {
        $snap = $entry->payload()['production_snapshot'] ?? [];
        $title = is_array($snap) ? (string) ($snap['title'] ?? $entry->label()) : $entry->label();
        if ($title === '') {
            $title = $entry->label();
        }
        $slug = is_array($snap) ? (string) ($snap['slug'] ?? '') : '';
        $summary = $slug !== '' ? '/' . $slug : $entry->label();

        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            return new ChangeCard('Smazat: ' . $title, $summary);
        }
        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            return new ChangeCard('Nové: ' . $title, $summary);
        }

        return new ChangeCard($title, $summary);
    }

    private function hardDelete(string $pageId): void
    {
        foreach ($this->em->getRepository(PageBlock::class)->findBy(['pageId' => $pageId]) as $block) {
            $this->em->remove($block);
        }
        $page = $this->em->find(Page::class, $pageId);
        if ($page instanceof Page) {
            $this->em->remove($page);
        }
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    public static function capture(EntityManagerInterface $em, Page $page): array
    {
        /** @var list<PageBlock> $blocks */
        $blocks = $em->getRepository(PageBlock::class)->findBy(
            ['pageId' => $page->id()],
            ['sortOrder' => 'ASC'],
        );

        return [
            'id' => $page->id(),
            'slug' => $page->slug(),
            'title' => $page->title(),
            'status' => $page->status(),
            'template' => $page->template(),
            'content_mode' => $page->contentMode(),
            'body' => $page->body(),
            'meta_description' => $page->metaDescription(),
            'published_at' => $page->publishedAt()?->format(\DateTimeInterface::ATOM),
            'blocks' => array_map(static fn (PageBlock $b): array => [
                'id' => $b->id(),
                'type' => $b->type(),
                'sort_order' => $b->sortOrder(),
                'payload' => $b->payload(),
            ], $blocks),
        ];
    }
}
