<?php

declare(strict_types=1);

namespace Modules\Notifications\Application;

final class NotificationsProvider
{
    public function __construct(private readonly NotifierBridge $bridge)
    {
    }

    public function bridge(): NotifierBridge
    {
        return $this->bridge;
    }
}