<?php

declare(strict_types=1);

namespace Gereq\Support;

/**
 * Builds Gravatar avatar URLs from an e-mail address.
 */
final class Gravatar
{
    public const DEFAULT_BLANK = 'blank';
    public const DEFAULT_IDENTICON = 'identicon';
    public const DEFAULT_MP = 'mp';

    /**
     * @param non-empty-string $default Gravatar default image keyword or absolute URL
     */
    public static function url(?string $email, int $size = 80, string $default = self::DEFAULT_IDENTICON): string
    {
        $size = max(1, min(2048, $size));
        $hash = self::hash($email);

        $query = http_build_query([
            's' => $size,
            'd' => $default,
            'r' => 'g',
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://www.gravatar.com/avatar/' . $hash . '?' . $query;
    }

    public static function hash(?string $email): string
    {
        $normalized = strtolower(trim((string) $email));

        return md5($normalized);
    }
}
