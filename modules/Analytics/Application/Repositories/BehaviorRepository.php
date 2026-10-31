<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Repositories;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Analytics\Application\TrafficClassifier;

final class BehaviorRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forms(string $siteId, string $from, string $to): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT name, COUNT(*) AS events, COUNT(DISTINCT session_id) AS sessions'
            . ' FROM analytics_events WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ?'
            . ' AND name IN (?, ?, ?, ?, ?, ?, ?, ?) AND props NOT LIKE ? GROUP BY name',
            [$siteId, $from . ' 00:00:00', self::end($to), 'form_start', 'form_field_focus',
                'form_field_complete', 'form_validation_error', 'form_submit_attempt',
                'form_submit_success', 'form_submit_error', 'form_abandon', TrafficClassifier::EXCLUDED_PROPS_LIKE],
        );
    }

    /** @return list<array<string, mixed>> */
    public function sessions(string $siteId, string $from, string $to): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT s.id, s.started_at, s.last_activity_at, s.entry_path AS landing_path, s.device_type, s.browser,'
            . ' s.pageview_count, s.duration_seconds,'
            . ' SUM(CASE WHEN e.name = ? THEN 1 ELSE 0 END) AS replay_chunks,'
            . ' SUM(CASE WHEN e.name = ? THEN 1 ELSE 0 END) AS conversions'
            . ' FROM analytics_sessions s LEFT JOIN analytics_events e ON e.session_id = s.id AND e.site_id = s.site_id'
            . ' WHERE s.site_id = ? AND s.started_at >= ? AND s.started_at < ? AND s.device_type NOT IN (?, ?)'
            . ' GROUP BY s.id, s.started_at, s.last_activity_at, s.entry_path, s.device_type, s.browser,'
            . ' s.pageview_count, s.duration_seconds ORDER BY s.started_at DESC LIMIT 100',
            ['session_replay', 'form_submit_success', $siteId, $from . ' 00:00:00', self::end($to),
                TrafficClassifier::EXCLUDED_BOT, TrafficClassifier::EXCLUDED_INTERNAL],
        );
    }

    /** @return array<string, mixed>|null */
    public function session(string $siteId, string $id): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT id, started_at, last_activity_at, entry_path AS landing_path, device_type, browser, pageview_count, duration_seconds'
            . ' FROM analytics_sessions WHERE site_id = ? AND id = ? AND device_type NOT IN (?, ?)',
            [$siteId, $id, TrafficClassifier::EXCLUDED_BOT, TrafficClassifier::EXCLUDED_INTERNAL],
        );

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function timeline(string $siteId, string $id): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT e.name, e.occurred_at, e.props, p.path FROM analytics_events e'
            . ' LEFT JOIN analytics_pageviews p ON p.id = e.pageview_id AND p.site_id = e.site_id'
            . ' WHERE e.site_id = ? AND e.session_id = ? AND e.name <> ? AND e.props NOT LIKE ?'
            . ' ORDER BY e.occurred_at ASC, e.id ASC LIMIT 500',
            [$siteId, $id, 'session_replay', TrafficClassifier::EXCLUDED_PROPS_LIKE],
        );
        foreach ($rows as &$row) {
            $props = json_decode((string) $row['props'], true);
            $row['field'] = is_array($props) && in_array($props['field'] ?? '', ['name', 'phone', 'email', 'website', 'message', 'service'], true)
                ? $props['field'] : null;
            $row['reason'] = is_array($props) && in_array($props['reason'] ?? '', ['required', 'format', 'invalid', 'request_failed'], true)
                ? $props['reason'] : null;
            $row['filled'] = is_array($props) && is_bool($props['filled'] ?? null) ? $props['filled'] : null;
            unset($row['props']);
        }
        unset($row);
        $pages = $this->em->getConnection()->fetchAllAssociative(
            'SELECT occurred_at, path FROM analytics_pageviews WHERE site_id = ? AND session_id = ?'
            . ' AND (props IS NULL OR props NOT LIKE ?) ORDER BY occurred_at ASC LIMIT 200',
            [$siteId, $id, TrafficClassifier::EXCLUDED_PROPS_LIKE],
        );
        foreach ($pages as $page) {
            $rows[] = ['name' => 'pageview', 'occurred_at' => $page['occurred_at'], 'path' => $page['path'], 'field' => null, 'reason' => null, 'filled' => null];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['occurred_at'], (string) $b['occurred_at']));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function replayChunks(string $siteId, string $id): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT props FROM analytics_events WHERE site_id = ? AND session_id = ? AND name = ?'
            . ' AND props NOT LIKE ? ORDER BY occurred_at ASC, id ASC LIMIT 600',
            [$siteId, $id, 'session_replay', TrafficClassifier::EXCLUDED_PROPS_LIKE],
        );
    }

    private static function end(string $to): string
    {
        return (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00');
    }
}
