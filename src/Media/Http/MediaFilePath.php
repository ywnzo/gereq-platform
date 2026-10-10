<?php

declare(strict_types=1);

namespace Gereq\Media\Http;

/** Matches public and admin binary file routes served by MediaController. */
final class MediaFilePath
{
    public static function matches(string $path): bool
    {
        return (bool) preg_match('#^/(?:admin/)?media/[0-9a-fA-F-]{8,36}/file$#', $path);
    }

    public static function fromGlobals(): bool
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '') {
            return false;
        }
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) && self::matches($path);
    }
}
