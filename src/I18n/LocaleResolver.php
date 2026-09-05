<?php

declare(strict_types=1);

namespace Gereq\I18n;

use Gereq\Auth\Authenticator;
use Gereq\Config\Repository;
use Gereq\Setting\Settings;
use Gereq\User\Entity\User;

/**
 * Priorita: uživatel → globální nastavení → config → fallback.
 */
final class LocaleResolver
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly Settings $settings,
        private readonly Repository $config,
        private readonly LocaleCatalog $catalog,
    ) {
    }

    public function resolve(?User $user = null): string
    {
        $user ??= $this->auth->user();

        if ($user instanceof User) {
            $personal = $user->locale();
            if ($personal !== null && $this->catalog->isSupported($personal)) {
                return $personal;
            }
        }

        $global = (string) $this->settings->get('app.locale', $this->config->get('app.locale', $this->catalog->fallback()));
        if ($this->catalog->isSupported($global)) {
            return $global;
        }

        return $this->catalog->fallback();
    }
}
