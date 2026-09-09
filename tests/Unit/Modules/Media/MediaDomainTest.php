<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Media;

use Modules\Media\Domain\MediaAsset;
use Modules\Media\Domain\MediaFolder;
use Modules\Media\Domain\MediaLink;
use PHPUnit\Framework\TestCase;

final class MediaDomainTest extends TestCase
{
    public function testAssetDefaultsAndMutators(): void
    {
        $asset = new MediaAsset(
            'Hero',
            'seed/hero.avif',
            'image/avif',
            81920,
            'user-1',
            'Byt Praha',
            1200,
            800,
            'folder-1',
        );

        self::assertNotSame('', $asset->id());
        self::assertTrue($asset->isImage());
        self::assertSame('Hero', $asset->title());
        self::assertSame('Byt Praha', $asset->alt());
        self::assertSame(1200, $asset->width());
        self::assertSame(800, $asset->height());
        self::assertSame('folder-1', $asset->folderId());

        $asset->setTitle('Hero 2');
        $asset->setAlt('');
        $asset->setDimensions(null, null);
        $asset->moveTo(null);

        self::assertSame('Hero 2', $asset->title());
        self::assertNull($asset->alt());
        self::assertNull($asset->width());
        self::assertNull($asset->folderId());
    }

    public function testNonImageAsset(): void
    {
        $asset = new MediaAsset('Doc', 'files/a.pdf', 'application/pdf', 100);
        self::assertFalse($asset->isImage());
    }

    public function testFolderRenameAndMove(): void
    {
        $folder = new MediaFolder('Galerie', 'parent-1');
        self::assertSame('Galerie', $folder->name());
        self::assertSame('parent-1', $folder->parentId());

        $folder->rename('  Renamed  ');
        $folder->moveTo(null);

        self::assertSame('Renamed', $folder->name());
        self::assertNull($folder->parentId());
    }

    public function testMediaLinkMetadata(): void
    {
        $link = new MediaLink('asset-1', 'fleet_vehicle', 'veh-1', 'cover', 3);
        self::assertSame('asset-1', $link->mediaAssetId());
        self::assertSame('fleet_vehicle', $link->entityType());
        self::assertSame('veh-1', $link->entityId());
        self::assertSame('cover', $link->label());
        self::assertSame(3, $link->sortOrder());

        $link->setLabel('');
        $link->setSortOrder(1);
        self::assertNull($link->label());
        self::assertSame(1, $link->sortOrder());
    }
}
