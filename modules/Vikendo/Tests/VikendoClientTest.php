<?php

declare(strict_types=1);

namespace Modules\Vikendo\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Gereq\Config\Repository;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\Testing\TestCase;
use Modules\Vikendo\Application\VikendoClient;

final class VikendoClientTest extends TestCase
{
    public function testBuildQueryEncodesRansackFilters(): void
    {
        $qs = VikendoClient::buildQuery([
            'page' => 1,
            'per_page' => 100,
            'q' => [
                'region_eid_eq' => '43',
                'district_eid_in' => ['3401', '3404'],
                'category_ids_overlaps' => [12, 34],
            ],
        ]);

        self::assertStringContainsString('page=1', $qs);
        self::assertStringContainsString('per_page=100', $qs);
        self::assertStringContainsString(rawurlencode('q[region_eid_eq]') . '=43', $qs);
        self::assertStringContainsString(rawurlencode('q[district_eid_in][]') . '=3401', $qs);
        self::assertStringContainsString(rawurlencode('q[district_eid_in][]') . '=3404', $qs);
        self::assertStringContainsString(rawurlencode('q[category_ids_overlaps][]') . '=12', $qs);
    }

    public function testListingQueryDefaultsToPlzenskyRegion(): void
    {
        $client = $this->clientWithSettings([
            'vikendo.default_region_eid' => VikendoClient::REGION_PLZENSKY,
        ]);

        $query = $client->listingQuery(
            districtEids: [VikendoClient::DISTRICT_EIDS_PLZENSKY['domazlice']],
            page: 2,
            perPage: 50,
        );

        self::assertSame(2, $query['page']);
        self::assertSame(50, $query['per_page']);
        self::assertSame('43', $query['q']['region_eid_eq']);
        self::assertSame(['3401'], $query['q']['district_eid_in']);
    }

    public function testDistrictEidConstantsCoverPlzensky(): void
    {
        self::assertCount(7, VikendoClient::DISTRICT_EIDS_PLZENSKY);
        self::assertSame('3404', VikendoClient::DISTRICT_EIDS_PLZENSKY['klatovy']);
        self::assertSame('43', VikendoClient::REGION_PLZENSKY);
    }

    public function testVikendoPathSlugAndHostDetection(): void
    {
        self::assertTrue(VikendoClient::isVikendoHostUrl('https://vikendo.cz/events/foo'));
        self::assertTrue(VikendoClient::isVikendoHostUrl('https://www.vikendo.cz/events/foo'));
        self::assertFalse(VikendoClient::isVikendoHostUrl('https://www.domazlice.eu/akce'));
        self::assertSame(
            'sebepece-wellbeing',
            VikendoClient::vikendoPathSlug('https://vikendo.cz/events/sebepece-wellbeing'),
        );
        self::assertSame(
            'foo-bar',
            VikendoClient::vikendoPathSlug('https://vikendo.cz/udalost/foo-bar'),
        );
        self::assertNull(VikendoClient::vikendoPathSlug('https://akceplzen.cz/akce/x'));
    }

    /**
     * @param array<string, mixed> $map
     */
    private function clientWithSettings(array $map): VikendoClient
    {
        $rows = [];
        foreach ($map as $key => $value) {
            $rows[] = new SettingRecord((string) $key, (string) $value, SettingRecord::TYPE_STRING);
        }

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findAll')->willReturn($rows);

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

        return new VikendoClient($settings);
    }
}
