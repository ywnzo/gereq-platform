<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Gereq\Config\Repository;
use Gereq\Hook\HookManager;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\Validation\ValidationRegistry;
use Modules\Analytics\Application\AnalyticsConfig;
use Modules\Analytics\Application\BotClassifier;
use Modules\Analytics\Application\ConsentGate;
use Modules\Analytics\Application\IngestService;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Modules\Analytics\Application\Repositories\TrafficRepository;
use Modules\Analytics\Application\Sessionizer;
use Modules\Analytics\Application\TrafficClassifier;
use Modules\Analytics\Application\UserAgent;
use Modules\Analytics\Application\VisitorPseudonymizer;
use PHPUnit\Framework\TestCase;

/**
 * Origin binding on the ingest path ({@see IngestService::ingest()}).
 *
 * A site resolved **by key** is bound to the request host(s): a foreign host is suppressed through
 * the exact same silent no-op as an unknown key (`unknown_site`), so the storefront can never tell
 * a foreign key from a wrong host. A site with no configured domains accepts the key on its own.
 *
 * The collaborators are real services on a mocked DBAL/ORM boundary (the repositories are final),
 * so the tests pin the resolution decision, not SQL execution.
 */
final class IngestServiceTest extends TestCase
{
    private const CONSENT_COOKIE = '{"v":1,"statistics":true}';

    public function testForeignHostIsSuppressedLikeAnUnknownSite(): void
    {
        $service = $this->service($this->site(['example.com']));

        $result = $service->ingest($this->payload(), null, null, null, self::CONSENT_COOKIE, null, 'evil.test');

        self::assertTrue($result['suppressed']);
        self::assertNull($result['sid']);
        self::assertSame(IngestService::REASON_UNKNOWN_SITE, $result['reason']);
    }

    public function testUnknownKeyIsSuppressed(): void
    {
        $service = $this->service(null);

        $result = $service->ingest($this->payload(), null, null, null, self::CONSENT_COOKIE, null, 'example.com');

        self::assertSame(IngestService::REASON_UNKNOWN_SITE, $result['reason']);
    }

    public function testMissingHostEvidenceIsRejectedWhenDomainsAreConfigured(): void
    {
        $service = $this->service($this->site(['example.com']));

        $result = $service->ingest($this->payload(), null, null, null, self::CONSENT_COOKIE, null, null, null, null);

        self::assertSame(IngestService::REASON_UNKNOWN_SITE, $result['reason']);
    }

    public function testOriginHostMustAlsoMatchTheSiteDomains(): void
    {
        $service = $this->service($this->site(['example.com']));

        $result = $service->ingest($this->payload(), null, null, null, self::CONSENT_COOKIE, null, 'example.com', null, 'evil.test');

        self::assertSame(IngestService::REASON_UNKNOWN_SITE, $result['reason']);
    }

    public function testExactHostAcceptsTheKey(): void
    {
        // An implausibly short visitor id stops the flow right after site resolution: a
        // `no_visitor` reason (not `unknown_site`) proves the host binding accepted the beacon.
        $service = $this->service($this->site(['example.com']));

        $result = $service->ingest($this->payload('short'), null, null, null, self::CONSENT_COOKIE, null, 'example.com');

        self::assertTrue($result['suppressed']);
        self::assertSame(IngestService::REASON_NO_VISITOR, $result['reason'], 'the host binding accepted the site');
    }

    public function testWildcardHostAcceptsASubdomain(): void
    {
        $service = $this->service($this->site(['*.example.com']));

        $result = $service->ingest($this->payload('short'), null, null, null, self::CONSENT_COOKIE, null, 'shop.example.com');

        self::assertSame(IngestService::REASON_NO_VISITOR, $result['reason']);
    }

    public function testOriginHostSatisfiesTheBindingWhenTheRequestHostIsBlank(): void
    {
        $service = $this->service($this->site(['example.com']));

        $result = $service->ingest($this->payload('short'), null, null, null, self::CONSENT_COOKIE, null, null, null, 'example.com');

        self::assertSame(IngestService::REASON_NO_VISITOR, $result['reason']);
    }

    public function testEmptyDomainsAcceptTheKeyWithoutHostEvidence(): void
    {
        $service = $this->service($this->site([]));

        $result = $service->ingest($this->payload('short'), null, null, null, self::CONSENT_COOKIE, null, null, null, null);

        self::assertSame(IngestService::REASON_NO_VISITOR, $result['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $visitor = 'visitor1234'): array
    {
        return [
            'site' => 'grq_an_test',
            'consent' => ['v' => 1, 'statistics' => true],
            'visitor' => $visitor,
            'page' => ['url' => '/', 'referrer' => '', 'lang' => 'cs'],
            'events' => [['t' => 'pageview', 'ts' => time(), 'props' => []]],
        ];
    }

    /**
     * @param list<string> $domains
     * @return array<string, mixed>
     */
    private function site(array $domains): array
    {
        return [
            'id' => 'site-1',
            'name' => 'Example',
            'key' => 'grq_an_test',
            'domains' => $domains,
            'enabled' => true,
            'timezone' => 'Europe/Prague',
            'secret' => str_repeat('a', 64),
            'retention_override_days' => null,
            'keep_bot_hits' => false,
            'respect_dnt' => true,
            'excluded_paths' => [],
            'excluded_ips' => [],
        ];
    }

    /**
     * @param array<string, mixed>|null $site
     */
    private function service(?array $site): IngestService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($this->settingsRepository());
        $em->method('createQueryBuilder')->willReturn($this->siteBuilder($site));
        $em->method('getConnection')->willReturn($this->createMock(Connection::class));

        $validation = new ValidationRegistry(new HookManager(), new PassthroughTranslator());
        $validation->ruleset('analytics.collect', [
            'site' => 'nullable|max:64',
            'visitor' => 'nullable|max:190',
            'website_url' => 'nullable|max:190',
            'website' => 'nullable|max:190',
        ]);

        return new IngestService(
            $validation,
            $this->config($em),
            new ConsentGate(),
            new BotClassifier(),
            new TrafficClassifier(),
            new VisitorPseudonymizer(),
            new Sessionizer(),
            new UserAgent(),
            new SiteRepository($em),
            new TrafficRepository($em),
            $em,
        );
    }

    private function config(EntityManagerInterface $em): AnalyticsConfig
    {
        $publishables = new PublishableRegistry();

        return new AnalyticsConfig(new Settings(
            $em,
            new Repository([]),
            new ContentReadContext(),
            $publishables,
            new StagingWriter(new StagingStore($em), $publishables),
        ));
    }

    /** @return EntityRepository<SettingRecord> */
    private function settingsRepository(): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findAll')->willReturn([]);

        return $repository;
    }

    /**
     * @param array<string, mixed>|null $site
     */
    private function siteBuilder(?array $site): QueryBuilder
    {
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($site);
        $query->method('getSingleColumnResult')->willReturn([]);

        $builder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'setParameter', 'setMaxResults', 'orderBy', 'addOrderBy', 'groupBy'] as $fluent) {
            $builder->method($fluent)->willReturnSelf();
        }
        $builder->method('getQuery')->willReturn($query);

        return $builder;
    }
}
