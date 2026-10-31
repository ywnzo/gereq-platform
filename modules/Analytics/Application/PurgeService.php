<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

use Modules\Analytics\Application\Repositories\PurgeRepository;

/**
 * Retention purge.
 *
 * Raw pageviews / events / sessions follow `analytics.retention_raw_days` (a site may override it),
 * visitors and their consent events are only removed once idle for
 * `analytics.retention_visitor_days` (consent rows by `occurred_at`), and daily rollups are kept
 * for `analytics.retention_rollup_days`. Every table is deleted in `analytics.purge_batch_size` id
 * batches so a purge never holds a long write lock.
 *
 * A dry run returns the same per-table numbers without deleting anything and reports `deleted: 0`.
 * The `consent` count is part of `candidates` / `deleted`.
 */
final class PurgeService
{
    public function __construct(
        private readonly AnalyticsConfig $config,
        private readonly PurgeRepository $repository,
    ) {
    }

    /**
     * @return array{pageviews: int, events: int, sessions: int, visitors: int, consent: int,
     *   rollups: int, candidates: int, deleted: int}
     */
    public function purge(?int $days = null, bool $dryRun = false): array
    {
        $rawDays = $days !== null && $days > 0 ? $days : $this->config->retentionRawDays();
        $batchSize = $this->config->purgeBatchSize();

        $rawCutoff = $this->timestampCutoff($rawDays);
        $visitorCutoff = $this->timestampCutoff($this->config->retentionVisitorDays());
        $rollupCutoff = $this->dayCutoff($this->config->retentionRollupDays());

        $pageviews = $this->process(PurgeRepository::TARGET_PAGEVIEWS, $rawCutoff, $batchSize, $dryRun);
        $events = $this->process(PurgeRepository::TARGET_EVENTS, $rawCutoff, $batchSize, $dryRun);
        $sessions = $this->process(PurgeRepository::TARGET_SESSIONS, $rawCutoff, $batchSize, $dryRun);
        $visitors = $this->process(PurgeRepository::TARGET_VISITORS, $visitorCutoff, $batchSize, $dryRun);
        // Consent events are personal data of the visitor, so they follow the visitor window.
        $consent = $this->process(PurgeRepository::TARGET_CONSENT, $visitorCutoff, $batchSize, $dryRun);
        $rollupStats = $this->process(PurgeRepository::TARGET_STATS, $rollupCutoff, $batchSize, $dryRun);
        $rollupDimensions = $this->process(PurgeRepository::TARGET_DIMENSIONS, $rollupCutoff, $batchSize, $dryRun);
        $rollups = $rollupStats + $rollupDimensions;
        $candidates = $pageviews + $events + $sessions + $visitors + $consent + $rollups;

        return [
            'pageviews' => $pageviews,
            'events' => $events,
            'sessions' => $sessions,
            'visitors' => $visitors,
            'consent' => $consent,
            'rollups' => $rollups,
            'candidates' => $candidates,
            'deleted' => $dryRun ? 0 : $candidates,
        ];
    }

    /**
     * Rows that a real purge with the same arguments would remove (dry run counts).
     *
     * @return array{pageviews: int, events: int, sessions: int, visitors: int, consent: int,
     *   rollups: int, candidates: int, deleted: int}
     */
    public function preview(?int $days = null): array
    {
        return $this->purge($days, true);
    }

    private function process(string $target, string $cutoff, int $batchSize, bool $dryRun): int
    {
        if ($dryRun) {
            return $this->repository->countCandidates($target, $cutoff);
        }

        return $this->repository->purgeTable($target, $cutoff, $batchSize);
    }

    private function timestampCutoff(int $days): string
    {
        return (new \DateTimeImmutable('today'))
            ->modify('-' . max(1, $days) . ' days')
            ->format('Y-m-d H:i:s');
    }

    private function dayCutoff(int $days): string
    {
        return (new \DateTimeImmutable('today'))
            ->modify('-' . max(1, $days) . ' days')
            ->format('Y-m-d');
    }
}
