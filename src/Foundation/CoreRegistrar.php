<?php

declare(strict_types=1);

namespace Gereq\Foundation;

final class CoreRegistrar
{
    public function __construct(
        private readonly CoreSettingsRegistrar $settings,
        private readonly AdminRoutesRegistrar $adminRoutes,
        private readonly PublicApiRegistrar $publicApi,
    ) {
    }

    public function register(): void
    {
        $this->settings->register();
        $this->adminRoutes->register();
        $this->publicApi->register();
    }
}
