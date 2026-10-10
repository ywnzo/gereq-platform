<?php

declare(strict_types=1);

namespace Gereq\Http;

use Gereq\Brand\AdminFavicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Serves white-label logo/favicon from storage/uploads/brand. */
final class BrandAssetController
{
    public function __construct(
        private readonly string $basePath,
        private readonly AdminFavicon $adminFavicon,
    ) {
    }

    public function admin(Request $request): Response
    {
        $payload = $this->adminFavicon->payload();
        $response = new Response($payload['body'], 200, [
            'Content-Type' => $payload['mime'],
            'Cache-Control' => 'public, max-age=3600',
        ]);
        $response->setEtag($payload['etag']);
        $response->isNotModified($request);

        return $response;
    }

    public function show(Request $request, string $name): Response
    {
        if (preg_match('/^(logo|favicon)\.[a-z0-9]{1,8}$/i', $name) !== 1) {
            return new Response('Not found', 404);
        }

        $root = realpath($this->basePath . '/storage/uploads/brand');
        if ($root === false) {
            return new Response('Not found', 404);
        }

        $path = realpath($root . DIRECTORY_SEPARATOR . $name);
        if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return new Response('Not found', 404);
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'svg' => 'image/svg+xml',
            'gif' => 'image/gif',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => mime_content_type($path) ?: 'application/octet-stream',
        };
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Cache-Control', 'public, max-age=86400');
        // Legacy SVG uploads: force download so script in SVG does not run in the browser.
        if ($ext === 'svg') {
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $name . '"');
            $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }
        $response->setAutoEtag();
        $response->isNotModified($request);

        return $response;
    }
}
