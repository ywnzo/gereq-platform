<?php

declare(strict_types=1);

namespace Modules\Webhooks\Application;

use Gereq\Config\Repository;

final class SsrfGuard
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Neplatná URL webhooku.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Webhook URL musí používat HTTP nebo HTTPS.');
        }

        if ((bool) $this->config->get('api.webhooks.allow_private_urls', false)) {
            return;
        }

        $host = strtolower((string) $parts['host']);
        $host = trim($host, '[]');

        if ($this->isBlockedHost($host)) {
            throw new \InvalidArgumentException('Webhook URL míří na zakázaný hostitel.');
        }

        foreach ($this->resolveIps($host) as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new \InvalidArgumentException('Webhook URL míří na privátní nebo lokální síť.');
            }
        }
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

        // 0.0.0.0/8, 127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16
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

        // fe80::/10 link-local, fc00::/7 unique local, ::ffff:127.0.0.1 etc.
        if (str_starts_with($normalized, 'fe8')
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
