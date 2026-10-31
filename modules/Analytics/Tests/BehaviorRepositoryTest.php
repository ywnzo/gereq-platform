<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Analytics\Application\Repositories\BehaviorRepository;
use PHPUnit\Framework\TestCase;

final class BehaviorRepositoryTest extends TestCase
{
    public function testReportsStayWithinSiteRangeAndExcludeRecordedPayloadsFromTimeline(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE analytics_sessions (id TEXT, site_id TEXT, started_at TEXT, last_activity_at TEXT, entry_path TEXT, device_type TEXT, browser TEXT, pageview_count INTEGER, duration_seconds INTEGER)');
        $db->executeStatement('CREATE TABLE analytics_events (id TEXT, site_id TEXT, session_id TEXT, pageview_id TEXT, name TEXT, occurred_at TEXT, props TEXT)');
        $db->executeStatement('CREATE TABLE analytics_pageviews (id TEXT, site_id TEXT, session_id TEXT, occurred_at TEXT, path TEXT, props TEXT)');
        foreach (['site-a', 'site-b'] as $site) {
            $db->insert('analytics_sessions', [
                'id' => $site . '-session', 'site_id' => $site, 'started_at' => '2026-10-20 10:00:00',
                'last_activity_at' => '2026-10-20 10:01:00', 'entry_path' => '/kontakt',
                'device_type' => 'desktop', 'browser' => 'chrome', 'pageview_count' => 1, 'duration_seconds' => 60,
            ]);
            $db->insert('analytics_pageviews', [
                'id' => $site . '-page', 'site_id' => $site, 'session_id' => $site . '-session',
                'occurred_at' => '2026-10-20 10:00:00', 'path' => '/kontakt', 'props' => '{}',
            ]);
            foreach (['form_submit_success', 'session_replay', 'form_validation_error'] as $name) {
                $db->insert('analytics_events', [
                    'id' => $site . $name, 'site_id' => $site, 'session_id' => $site . '-session',
                    'pageview_id' => $site . '-page', 'name' => $name, 'occurred_at' => '2026-10-20 10:01:00',
                    'props' => '{"field":"email","reason":"required","private_value":"must-not-be-returned"}',
                ]);
            }
        }
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($db);
        $repository = new BehaviorRepository($em);

        $sessions = $repository->sessions('site-a', '2026-10-20', '2026-10-20');
        self::assertCount(1, $sessions);
        self::assertSame(1, (int) $sessions[0]['conversions']);
        self::assertSame(1, (int) $sessions[0]['replay_chunks']);
        self::assertNull($repository->session('site-b', 'site-a-session'));
        self::assertSame([], $repository->sessions('site-a', '2026-10-21', '2026-10-21'));
        self::assertCount(2, $repository->forms('site-a', '2026-10-20', '2026-10-20'));
        $timeline = $repository->timeline('site-a', 'site-a-session');
        self::assertCount(3, $timeline);
        self::assertNotContains('session_replay', array_column($timeline, 'name'));
        self::assertStringNotContainsString('must-not-be-returned', json_encode($timeline, JSON_THROW_ON_ERROR));
        self::assertSame([], $repository->replayChunks('site-b', 'site-a-session'));
    }
}
