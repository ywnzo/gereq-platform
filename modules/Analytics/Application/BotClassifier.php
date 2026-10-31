<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

/**
 * Pure bot heuristic — no I/O, no state.
 *
 * A hit is classified as a bot when the user agent matches a known crawler / agent pattern,
 * the browser reports `navigator.webdriver`, or a honeypot field was filled by the client.
 * An empty user agent is not treated as a bot (some privacy tooling strips it).
 */
final class BotClassifier
{
    /**
     * Generic crawler words. Matched as whole tokens so device model names such as
     * "CUBOT_X20" are not swallowed by the "bot" substring.
     */
    private const GENERIC_PATTERN = '~(?:^|[^a-z])(bot|crawler|crawling|spider|slurp|scraper)(?:[^a-z]|$)~';

    /**
     * Versioned agent form `SomeBot/1.2`, `SomeBot;`, `SomeBot)` — real crawlers send
     * `Googlebot/2.1`, never a bare word, and the token pattern above cannot see it because
     * "bot" is glued to the vendor name. A brand followed by a space is deliberately NOT matched
     * here (device model names look like `CUBOT X20`); those brands are listed below instead.
     */
    private const SUFFIX_BOT_PATTERN = '~[a-z0-9_-]bot[\/;)]~';

    /** Unambiguous agent substrings (brands, libraries, monitoring and AI crawlers). */
    private const UA_PATTERNS = [
        'googlebot', 'google-inspectiontool', 'googleother', 'apis-google', 'storebot-google',
        'adsbot', 'mediapartners', 'google-read-aloud', 'google-safety', 'googleweblight',
        'bingbot', 'bingpreview', 'adidxbot', 'msnbot', 'yandex', 'seznam',
        'baiduspider', 'bytespider', 'petalbot', 'applebot', 'duckduckbot', 'slurp',
        'naver', 'qwantify', 'coccocbot', 'sogou', 'exabot', 'gigabot', 'ia_archiver',
        'headless', 'phantomjs', 'puppeteer', 'playwright', 'electron/',
        'lighthouse', 'pagespeed', 'gtmetrix', 'pingdom', 'uptimerobot', 'site24x7',
        'curl/', 'wget/', 'python', 'aiohttp', 'httpx/', 'node-fetch', 'undici', 'guzzle',
        'go-http-client', 'okhttp', 'java/', 'libwww', 'httpclient', 'postmanruntime', 'axios/',
        'ahrefs', 'semrush', 'majestic', 'dotbot', 'mj12', 'blexbot', 'serpstat', 'screaming frog',
        'facebookexternalhit', 'twitterbot', 'linkedinbot', 'whatsapp', 'telegrambot', 'slackbot',
        'discordbot', 'pinterest', 'redditbot', 'embedly', 'vkshare', 'skypeuripreview',
        'gptbot', 'ccbot', 'claudebot', 'anthropic-ai', 'google-extended', 'perplexitybot',
        'amazonbot', 'omgili', 'commoncrawl',
        'preview', 'validator', 'feedfetcher', 'monitoring',
    ];

    /** Headless-agent markers that rarely appear next to a "bot" word. */
    private const HEADLESS_MARKERS = [
        'headlesschrome', 'headless firefox', 'headless_firefox', 'selenium', 'webdriver',
        'webdrivermanager', 'chrome-lighthouse', 'chrome-aws-lambda',
    ];

    /** Honeypot form fields — a non-empty value means an automated submit. */
    private const HONEYPOT_FIELDS = ['website_url', 'website'];

    public function isBot(?string $userAgent, ?bool $webdriver, string $honeypot): bool
    {
        if (trim($honeypot) !== '') {
            return true;
        }

        if ($webdriver === true) {
            return true;
        }

        $ua = mb_strtolower(trim((string) $userAgent));
        if ($ua === '') {
            return false;
        }

        foreach (self::HEADLESS_MARKERS as $marker) {
            if (str_contains($ua, $marker)) {
                return true;
            }
        }

        if (preg_match(self::GENERIC_PATTERN, $ua) === 1) {
            return true;
        }

        if (preg_match(self::SUFFIX_BOT_PATTERN, $ua) === 1) {
            return true;
        }

        foreach (self::UA_PATTERNS as $pattern) {
            if (str_contains($ua, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Honeypot value from an already parsed payload.
     *
     * @param array<string, mixed> $payload
     */
    public static function honeypotFrom(array $payload): string
    {
        foreach (self::HONEYPOT_FIELDS as $field) {
            $value = trim((string) ($payload[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
