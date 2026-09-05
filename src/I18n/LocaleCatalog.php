<?php

declare(strict_types=1);

namespace Gereq\I18n;

use Gereq\Config\Repository;

final class LocaleCatalog
{
    public function __construct(
        private readonly Repository $config,
    ) {
    }

    /** @return array<string, string> locale => label */
    public function all(): array
    {
        /** @var array<string, string> $locales */
        $locales = $this->config->get('i18n.locales', [
            'cs' => 'Čeština',
            'en' => 'English',
        ]);

        return $locales;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->all());
    }

    public function isSupported(string $locale): bool
    {
        return array_key_exists($locale, $this->all());
    }

    public function label(string $locale): string
    {
        return $this->all()[$locale] ?? $locale;
    }

    public function fallback(): string
    {
        $fallback = (string) $this->config->get('i18n.fallback_locale', 'cs');

        return $this->isSupported($fallback) ? $fallback : 'cs';
    }
}
