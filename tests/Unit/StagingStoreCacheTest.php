<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\FrozenPublicReader;
use Gereq\Publish\StagingStore;
use Gereq\Testing\TestCase;

final class StagingStoreCacheTest extends TestCase
{
    public function testSnapshotReadsShareOneStagingQuery(): void
    {
        $page = new ContentStagingEntry('cms.pages', 'page', 'home', 'update', [
            'production_snapshot' => ['slug' => 'home'],
        ], 'Home');
        $menu = new ContentStagingEntry('cms.menus', 'menu', 'header', 'update', [
            'production_snapshot' => ['location' => 'header'],
        ], 'Header');
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::once())->method('findBy')->with([])->willReturn([$page, $menu]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(ContentStagingEntry::class)->willReturn($repo);
        $store = new StagingStore($em);
        $reader = new FrozenPublicReader($store);

        $reader->preloadAll();
        self::assertNull($store->find('cms.pages', 'missing'));
        self::assertSame([$menu, $page], $store->all());
        self::assertCount(1, $reader->allSnapshots('cms.pages'));
        self::assertCount(1, $reader->allSnapshots('cms.menus'));
        self::assertSame('home', $reader->findSnapshotBy('cms.pages', 'slug', 'home')['resource_key'] ?? null);
        $store->remove($menu);
        self::assertSame([$page], $store->all());
    }

    public function testEmptyStagingIsLoadedOnlyOnce(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::once())->method('findBy')->with([])->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $store = new StagingStore($em);

        self::assertSame([], $store->all());
        self::assertSame([], $store->all());
        self::assertNull($store->findSettingsOverlay('missing'));
    }
}
