<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

/**
 * Internal / excluded traffic classification: global internal CIDRs, per-site excluded IPs,
 * prefixed excluded paths and the `grq_no_track` opt-out cookie.
 *
 * The client IP is used for the decision only and is never persisted (README "Pseudonymity rules").
 */
final class TrafficClassifier
{
    public const OPTOUT_COOKIE = 'grq_no_track';

    /**
     * `device_type` marker written for stored bot hits. Reports exclude it and it can never be
     * confused with a real device bucket (mobile / tablet / desktop / unknown).
     */
    public const EXCLUDED_BOT = UserAgent::DEVICE_BOT;

    /** `device_type` marker written for stored internal hits, same idea as {@see self::EXCLUDED_BOT}. */
    public const EXCLUDED_INTERNAL = 'internal';

    /** @var list<string> Both markers, for `device_type NOT IN (…)` report filters. */
    public const EXCLUDED_DEVICES = [self::EXCLUDED_BOT, self::EXCLUDED_INTERNAL];

    /**
     * JSON props marker carried by stored bot / internal hits. Reports filter it out with
     * {@see self::EXCLUDED_PROPS_LIKE} so opted-in noise never mixes into the numbers.
     */
    public const EXCLUDED_PROP = 'excluded';

    public const EXCLUDED_PROPS_LIKE = '%"excluded":true%';

    /** Device marker for excluded traffic; an empty string means the hit is real traffic. */
    public function excludedDevice(bool $isBot, bool $isInternal): string
    {
        if ($isBot) {
            return self::EXCLUDED_BOT;
        }

        return $isInternal ? self::EXCLUDED_INTERNAL : '';
    }

    /**
     * Props merged into stored pageviews / events for excluded traffic.
     *
     * @return array<string, mixed>
     */
    public function propsMarkers(bool $isBot, bool $isInternal): array
    {
        if ($isBot) {
            return [self::EXCLUDED_PROP => true, 'reason' => self::EXCLUDED_BOT];
        }
        if ($isInternal) {
            return [self::EXCLUDED_PROP => true, 'reason' => self::EXCLUDED_INTERNAL];
        }

        return [];
    }

    /**
     * @param list<string> $internalCidrs Global `analytics.internal_ips`
     * @param list<string> $excludedIps Per-site `analytics_sites.excluded_ips`
     * @param list<string> $excludedPaths Per-site `analytics_sites.excluded_paths` (path prefixes)
     */
    public function isInternal(
        ?string $ip,
        string $path,
        array $internalCidrs,
        array $excludedIps = [],
        array $excludedPaths = [],
        ?string $optOutCookie = null,
    ): bool {
        if ($this->isOptedOut($optOutCookie)) {
            return true;
        }

        if ($excludedPaths !== [] && $this->matchesPath($path, $excludedPaths)) {
            return true;
        }

        if ($ip === null || trim($ip) === '') {
            return false;
        }

        $candidates = array_merge($internalCidrs, $excludedIps);

        return $candidates !== [] && $this->matchesIp($ip, $candidates);
    }

    /** @param list<string> $cidrs */
    public function matchesIp(string $ip, array $cidrs): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        foreach ($cidrs as $cidr) {
            if ($this->ipInCidr($ip, trim($cidr))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prefix match against a configured excluded path list.
     *
     * @param list<string> $prefixes
     */
    public function matchesPath(string $path, array $prefixes): bool
    {
        $path = '/' . ltrim($path, '/');
        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix);
            if ($prefix === '') {
                continue;
            }
            $prefix = '/' . ltrim($prefix, '/');
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Opt-out cookie: any value other than an explicit false-ish token opts the visitor out. */
    public function isOptedOut(?string $cookie): bool
    {
        if ($cookie === null) {
            return false;
        }
        $value = mb_strtolower(trim($cookie));

        return !in_array($value, ['', '0', 'false', 'off', 'no'], true);
    }

    /**
     * Single IP or CIDR match for IPv4 and IPv6 (bitwise comparison of packed addresses).
     */
    public function ipInCidr(string $ip, string $cidr): bool
    {
        if ($cidr === '') {
            return false;
        }

        if (!str_contains($cidr, '/')) {
            $singleIp = @inet_pton(trim($ip));
            $singleCidr = @inet_pton(trim($cidr));

            return $singleIp !== false && $singleCidr !== false && $singleIp === $singleCidr;
        }

        [$network, $bits] = explode('/', $cidr, 2);
        if (!ctype_digit($bits)) {
            return false;
        }
        $prefixLength = (int) $bits;

        $packedIp = @inet_pton(trim($ip));
        $packedNetwork = @inet_pton(trim($network));
        if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
            return false;
        }

        $maxBits = strlen($packedIp) * 8;
        if ($prefixLength < 0 || $prefixLength > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr($packedIp, 0, $fullBytes) !== substr($packedNetwork, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;
        $ipByte = ord($packedIp[$fullBytes]);
        $netByte = ord($packedNetwork[$fullBytes]);

        return ($ipByte & $mask) === ($netByte & $mask);
    }
}
