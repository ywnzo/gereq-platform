<?php

declare(strict_types=1);

namespace Modules\Gereq\Application\Security;

use Gereq\Cache\AppCache;
use Gereq\Config\Repository;
use Modules\Gereq\Domain\SecuritySite;
use Symfony\Component\HttpFoundation\Request;

final class FormPassService
{
    public const TTL_SECONDS = 900;
    public const RATE_LIMIT = 8;
    public const RATE_WINDOW_SECONDS = 600;

    public function __construct(
        private readonly AppCache $cache,
        private readonly Repository $config,
    ) {
    }

    public function issue(SecuritySite $site, string $visitor, Request $request): ?string
    {
        if ($visitor === '') {
            return null;
        }
        $ip = (string) $request->getClientIp();
        $rateKey = $this->rateKey($site, $ip);
        $issued = (int) $this->cache->get($rateKey, 0);
        if ($issued >= self::RATE_LIMIT) {
            return null;
        }
        $token = ($site->usingTestKey() ? 'fp_test_' : 'fp_') . bin2hex(random_bytes(20));
        $this->cache->put($this->cacheKey($site, $visitor, $token), [
            'ip_hash' => $this->ipHash($ip),
        ], self::TTL_SECONDS);
        $this->cache->put($rateKey, $issued + 1, self::RATE_WINDOW_SECONDS);

        return $token;
    }

    public function consume(SecuritySite $site, string $visitor, string $token, ?string $clientIp = null): bool
    {
        if ($visitor === '' || $token === '') {
            return false;
        }
        $test = str_starts_with($token, 'fp_test_');
        if (!$test && !str_starts_with($token, 'fp_')) {
            return false;
        }
        $key = $this->cacheKey($site, $visitor, $token);
        $payload = $this->cache->get($key);
        if ($payload !== true && !is_array($payload)) {
            return false;
        }
        $expectedIpHash = is_array($payload) ? ($payload['ip_hash'] ?? null) : null;
        if (is_string($expectedIpHash) && $expectedIpHash !== '') {
            if (!is_string($clientIp) || $clientIp === '') {
                return false;
            }
            if (!hash_equals($expectedIpHash, $this->ipHash($clientIp))) {
                return false;
            }
        }
        $this->cache->forget($key);

        return true;
    }

    private function cacheKey(SecuritySite $site, string $visitor, string $token): string
    {
        return 'gereq:form-pass:' . $site->id() . ':' . ($site->usingTestKey() ? 'test:' : 'live:') . hash('sha256', $visitor . '|' . $token);
    }

    private function rateKey(SecuritySite $site, string $ip): string
    {
        return 'gereq:form-pass-rate:' . $site->id() . ':' . ($site->usingTestKey() ? 'test:' : 'live:') . $this->ipHash($ip);
    }

    private function ipHash(string $ip): string
    {
        return hash('sha256', $ip . '|' . (string) $this->config->get('app.key', ''));
    }
}
