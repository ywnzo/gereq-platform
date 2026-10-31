<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Jobs;

use Gereq\Event\EventBus;
use Gereq\Queue\Job;
use Modules\Analytics\Application\PurgeService;

/**
 * Daily retention purge (03:20 Europe/Prague, registered in `boot()`).
 *
 * Scalar-only constructor because the queue payload is JSON rebuilt via the constructor
 * (`src/Queue/Queue.php::decodeJsonPayload()`); services are resolved in `handle()`.
 * A dry run keeps the queue usable for a preview and emits no retention event.
 */
final class PurgeAnalyticsJob implements Job
{
    public function __construct(
        private readonly ?int $days = null,
        private readonly bool $dryRun = false,
    ) {
    }

    public function handle(): void
    {
        $result = gereq()->make(PurgeService::class)->purge($this->days, $this->dryRun);
        if ($this->dryRun) {
            return;
        }

        gereq()->make(EventBus::class)->emit(
            'analytics.retention.purged',
            ['type' => 'analytics', 'id' => 'retention'],
            $result,
        );
    }
}
