<?php

declare(strict_types=1);

namespace Gereq\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Shared CORS for public storefront APIs — never reflect arbitrary Origin. */
final class PublicCors
{
    /** Common Vite / FE ports in local development. */
    private const DEV_PORTS = [5173, 5174, 5175, 4173, 3000];

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
     * Browser storefront on the same site (or allowlisted Origin) — no public API key required.
     * Bare curl / scripts without Origin / Sec-Fetch-Site / Referer still need Bearer grq_pub_*.
     *
     * Same-origin GETs often omit Origin. Chromium on non-loopback hosts (LAN IP) may also omit
     * Sec-Fetch-Site when the page is opened via http://192.168.x.x:5174 — fall back to Referer.
     */
    public static function isFirstPartyRequest(Request $request): bool
    {
        $site = strtolower(trim((string) $request->headers->get('Sec-Fetch-Site', '')));
        if (in_array($site, ['same-origin', 'same-site'], true)) {
            return true;
        }

        $candidates = [];
        $origin = trim((string) $request->headers->get('Origin', ''));
        if ($origin !== '') {
            $candidates[] = $origin;
        }

        $referer = trim((string) $request->headers->get('Referer', ''));
        if ($referer !== '') {
            $fromReferer = self::originFromUrl($referer);
            if ($fromReferer !== null) {
                $candidates[] = $fromReferer;
            }
        }

        if ($candidates === []) {
            return false;
        }

        $allowed = self::allowedOrigins();
        $debug = (bool) env_value('APP_DEBUG', false);

        foreach ($candidates as $candidate) {
            foreach ($allowed as $entry) {
                if (strcasecmp($candidate, $entry) === 0) {
                    return true;
                }
            }
            if ($debug && self::isLocalDevOrigin($candidate)) {
                return true;
            }
        }

        return false;
    }

    /** @return non-empty-string|null */
    private static function originFromUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
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
