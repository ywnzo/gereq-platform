<?php

declare(strict_types=1);

namespace Gereq\Queue;

interface Job
{
    public function handle(): void;
}
