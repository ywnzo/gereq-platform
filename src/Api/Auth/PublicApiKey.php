<?php

declare(strict_types=1);

namespace Gereq\Api\Auth;

use Gereq\Config\Repository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Site-level public API key (grq_pub_*).
 * Required for /api/public/* — callable from external FE origins, not anonymous.
 */
final class PublicApiKey
{
    public const PREFIX = 'grq_pub_';

    public function __construct(private readonly Repository $config)
    {
    }

    public static function generate(): string
    {
        return self::PREFIX . bin2hex(random_bytes(24));
    }

    public function configured(): string
    {
        return trim((string) $this->config->get('api.public_key', ''));
    }

    public function isConfigured(): bool
    {
        $key = $this->configured();

        return $key !== '' && str_starts_with($key, self::PREFIX);
    }

    public function matches(string $provided): bool
    {
        $expected = $this->configured();
        if ($expected === '' || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    public function extractFromRequest(Request $request): string
    {
        // Prefer dedicated header so storefront can send store JWT in Authorization.
        $headerKey = trim((string) $request->headers->get('X-Api-Key', ''));
        if ($headerKey !== '') {
            return $headerKey;
        }

        $header = (string) $request->headers->get('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)/i', $header, $m)) {
            $token = trim($m[1]);
            // Ignore customer/admin bearer tokens — only grq_pub_* is the site key.
            if (str_starts_with($token, self::PREFIX)) {
                return $token;
            }
        }

        return '';
    }

    public function masked(): string
    {
        $key = $this->configured();
        if ($key === '') {
            return '';
        }
        if (strlen($key) <= 16) {
            return self::PREFIX . '…';
        }

        return substr($key, 0, strlen(self::PREFIX) + 4) . '…' . substr($key, -4);
    }
}
