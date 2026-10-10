<?php

declare(strict_types=1);

namespace Gereq\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Shared CORS for public storefront APIs — never reflect arbitrary Origin. */
final class PublicCors
{
    /** Common Vite / FE ports in local development. */
    private const DEV_PORTS = [5173, 5174, 5175, 5155, 4173, 3000];

    /**
     * @template T of Response
     * @param T $response
     * @return T
     */
    public static function apply(Response $response, ?Request $request, string $methods = 'GET, OPTIONS'): Response
    {
        $allowedRaw = trim((string) env_value('FRONTEND_ORIGIN', ''));
        $requestOrigin = trim((string) ($request?->headers->get('Origin') ?? ''));
        $debug = (bool) env_value('APP_DEBUG', false);

        if ($allowedRaw === '' || $allowedRaw === '*') {
            // Explicit wildcard only when configured; do not echo client Origin.
            $response->headers->set('Access-Control-Allow-Origin', '*');
        } else {
            $allowed = array_values(array_filter(array_map(
                static fn (string $v): string => trim($v),
                explode(',', $allowedRaw),
            )));

            $matched = null;
            if ($requestOrigin !== '' && in_array($requestOrigin, $allowed, true)) {
                $matched = $requestOrigin;
            } elseif ($requestOrigin !== '' && $debug && self::isLocalDevOrigin($requestOrigin)) {
                // Local Vite often bumps the port (5173 → 5174); allow private-network FE in debug.
                $matched = $requestOrigin;
            }

            if ($matched !== null) {
                $response->headers->set('Access-Control-Allow-Origin', $matched);
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Vary', 'Origin');
            }
            // Do not fall back to a mismatched origin — browsers reject it and it confuses debugging.
        }

        $response->headers->set('Access-Control-Allow-Methods', $methods);
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Api-Key');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }

    public static function isPublicApiPath(string $path): bool
    {
        return str_starts_with($path, '/api/public/') || $path === '/api/public';
    }

    /**
     * Browser storefront with an allowlisted Origin/Referer — no public API key required.
     * Never trust Sec-Fetch-Site alone (any HTTP client can spoof it).
     * Bare curl / scripts without allowlisted Origin/Referer still need Bearer grq_pub_*.
     */
    public static function isFirstPartyRequest(Request $request): bool
    {
        $origin = trim((string) $request->headers->get('Origin', ''));
        if ($origin !== '' && self::originAllowed($origin)) {
            return true;
        }

        // Some proxies drop Origin; Referer still identifies the browser storefront.
        $referer = trim((string) $request->headers->get('Referer', ''));
        if ($referer !== '') {
            $parts = parse_url($referer);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                $refOrigin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']) . $port;
                if (self::originAllowed($refOrigin)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function originAllowed(string $origin): bool
    {
        foreach (self::allowedOrigins() as $allowed) {
            if (strcasecmp($origin, $allowed) === 0) {
                return true;
            }
        }

        $debug = (bool) env_value('APP_DEBUG', false);
        if ($debug && self::isLocalDevOrigin($origin)) {
            return true;
        }

        return false;
    }

    /** @return list<string> */
    public static function allowedOrigins(): array
    {
        $raw = trim((string) env_value('FRONTEND_ORIGIN', ''));
        $list = [];
        if ($raw !== '' && $raw !== '*') {
            foreach (explode(',', $raw) as $part) {
                $origin = trim($part);
                if ($origin !== '') {
                    $list[] = $origin;
                }
            }
        }

        $appUrl = trim((string) env_value('APP_URL', ''));
        if ($appUrl !== '') {
            if (!preg_match('#^https?://#i', $appUrl)) {
                $appUrl = 'http://' . $appUrl;
            }
            $parts = parse_url($appUrl);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                $list[] = strtolower($parts['scheme']) . '://' . strtolower((string) $parts['host']) . $port;
            }
        }

        return array_values(array_unique($list));
    }

    private static function isLocalDevOrigin(string $origin): bool
    {
        $parts = parse_url($origin);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if (!in_array($port, self::DEV_PORTS, true)) {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '[::1]', '::1'], true)) {
            return true;
        }

        // Private LAN (RFC1918) — FE opened via VM IP from Windows host.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPrivateIp($host);
        }

        return false;
    }

    private static function isPrivateIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        $ranges = [
            ['10.0.0.0', '10.255.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
        ];

        foreach ($ranges as [$from, $to]) {
            if ($long >= ip2long($from) && $long <= ip2long($to)) {
                return true;
            }
        }

        return false;
    }
}
