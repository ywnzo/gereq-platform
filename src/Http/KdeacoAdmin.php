<?php

declare(strict_types=1);

namespace Gereq\Http;

use Gereq\Security\ContentSecurityPolicy;
use Gereq\Support\AdminPath;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Serves the Vite Vue product admin from public/kdeaco-admin under /admin. */
final class KdeacoAdmin
{
    public static function root(): string
    {
        return dirname(__DIR__, 2) . '/public/kdeaco-admin';
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

        $relative = self::staticRelative($request->getPathInfo());
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
        if (!self::exists() || !AdminPath::productMatches($request->getPathInfo())) {
            return null;
        }
        // Platform Twig still owns /admin when ADMIN_PATH has not been relocated.
        if (AdminPath::base() === '/' . AdminPath::CANONICAL) {
            return null;
        }

        $html = (string) file_get_contents(self::root() . '/index.html');

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            ContentSecurityPolicy::HEADER_OVERRIDE => self::csp(),
        ]);
    }

    private static function staticRelative(string $path): ?string
    {
        // Vite base=/admin/ → /admin/assets/…
        if (str_starts_with($path, '/admin/assets/')) {
            return 'assets/' . substr($path, strlen('/admin/assets/'));
        }
        // Root public files copied into build (favicon, logo).
        if (preg_match('#^/admin/(favicon\.(?:png|svg|ico)|kdeaco-logo\.png|icons\.svg)$#', $path, $m) === 1) {
            return $m[1];
        }
        // Optional direct public/kdeaco-admin access
        if (str_starts_with($path, '/kdeaco-admin/')) {
            return substr($path, strlen('/kdeaco-admin/'));
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
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff', 'woff2' => 'font/woff2',
            default => (string) (mime_content_type($path) ?: 'application/octet-stream'),
        };
    }

    private static function csp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob: https:",
            "connect-src 'self'",
            "worker-src 'self' blob:",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
            "upgrade-insecure-requests",
        ]);
    }
}
