<?php

declare(strict_types=1);

namespace Integrations\Packeta\Tests;

use Gereq\Cache\AppCache;
use Gereq\Config\Repository;
use Integrations\Packeta\Application\PacketaPickupPoints;
use Modules\Shop\Tests\PricingFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PacketaPickupPointsTest extends TestCase
{
    public function testOnlyOperationalPublicCountryMatchingPointsAreSelectable(): void
    {
        $row = self::row();
        self::assertNotNull(PacketaPickupPoints::normalize($row, 'CZ', 'branch', '2026-10-29'));
        foreach ([['country' => 'sk'], ['displayFrontend' => '0'], ['status' => ['statusId' => '2']],
            ['enterableUntil' => '2026-10-28'], ['openSince' => '2026-11-01'],
            ['holidayStart' => '2026-10-01', 'holidayEnd' => '2026-11-01'], ['name' => ''], ['id' => 'invalid'], ['openUntil' => []]] as $override) {
            self::assertNull(PacketaPickupPoints::normalize(array_replace($row, $override), 'CZ', 'branch', '2026-10-29'));
        }
    }

    public function testOfficialBranchAndBoxFeedShapesSupportSearchResolutionAndCod(): void
    {
        $f = new PricingFixture();
        $f->settings->setLive('shop.packeta_api_key', 'test-public-key');
        $cache = new AppCache(new ArrayAdapter(), new Repository([]));
        $cache->put(PacketaPickupPoints::cacheKey('test-public-key', 'CZ', 'branch'), [self::row()]);
        $cache->put(PacketaPickupPoints::cacheKey('test-public-key', 'CZ', 'box'), [array_replace(self::row(), ['id' => '102', 'name' => 'Z-BOX Praha', 'codAllowed' => '0'])]);
        $feed = new PacketaPickupPoints($f->settings, $cache);
        self::assertCount(2, $feed->search('CZ', 'Praha'));
        self::assertCount(2, $feed->search('CZ', 'Praha 11000'));
        self::assertCount(1, $feed->search('CZ', 'prikopy'));
        self::assertSame('branch', $feed->resolve('CZ', '101')->type);
        self::assertFalse($feed->resolve('CZ', '102')->codAllowed);
        self::assertSame('box', $feed->resolve('CZ', '102')->type);
        self::assertNotSame(PacketaPickupPoints::cacheKey('key1', 'CZ', 'box'), PacketaPickupPoints::cacheKey('key2', 'CZ', 'box'));
        self::assertNotSame(PacketaPickupPoints::cacheKey('key1', 'CZ', 'box'), PacketaPickupPoints::cacheKey('key1', 'SK', 'box'));
        $this->expectException(\InvalidArgumentException::class);
        $feed->resolve('CZ', '999');
    }

    public function testMissingConfigurationFailsClosedWithoutNetworkRequest(): void
    {
        $f = new PricingFixture();
        $feed = new PacketaPickupPoints($f->settings, new AppCache(new ArrayAdapter(), new Repository([])));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('shop.error.pickup_unavailable');
        $feed->search('CZ', 'Praha');
    }

    /** @return array<string, mixed> */
    private static function row(): array
    {
        return ['id' => '101', 'name' => 'Praha, Na Příkopy', 'street' => 'Na Příkopě 10', 'city' => 'Praha', 'zip' => '110 00', 'country' => 'cz',
            'status' => ['statusId' => '1'], 'displayFrontend' => '1'];
    }
}
