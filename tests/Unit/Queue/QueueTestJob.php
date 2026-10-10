<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Queue;

use Gereq\Queue\Job;

final class QueueTestJob implements Job
{
    public bool $handled = false;

    public function __construct(public readonly string $value)
    {
    }

    public function handle(): void
    {
        $this->handled = true;
    }
}
