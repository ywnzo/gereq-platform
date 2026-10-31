<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

use Gereq\Http\Router;

/**
 * Public, cacheable tracker bootstrap.
 *
 * `GET /api/public/analytics/config` returns only the public subset — no admin-only setting value
 * ever leaves this class (README "Public surface").
 */
final class TrackerConfig
{
    /** Cache-busting version of `Resources/public/tracker.js`; keep in sync with the module version. */
    public const TRACKER_VERSION = '0.3.1';

    /** Seconds the config + tracker.js may be cached by the browser. */
    public const CACHE_TTL = 300;

    public function __construct(
        private readonly AnalyticsConfig $config,
        private readonly Router $router,
    ) {
    }

    /**
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    public function payload(array $site): array
    {
        return [
            'enabled' => $this->config->trackerEnabled($site),
            'endpoint' => $this->router->url('api.public.analytics.collect'),
            'site' => (string) ($site['key'] ?? ''),
            'consent' => [
                'storage_key' => $this->config->consentStorageKey(),
                'category' => $this->config->consentCategory(),
            ],
            'tracker_url' => $this->trackerUrl(),
            'beacon_max_events' => $this->config->beaconMaxEvents(),
            'respect_dnt' => $this->config->respectDnt($site),
        ];
    }

    public function trackerUrl(): string
    {
        return $this->router->url('api.public.analytics.tracker') . '?v=' . self::TRACKER_VERSION;
    }

    /**
     * Inline snippet injected into the storefront shell.
     *
     * The attribute set mirrors what `Resources/public/tracker.js` reads from its own
     * `<script>` tag (`data-site`, `data-endpoint`, `data-consent-*`, `data-max-events`,
     * `data-respect-dnt`) so the tracker starts without an extra config round trip; the
     * `data-analytics="gereq"` marker lets the injector stay idempotent.
     *
     * @param array<string, mixed> $site
     */
    public function snippet(array $site): string
    {
        $attributes = [
            'data-analytics' => 'gereq',
            'data-site' => (string) ($site['key'] ?? ''),
            'data-endpoint' => $this->router->url('api.public.analytics.collect'),
            'data-consent-endpoint' => $this->router->url('api.public.analytics.consent'),
            'data-config' => $this->router->url('api.public.analytics.config'),
            'data-consent-key' => $this->config->consentStorageKey(),
            'data-consent-category' => $this->config->consentCategory(),
            'data-max-events' => (string) $this->config->beaconMaxEvents(),
            'data-respect-dnt' => $this->config->respectDnt($site) ? '1' : '0',
        ];

        $parts = ['defer', 'src="' . htmlspecialchars($this->trackerUrl(), ENT_QUOTES) . '"'];
        foreach ($attributes as $name => $value) {
            $parts[] = $name . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
        }

        return '<script ' . implode(' ', $parts) . '></script>';
    }
}
