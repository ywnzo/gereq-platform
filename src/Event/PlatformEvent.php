<?php

declare(strict_types=1);

namespace Gereq\Event;

interface PlatformEvent
{
    public function envelope(): EventEnvelope;
}
