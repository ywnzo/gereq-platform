<?php

declare(strict_types=1);

namespace Gereq\Webhooks\Application;

use Gereq\Config\Repository;

final class SsrfGuard
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function assertSafe(string $url): void
    {
        $this->pin($url);
    }

    /**
     * Resolve and validate destination IP; caller must dial the pinned IP and set Host.
     */
    public function pin(string $url): PinnedUrl
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Neplatná URL webhooku.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Webhook URL musí používat HTTP nebo HTTPS.');
        }

        $host = strtolower((string) $parts['host']);
        $host = trim($host, '[]');
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        $allowPrivate = (bool) $this->config->get('api.webhooks.allow_private_urls', false);

        if (!$allowPrivate && $this->isBlockedHost($host)) {
            throw new \InvalidArgumentException('Webhook URL míří na zakázaný hostitel.');
        }

        $ips = $this->resolveIps($host);
        $safeIp = null;
        foreach ($ips as $ip) {
            if ($allowPrivate || !$this->isBlockedIp($ip)) {
                $safeIp = $ip;
                break;
            }
        }
        if ($safeIp === null) {
            throw new \InvalidArgumentException('Webhook URL míří na privátní nebo lokální síť.');
        }

        return new PinnedUrl($url, $scheme, $host, $port, $path, $safeIp);
    }

    private function isBlockedHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local');
    }

    /** @return list<string> */
    private function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        $fallback = @gethostbynamel($host);
        if (is_array($fallback)) {
            foreach ($fallback as $ip) {
                $ips[] = $ip;
            }
        }

        $ips = array_values(array_unique($ips));
        if ($ips === []) {
            throw new \InvalidArgumentException('Nelze přeložit hostname webhook URL.');
        }

        return $ips;
    }

    private function isBlockedIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->isBlockedIpv4($ip);
        }

        return $this->isBlockedIpv6($ip);
    }

    private function isBlockedIpv4(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }

        $ranges = [
            ['0.0.0.0', '0.255.255.255'],
            ['127.0.0.0', '127.255.255.255'],
            ['10.0.0.0', '10.255.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['169.254.0.0', '169.254.255.255'],
        ];

        foreach ($ranges as [$start, $end]) {
            $startLong = ip2long($start);
            $endLong = ip2long($end);
            if ($startLong !== false && $endLong !== false && $long >= $startLong && $long <= $endLong) {
                return true;
            }
        }

        return false;
    }

    private function isBlockedIpv6(string $ip): bool
    {
        $normalized = strtolower($ip);
        if ($normalized === '::1' || $normalized === '::') {
            return true;
        }

        if (
            str_starts_with($normalized, 'fe8')
            || str_starts_with($normalized, 'fe9')
            || str_starts_with($normalized, 'fea')
            || str_starts_with($normalized, 'feb')
            || str_starts_with($normalized, 'fc')
            || str_starts_with($normalized, 'fd')
        ) {
            return true;
        }

        if (str_starts_with($normalized, '::ffff:')) {
            $v4 = substr($normalized, 7);
            if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $this->isBlockedIpv4($v4);
            }
        }

        return false;
    }
}
