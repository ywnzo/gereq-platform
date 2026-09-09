<?php

declare(strict_types=1);

namespace Modules\Emailing\Application;

use Gereq\Cache\AppCache;

/**
 * BIMI (Brand Indicators for Message Identification) — logo z DNS TXT default._bimi.{domain}.
 *
 * @see https://datatracker.ietf.org/doc/html/draft-brand-indicators-for-message-identification
 */
final class BimiLogoResolver
{
    private const CACHE_TTL = 21600; // 6 h

    /** @var array<string, string|null> */
    private array $memory = [];

    public function __construct(private readonly AppCache $cache)
    {
    }

    public function logoUrlForEmail(?string $email): ?string
    {
        $domain = self::domainFromEmail($email);
        if ($domain === null) {
            return null;
        }

        return $this->logoUrlForDomain($domain);
    }

    /**
     * @param list<?string> $emails
     * @return array<string, string> email-domain => https logo URL
     */
    public function preload(array $emails): array
    {
        $out = [];
        foreach ($emails as $email) {
            $domain = self::domainFromEmail($email);
            if ($domain === null) {
                continue;
            }
            $url = $this->logoUrlForDomain($domain);
            if ($url !== null) {
                $out[$domain] = $url;
            }
        }

        return $out;
    }

    public function logoUrlForDomain(string $domain): ?string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if ($domain === '' || !str_contains($domain, '.')) {
            return null;
        }

        if (array_key_exists($domain, $this->memory)) {
            return $this->memory[$domain];
        }

        $cached = $this->cache->remember(
            'emailing.bimi.' . $domain,
            function () use ($domain): string {
                return $this->lookup($domain) ?? '';
            },
            self::CACHE_TTL,
        );

        $url = is_string($cached) && $cached !== '' ? $cached : null;
        $this->memory[$domain] = $url;

        return $url;
    }

    public static function domainFromEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }
        $domain = strtolower(trim((string) substr($email, (int) strrpos($email, '@') + 1), "<> \t\"'"));
        $domain = rtrim($domain, '.>');
        if ($domain === '' || !str_contains($domain, '.') || str_contains($domain, ' ')) {
            return null;
        }

        return $domain;
    }

    private function lookup(string $domain): ?string
    {
        $url = $this->queryBimiTxt($domain);
        if ($url !== null) {
            return $url;
        }

        // Organizational domain: mail.example.cz → example.cz (ne pro víceúrovňové TLD).
        $parts = explode('.', $domain);
        if (count($parts) > 2) {
            $parent = implode('.', array_slice($parts, -2));
            if ($parent !== $domain) {
                return $this->queryBimiTxt($parent);
            }
        }

        return null;
    }

    private function queryBimiTxt(string $domain): ?string
    {
        $host = 'default._bimi.' . $domain;
        $records = @dns_get_record($host, DNS_TXT);
        if (!is_array($records) || $records === []) {
            return null;
        }

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $txt = $record['txt'] ?? '';
            if (is_array($txt)) {
                $txt = implode('', $txt);
            }
            $url = self::parseLogoUrl((string) $txt);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    public static function parseLogoUrl(string $txt): ?string
    {
        $txt = trim($txt);
        $txt = trim($txt, "\"'");
        if ($txt === '') {
            return null;
        }
        if (!preg_match('/(?:^|;)\s*v\s*=\s*BIMI1\b/i', $txt)) {
            return null;
        }
        if (!preg_match('/(?:^|;)\s*l\s*=\s*([^;]*)/i', $txt, $m)) {
            return null;
        }
        $url = trim($m[1], " \t\"'");
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^https://[^\s<>\"\']+#i', $url)) {
            return null;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }
}
