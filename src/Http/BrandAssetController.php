<?php

declare(strict_types=1);

namespace Gereq\Http;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Serves white-label logo/favicon from storage/uploads/brand. */
final class BrandAssetController
{
    public function __construct(private readonly string $basePath)
    {
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

        $mime = mime_content_type($path) ?: 'application/octet-stream';
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Cache-Control', 'public, max-age=86400');
        $response->setAutoEtag();
        $response->isNotModified($request);

        return $response;
    }
}
