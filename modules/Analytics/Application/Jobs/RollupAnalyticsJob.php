<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Jobs;

use Gereq\Queue\Job;
use Modules\Analytics\Application\RollupService;

/**
 * Hourly rollup of the analytics day aggregates.
 *
 * Queue payloads are JSON rebuilt through this constructor, so only scalars are allowed
 * (`src/Queue/Queue.php::decodeJsonPayload()`); the service itself is resolved in `handle()`
 * because the queue worker has no request-bound container state.
 */
final class RollupAnalyticsJob implements Job
{
    public function __construct(
        private readonly ?string $day = null,
        private readonly int $days = 1,
    ) {
    }

    public function handle(): void
    {
        gereq()->make(RollupService::class)->rollup($this->day, $this->days);
    }
}
