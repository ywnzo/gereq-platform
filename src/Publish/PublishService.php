<?php

declare(strict_types=1);

namespace Gereq\Publish;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\Entity\ContentRelease;
use Gereq\Publish\Entity\ContentReleaseItem;
use Gereq\Publish\Entity\ContentStagingEntry;

final class PublishService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StagingStore $store,
        private readonly PublishableRegistry $registry,
        private readonly ContentVersion $versions,
        private readonly ContentReadContext $readContext,
    ) {
    }

    /**
     * @return array{
     *   count: int,
     *   version: string,
     *   categories: list<array{
     *     category: string,
     *     label: string,
     *     items: list<array{
     *       id: string,
     *       label: string,
     *       action: string,
     *       resource_key: string,
     *       resource_type: string,
     *       title: string,
     *       summary: string,
     *       href: ?string,
     *       updated_at: string
     *     }>
     *   }>
     * }
     */
    public function pending(int $previewLimitPerCategory = 0): array
    {
        $entries = $this->store->all();
        $grouped = [];

        foreach ($this->registry->all() as $definition) {
            $grouped[$definition->category] = [
                'category' => $definition->category,
                'label' => $definition->label,
                'items' => [],
            ];
        }

        foreach ($entries as $entry) {
            $category = $entry->category();
            if (!isset($grouped[$category])) {
                $grouped[$category] = [
                    'category' => $category,
                    'label' => $category,
                    'items' => [],
                ];
            }

            $card = $this->describe($entry);
            $grouped[$category]['items'][] = [
                'id' => $entry->id(),
                'label' => $entry->label(),
                'action' => $entry->action(),
                'resource_key' => $entry->resourceKey(),
                'resource_type' => $entry->resourceType(),
                'title' => $card->title,
                'summary' => $card->summary,
                'href' => $card->href,
                'updated_at' => $entry->updatedAt()->format(DATE_ATOM),
            ];
        }

        $categories = [];
        foreach ($grouped as $group) {
            if ($group['items'] === []) {
                continue;
            }
            if ($previewLimitPerCategory > 0) {
                $group['items'] = array_slice($group['items'], 0, $previewLimitPerCategory);
            }
            $categories[] = $group;
        }

        return [
            'count' => count($entries),
            'version' => $this->versions->current(),
            'categories' => $categories,
        ];
    }

    /**
     * @param list<string> $entryIds
     * @return array{version: string, published: int, release_id: string}
     */
    public function publish(array $entryIds, ?string $actorId = null): array
    {
        $entries = $this->store->findByIds($entryIds);
        if ($entries === []) {
            throw new \InvalidArgumentException('No staging entries selected.');
        }

        $byCategory = [];
        foreach ($entries as $entry) {
            $byCategory[$entry->category()][] = $entry->resourceKey();
        }

        $backup = [];
        foreach ($byCategory as $category => $keys) {
            if (!$this->registry->has($category)) {
                continue;
            }
            $backup[$category] = $this->registry->adapter($category)->snapshot(array_values(array_unique($keys)));
        }

        $this->em->beginTransaction();
        try {
            $this->readContext->withProduction(function () use ($entries): void {
                foreach ($entries as $entry) {
                    if (!$this->registry->has($entry->category())) {
                        throw new \RuntimeException(sprintf('Unknown publishable category "%s".', $entry->category()));
                    }
                    $this->registry->adapter($entry->category())->apply($entry);
                }
            });

            $version = $this->versions->bump(count($entries));
            $release = new ContentRelease($version, count($entries), $backup, $actorId);
            $this->em->persist($release);

            foreach ($entries as $entry) {
                $this->em->persist(new ContentReleaseItem(
                    $release->id(),
                    $entry->category(),
                    $entry->resourceKey(),
                    $entry->label(),
                    $entry->action(),
                    $entry->payload(),
                ));
                $this->em->remove($entry);
            }

            $this->em->flush();
            $this->em->commit();

            return [
                'version' => $version,
                'published' => count($entries),
                'release_id' => $release->id(),
            ];
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    /**
     * @return list<array{id: string, version: string, change_count: int, created_at: string, created_by: ?string}>
     */
    public function releases(int $limit = 50): array
    {
        try {
            /** @var list<ContentRelease> $rows */
            $rows = $this->em->getRepository(ContentRelease::class)
                ->findBy([], ['createdAt' => 'DESC'], $limit);

            return array_map(static fn (ContentRelease $r): array => [
                'id' => $r->id(),
                'version' => $r->version(),
                'change_count' => $r->changeCount(),
                'created_at' => $r->createdAt()->format(DATE_ATOM),
                'created_by' => $r->createdBy(),
            ], $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    private function describe(ContentStagingEntry $entry): ChangeCard
    {
        if (!$this->registry->has($entry->category())) {
            return new ChangeCard($entry->label());
        }

        return $this->registry->adapter($entry->category())->describe($entry);
    }
}
