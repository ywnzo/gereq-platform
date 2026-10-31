<?php

declare(strict_types=1);

namespace Modules\Cookie\Application;

use Gereq\Setting\Settings;

final class LegacySettingsImporter
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function copy(): int
    {
        $copied = 0;
        foreach (CookieConfig::definitions() as $key => $definition) {
            $legacy = 'webplus.cookies_' . substr($key, 7);
            if ($this->settings->has($key) || !$this->settings->has($legacy)) {
                continue;
            }
            $this->settings->setLive($key, $this->settings->getLive($legacy), $definition['type'], 'cookie');
            ++$copied;
        }

        return $copied;
    }
}
