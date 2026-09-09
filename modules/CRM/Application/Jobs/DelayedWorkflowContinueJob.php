<?php

declare(strict_types=1);

namespace Modules\CRM\Application\Jobs;

use Gereq\Queue\Job;
use Modules\CRM\Application\WorkflowRunner;

final class DelayedWorkflowContinueJob implements Job
{
    public function __construct(
        private readonly string $runId,
        private readonly string $nodeId,
        private readonly int $seconds = 60,
    ) {
    }

    public function handle(): void
    {
        $wait = max(1, min(120, $this->seconds));
        sleep($wait);
        gereq()->make(WorkflowRunner::class)->run($this->runId, $this->nodeId);
    }
}
