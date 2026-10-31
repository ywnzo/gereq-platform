<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Public;

use Gereq\Api\ApiResponse;
use Gereq\Http\PublicCors;
use Gereq\Security\RateLimiter;
use Modules\Analytics\Application\ConsentGate;
use Modules\Analytics\Application\ConsentService;
use Modules\Analytics\Application\IngestService;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Modules\Analytics\Application\TrackerConfig;
use Modules\Analytics\Application\TrafficClassifier;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public storefront surface of the analytics module (README "Public surface").
 *
 * `collect` and `consent` answer `200` with a minimal JSON body on success (`{"ok":true,"sid":…}` /
 * `{"ok":true,"decision":…}`). Every suppressed, no-op or unreadable request answers `204` with an
 * empty body — a `204` carries no payload by definition, and the beacon must never see an error, a
 * stack trace or a reason why a hit was dropped.
 */
final class PublicAnalyticsController
{
    /** Module-level rate limit per client IP, independent of client-controlled tracker keys. */
    public const RATE_LIMIT_PER_MINUTE = 300;

    /** Hard cap on the limiter key so a crafted value cannot bloat the limiter storage. */
    private const MAX_RATE_LIMIT_KEY_LENGTH = 120;

    /** Hard cap on the beacon body (README: 32 KB). */
    private const MAX_BODY_BYTES = 32768;

    /** Inbound country headers — `country_code` is never resolved from an IP. */
    private const COUNTRY_HEADERS = ['CF-IPCountry', 'X-Country-Code', 'X-Geo-Country'];

    public function __construct(
        private readonly IngestService $ingest,
        private readonly ConsentService $consentService,
        private readonly SiteRepository $sites,
        private readonly TrackerConfig $tracker,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function options(Request $request): Response
    {
        return PublicCors::apply(new Response('', 204), $request, 'POST, OPTIONS');
    }

    public function collect(Request $request): Response
    {
        $body = $this->body($request);
        if ($body === null) {
            return $this->noContent($request);
        }

        if (!$this->rateLimited($request)) {
            return PublicCors::apply(
                ApiResponse::error('rate_limited', 'Too many requests.', [], 429),
                $request,
                'POST, OPTIONS',
            );
        }

        $result = $this->ingest->ingest(
            $body,
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
            $this->webdriverFlag($body),
            $this->cookie($request, ConsentGate::COOKIE),
            $this->cookie($request, TrafficClassifier::OPTOUT_COOKIE),
            $request->getHost(),
            $this->countryCode($request),
            $this->originHost($request),
        );

        if ($result['suppressed'] || $result['sid'] === null) {
            return $this->noContent($request);
        }

        return $this->success($request, ['ok' => true, 'sid' => $result['sid']]);
    }

    public function consent(Request $request): Response
    {
        $body = $this->body($request);
        if ($body === null) {
            return $this->noContent($request);
        }

        if (!$this->rateLimited($request)) {
            return PublicCors::apply(
                ApiResponse::error('rate_limited', 'Too many requests.', [], 429),
                $request,
                'POST, OPTIONS',
            );
        }

        $result = $this->consentService->record(
            $body,
            $this->cookie($request, ConsentGate::COOKIE),
            $request->getHost(),
        );

        if ($result['recorded'] === false) {
            return $this->noContent($request);
        }

        return $this->success($request, ['ok' => true, 'decision' => $result['decision']]);
    }

    /**
     * Public, cacheable tracker bootstrap — no admin-only setting value is ever included here.
     *
     * The payload depends on the resolved site, which in turn depends on the request host, so the
     * response must vary on `Host` (it is publicly cacheable).
     */
    public function config(Request $request): Response
    {
        $site = $this->resolveSite($request);
        $payload = $site !== null
            ? $this->tracker->payload($site)
            : ['enabled' => false];

        $json = (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $response = new Response($json, 200, ['Content-Type' => 'application/json']);
        $response->setVary('Host');
        $response->setEtag(hash('sha256', $json));
        $response->setPublic();
        $response->setMaxAge(TrackerConfig::CACHE_TTL);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Serves `Resources/public/tracker.js` straight from disk (no Twig, no build step).
     * The file is owned by the assets task; a missing file is a plain 404.
     */
    public function tracker(Request $request): Response
    {
        $path = dirname(__DIR__, 2) . '/Resources/public/tracker.js';
        if (!is_file($path)) {
            return new Response('', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $content = (string) file_get_contents($path);
        $response = new Response($content, 200, ['Content-Type' => 'application/javascript; charset=utf-8']);
        $response->setEtag(hash('sha256', $content . TrackerConfig::TRACKER_VERSION));
        $response->setPublic();
        $response->setMaxAge(TrackerConfig::CACHE_TTL);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * The IP-only bucket bounds all analytics writes before a database lookup. Site keys are
     * client-controlled and cannot create additional buckets. Site resolution belongs to ingest.
     */
    private function rateLimited(Request $request): bool
    {
        $key = 'analytics:' . (string) ($request->getClientIp() ?? 'unknown');

        return $this->limiter->hit(
            mb_substr($key, 0, self::MAX_RATE_LIMIT_KEY_LENGTH),
            self::RATE_LIMIT_PER_MINUTE,
            60,
        );
    }

    /**
     * Successful beacon response: `200` with the documented minimal JSON body.
     *
     * @param array<string, mixed> $payload
     */
    private function success(Request $request, array $payload): Response
    {
        $response = new Response(
            (string) json_encode($payload, JSON_THROW_ON_ERROR),
            200,
            ['Content-Type' => 'application/json'],
        );

        return PublicCors::apply($response, $request, 'POST, OPTIONS');
    }

    /**
     * Suppressed / no-op / unreadable beacon: `204` with no body and no reason.
     *
     * Symfony strips the body of a `204`, so nothing is passed in the first place.
     */
    private function noContent(Request $request): Response
    {
        return PublicCors::apply(new Response('', 204), $request, 'POST, OPTIONS');
    }

    /** @return array<string, mixed>|null Null when the body is oversized or unreadable. */
    private function body(Request $request): ?array
    {
        $content = $request->getContent();
        if (strlen($content) > self::MAX_BODY_BYTES) {
            return null;
        }

        if ($content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $form = $request->request->all();

        return $form !== [] ? $form : [];
    }

    /**
     * Resolve the site for the public `config` endpoint.
     *
     * A `?site=` tracker key is public, so the endpoint only answers it when the request host (and
     * the page `Origin`/`Referer` host, when present) belongs to the site's configured domains —
     * otherwise the endpoint would confirm foreign site keys ({@see IngestService::ingest()} uses
     * the same binding). Host resolution needs no extra check: it already goes through the site's
     * own domain list.
     *
     * @return array<string, mixed>|null
     */
    private function resolveSite(Request $request): ?array
    {
        $key = trim((string) $request->query->get('site', ''));
        if ($key === '') {
            return $this->sites->findEnabledByDomain($request->getHost());
        }

        $site = $this->sites->findEnabledByKey($key);
        if ($site === null) {
            return null;
        }

        return SiteRepository::hostsMatchSite($site, [$request->getHost(), $this->originHost($request)])
            ? $site
            : null;
    }

    /**
     * Host of the page that issued the request: `Origin` first (browsers send it on every POST),
     * `Referer` as the fallback. An opaque `null` origin is not usable evidence and is ignored.
     */
    private function originHost(Request $request): ?string
    {
        $origin = SiteRepository::normalizeHost((string) $request->headers->get('Origin', ''));
        if ($origin !== '' && $origin !== 'null') {
            return $origin;
        }

        $referer = SiteRepository::normalizeHost((string) $request->headers->get('Referer', ''));

        return $referer !== '' && $referer !== 'null' ? $referer : null;
    }

    private function cookie(Request $request, string $name): ?string
    {
        $value = $request->cookies->get($name);

        return is_string($value) ? $value : null;
    }

    /**
     * Optional hardening flag: the tracker may send `navigator.webdriver` in the beacon.
     *
     * @param array<string, mixed> $body
     */
    private function webdriverFlag(array $body): ?bool
    {
        return array_key_exists('webdriver', $body) ? (bool) $body['webdriver'] : null;
    }

    private function countryCode(Request $request): ?string
    {
        foreach (self::COUNTRY_HEADERS as $header) {
            $value = trim((string) $request->headers->get($header, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
