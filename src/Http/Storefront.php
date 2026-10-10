<?php

declare(strict_types=1);

namespace Gereq\Http;

use Gereq\Security\ContentSecurityPolicy;
use Gereq\Support\AdminPath;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Serves the Vite React storefront from public/storefront on the same HTTPS origin. */
final class Storefront
{
    private const SKIP_PREFIX = [
        '/api/', '/build/', '/media/', '/brand/', '/tickets/', '/security/', '/kdeaco-admin/',
    ];

    public static function root(): string
    {
        return dirname(__DIR__, 2) . '/public/storefront';
    }

    public static function exists(): bool
    {
        return is_file(self::root() . '/index.html');
    }

    public static function staticFile(Request $request): ?Response
    {
        if (!in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) {
            return null;
        }

        $path = $request->getPathInfo();
        if ($path === '/dev-ca') {
            $html = dirname(self::root()) . '/dev-ca.html';
            if (!is_file($html)) {
                return null;
            }

            return new Response((string) file_get_contents($html), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        }
        if ($path === '/dev-ca.cer' || $path === '/dev-ca.crt') {
            $file = dirname(self::root()) . $path;
            if (!is_file($file)) {
                return null;
            }
            $ext = $path === '/dev-ca.cer' ? 'cer' : 'crt';
            $response = new BinaryFileResponse($file);
            $response->headers->set('Content-Type', 'application/x-x509-ca-cert');
            $response->headers->set('Content-Disposition', 'attachment; filename="gereq-dev-rootCA.' . $ext . '"');
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        $relative = self::staticRelative($path);
        if ($relative === null) {
            return null;
        }

        $root = realpath(self::root());
        if ($root === false) {
            return null;
        }
        $full = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($full === false || !str_starts_with($full, $root . DIRECTORY_SEPARATOR) || !is_file($full)) {
            return null;
        }

        $response = new BinaryFileResponse($full);
        $response->headers->set('Content-Type', self::mime($full));
        $response->headers->set('Cache-Control', str_contains($relative, 'assets/') ? 'public, max-age=2592000, immutable' : 'public, max-age=86400');
        $response->setAutoEtag();
        $response->isNotModified($request);

        return $response;
    }

    public static function html(Request $request): ?Response
    {
        if (!in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) {
            return null;
        }
        if (!self::exists() || !self::isSpaPath($request->getPathInfo())) {
            return null;
        }

        $html = (string) file_get_contents(self::root() . '/index.html');
        $response = new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            ContentSecurityPolicy::HEADER_OVERRIDE => self::csp(),
        ]);

        return $response;
    }

    public static function isSpaPath(string $path): bool
    {
        if ($path === '') {
            $path = '/';
        }
        if (AdminPath::matches($path) || AdminPath::productMatches($path)) {
            return false;
        }
        foreach (self::SKIP_PREFIX as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }
        if ($path !== '/' && preg_match('/\.[a-z0-9]{1,8}$/i', $path) === 1) {
            return false;
        }

        return true;
    }

    private static function staticRelative(string $path): ?string
    {
        if (str_starts_with($path, '/assets/')) {
            return ltrim($path, '/');
        }
        $name = basename($path);
        if (in_array($name, ['favicon.gif', 'favicon.png', 'favicon.svg', 'favicon.ico', 'og.png'], true)
            && ($path === '/' . $name)) {
            return $name;
        }

        return null;
    }

    private static function mime(string $path): string
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'js', 'mjs' => 'application/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'map' => 'application/json',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
            'gif' => 'image/gif',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'crt' => 'application/x-x509-ca-cert',
            default => (string) (mime_content_type($path) ?: 'application/octet-stream'),
        };
    }

    private static function csp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            // gereq.cz — GEREQ Security embed loaded by the storefront FE
            "script-src 'self' https://gereq.cz",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com https://fonts.googleapis.com data:",
            "img-src 'self' data: blob: https:",
            "connect-src 'self' https://gereq.cz",
            "media-src 'self' blob:",
            // CMS video embeds (allowlisted hosts on the FE too) + GEREQ Security/Support frames.
            "frame-src https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com https://gereq.cz",
            "child-src 'none'",
            "worker-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
            "upgrade-insecure-requests",
        ]);
    }
}
