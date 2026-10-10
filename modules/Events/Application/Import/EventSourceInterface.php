<?php

declare(strict_types=1);

namespace Modules\Events\Application\Import;

interface EventSourceInterface
{
    public function key(): string;

    /** @return list<NormalizedEvent> */
    public function fetch(): array;
}
