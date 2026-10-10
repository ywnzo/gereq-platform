<?php

declare(strict_types=1);

namespace Gereq\Api\Http;

use Gereq\Api\ApiResponse;
use Gereq\Api\Auth\PublicApiKey;
use Gereq\Http\Middleware\MiddlewareInterface;
use Gereq\Http\PublicCors;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auth for /api/public/*:
 * - Bearer / X-Api-Key grq_pub_* (external clients), or
 * - first-party browser (same-origin / allowlisted Origin) without a key — like baby-naty shop API.
 *
 * Discovery files (robots / sitemap / llms / Web+AI catalog) stay open — crawlers have no API key.
 */
final class PublicApiAuthMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private const OPEN_DISCOVERY_PATHS = [
        '/api/public/webplus/robots.txt',
        '/api/public/webplus/sitemap.xml',
        '/api/public/webplus/llms.txt',
        '/api/public/webplus-ai/tools.json',
        '/api/public/webplus-ai/ai.txt',
        '/api/public/webplus-ai/llms-full.txt',
    ];

    public function __construct(private readonly PublicApiKey $publicKey)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $path = $request->getPathInfo();
        if (!PublicCors::isPublicApiPath($path)) {
            return $next($request);
        }

        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $next($request);
        }

        if (in_array($path, self::OPEN_DISCOVERY_PATHS, true)) {
            return $next($request);
        }

        if (str_starts_with($path, '/api/public/auth/') || $path === '/api/public/auth') {
            return $next($request);
        }

        if ($path === '/api/public/notifications/stream') {
            $sseToken = trim((string) $request->query->get('access_token', ''));
            if ($sseToken === '') {
                $auth = (string) $request->headers->get('Authorization', '');
                if (preg_match('/^Bearer\s+(\S+)/i', $auth, $m) === 1) {
                    $sseToken = trim($m[1]);
                }
            }
            if (\Gereq\Notifications\Application\NotificationSse::looksLikeToken($sseToken)) {
                return $next($request);
            }
        }

        if (!$this->publicKey->isConfigured()) {
            return $this->cors(
                $request,
                ApiResponse::error(
                    'misconfigured',
                    'Public API key is not configured (API_PUBLIC_KEY).',
                    [],
                    503,
                ),
            );
        }

        $provided = $this->publicKey->extractFromRequest($request);
        $keyOk = $provided !== '' && $this->publicKey->matches($provided);
        $firstParty = PublicCors::isFirstPartyRequest($request);

        if (!$keyOk && !$firstParty) {
            return $this->cors(
                $request,
                ApiResponse::unauthorized('Missing or invalid public API key.'),
            );
        }

        $request->attributes->set('_api_public_key', true);
        $request->attributes->set('_api_public_first_party', $firstParty && !$keyOk);

        return $next($request);
    }

    private function cors(Request $request, Response $response): Response
    {
        return PublicCors::apply($response, $request, 'GET, POST, PATCH, PUT, DELETE, OPTIONS');
    }
}
