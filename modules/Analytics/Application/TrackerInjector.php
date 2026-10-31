<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

use Modules\Analytics\Application\Repositories\SiteRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Injects the tracker snippet into the storefront SPA shell (`storefront.spa_html` filter).
 *
 * The tracked site is resolved from the request host against `analytics_sites.domains`; an unknown
 * host, a disabled site or a disabled tracker returns the HTML byte-for-byte unchanged.
 *
 * Fail open: the filter runs for every storefront shell — including the platform 404 / error render
 * — so a missing analytics schema or an unavailable database must never turn a page into a 500. Any
 * failure is logged as an exception class name only (no message: DBAL messages can carry SQL
 * fragments, and the request URL must never be logged) and the HTML is returned untouched. Same
 * precedent as `modules/WebPlus/Application/SpaHtmlInjector`.
 */
final class TrackerInjector
{
    /**
     * Host → resolved site (or null) of the request currently being filtered.
     *
     * Negative results are cached too, so one request performs at most one lookup per host. The map
     * is dropped as soon as a different `Request` instance arrives, so a long-running worker never
     * serves a stale site row.
     *
     * @var array<string, array<string, mixed>|null>
     */
    private array $sitesByHost = [];

    private ?Request $resolvedFor = null;

    public function __construct(
        private readonly AnalyticsConfig $config,
        private readonly SiteRepository $sites,
        private readonly TrackerConfig $tracker,
    ) {
    }

    public function inject(string $html, Request $request): string
    {
        if ($html === '' || str_contains($html, 'data-analytics="gereq"')) {
            return $html;
        }

        try {
            $site = $this->siteForHost($request->getHost(), $request);
            if ($site === null || !$this->config->trackerEnabled($site)) {
                return $html;
            }

            $snippet = $this->tracker->snippet($site);
        } catch (\Throwable $exception) {
            // Debug channel of this module; class name only, never the message.
            error_log('[analytics.tracker] ' . $exception::class);

            return $html;
        }

        $position = strripos($html, '</body>');

        if ($position === false) {
            return $html . $snippet;
        }

        return substr($html, 0, $position) . $snippet . substr($html, $position);
    }

    /** @return array<string, mixed>|null */
    private function siteForHost(string $host, Request $request): ?array
    {
        if ($this->resolvedFor !== $request) {
            $this->resolvedFor = $request;
            $this->sitesByHost = [];
        }

        if (!array_key_exists($host, $this->sitesByHost)) {
            $this->sitesByHost[$host] = $this->sites->findEnabledByDomain($host);
        }

        return $this->sitesByHost[$host];
    }
}
