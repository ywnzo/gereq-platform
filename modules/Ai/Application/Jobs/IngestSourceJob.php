<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Jobs;

use Gereq\Queue\Job;
use Modules\Ai\Application\Rag\IngestPipeline;

final class IngestSourceJob implements Job
{
    public function __construct(private readonly string $sourceId)
    {
    }

    public function handle(): void
    {
        /** @var IngestPipeline $pipeline */
        $pipeline = gereq()->make(IngestPipeline::class);
        $pipeline->ingestSource($this->sourceId);
    }
}
