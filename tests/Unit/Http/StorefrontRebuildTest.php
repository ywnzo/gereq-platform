<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Http;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Gereq\Config\Repository;
use Gereq\Http\StorefrontRebuild;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use PHPUnit\Framework\TestCase;

final class StorefrontRebuildTest extends TestCase
{
    public function testParseRemoteSshPath(): void
    {
        $rebuild = $this->makeRebuild();

        self::assertSame(
            ['userHost' => 'deploy@example.com', 'path' => '/var/www/fe'],
            $rebuild->parseRemote('deploy@example.com:/var/www/fe'),
        );
        self::assertNull($rebuild->parseRemote('../fe'));
        self::assertNull($rebuild->parseRemote('/var/www/fe'));
        self::assertNull($rebuild->parseRemote('deploy@example.com:relative'));
    }

    public function testResolveLocalRelativeToBase(): void
    {
        $base = sys_get_temp_dir() . '/gereq-sf-rebuild-' . uniqid('', true);
        $fe = $base . '/fe';
        self::assertTrue(mkdir($fe, 0777, true));
        try {
            $rebuild = $this->makeRebuild($base);
            self::assertSame(realpath($fe), $rebuild->resolveLocalPath('fe'));
            self::assertSame(realpath($fe), $rebuild->resolveLocalPath($fe));
            self::assertNull($rebuild->resolveLocalPath('missing'));
        } finally {
            @rmdir($fe);
            @rmdir($base);
        }
    }

    public function testRunRequiresConfiguredPath(): void
    {
        $result = $this->makeRebuild()->run();

        self::assertFalse($result['ok']);
        self::assertNotNull($result['error']);
        self::assertStringContainsString('storefront.path', $result['error']);
    }

    private function makeRebuild(?string $basePath = null): StorefrontRebuild
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findAll')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(SettingRecord::class)->willReturn($repo);

        $publishables = new PublishableRegistry();
        $settings = new Settings(
            $em,
            new Repository([]),
            new ContentReadContext(),
            $publishables,
            new StagingWriter(new StagingStore($em), $publishables),
        );

        return new StorefrontRebuild($settings, $basePath ?? sys_get_temp_dir());
    }
}
