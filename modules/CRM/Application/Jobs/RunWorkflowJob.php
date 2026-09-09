<?php

declare(strict_types=1);

namespace Modules\CRM\Application\Jobs;

use Gereq\Queue\Job;
use Modules\CRM\Application\WorkflowRunner;

final class RunWorkflowJob implements Job
{
    public function __construct(private readonly string $runId)
    {
    }

    public function handle(): void
    {
        gereq()->make(WorkflowRunner::class)->run($this->runId);
    }
}
