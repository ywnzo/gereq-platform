<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Modules\Analytics\Application\BotClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure bot heuristic (README "Bot and internal traffic").
 *
 * Guards the two real regressions this classifier fixes: brand-glued crawler agents such as
 * `Googlebot/2.1` and device model names such as `CUBOT_X20` (both contain "bot" as a substring).
 */
final class BotClassifierTest extends TestCase
{
    private BotClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new BotClassifier();
    }

    /** @return array<string, array{0: string}> */
    public static function crawlerUserAgents(): array
    {
        return [
            'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'googlebot-uppercase' => ['Mozilla/5.0 (compatible; GOOGLEBOT/2.1; +http://www.google.com/bot.html)'],
            'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            'yandexbot' => ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)'],
            'ahrefsbot' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'],
            'semrushbot' => ['Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)'],
            'gptbot' => ['Mozilla/5.0 AppleWebKit/537.36 (compatible; GPTBot/1.0; +https://openai.com/gptbot)'],
            'generic-spider-token' => ['Mozilla/5.0 (compatible; Some Research Spider)'],
            'curl' => ['curl/8.4.0'],
            'python-requests' => ['python-requests/2.31.0'],
            'empty-ish-word-boundary' => ['Feedfetcher-Google; (+http://www.google.com/feedfetcher.html)'],
        ];
    }

    #[DataProvider('crawlerUserAgents')]
    public function testKnownCrawlerUserAgentsAreBots(string $userAgent): void
    {
        self::assertTrue($this->classifier->isBot($userAgent, false, ''));
    }

    public function testBrandGluedToBotVersionIsRecognised(): void
    {
        // Real crawlers send the version glued to the brand ("SomeBot/1.2"); the whole-token
        // pattern cannot see it because "bot" has a letter in front and a "/" behind.
        self::assertTrue($this->classifier->isBot('SomeBrandBot/1.2', false, ''));
        self::assertTrue($this->classifier->isBot('SomeBrandBot;', false, ''));
        self::assertTrue($this->classifier->isBot('SomeBrandBot)', false, ''));
    }

    public function testDeviceModelNameContainingBotSubstringIsNotABot(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 10; CUBOT_X20) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/120.0.0.0 Mobile Safari/537.36';

        self::assertFalse($this->classifier->isBot($ua, false, ''));
        // "robot" and "CUBOT X20" (brand + space) must not trigger the generic "bot" token either.
        self::assertFalse($this->classifier->isBot('Mozilla/5.0 robotframework/6.0', false, ''));
        self::assertFalse($this->classifier->isBot('Mozilla/5.0 (Linux; Android 10; CUBOT X20)', false, ''));
    }

    /** @return array<string, array{0: string}> */
    public static function realisticBrowserUserAgents(): array
    {
        return [
            'chrome-macos' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/120.0.0.0 Safari/537.36',
            ],
            'safari-macos' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'Version/17.1 Safari/605.1.15',
            ],
            'firefox-windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
            ],
            'chrome-android' => [
                'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/119.0.0.0 Mobile Safari/537.36',
            ],
            'safari-iphone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'Version/17.0 Mobile/15E148 Safari/604.1',
            ],
            'edge-windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
            ],
        ];
    }

    #[DataProvider('realisticBrowserUserAgents')]
    public function testRealisticBrowserUserAgentsAreNotBots(string $userAgent): void
    {
        self::assertFalse($this->classifier->isBot($userAgent, false, ''));
    }

    public function testWebdriverFlagIsABot(): void
    {
        $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/120.0.0.0 Safari/537.36';

        self::assertTrue($this->classifier->isBot($chrome, true, ''));
        self::assertFalse($this->classifier->isBot($chrome, false, ''));
        self::assertFalse($this->classifier->isBot($chrome, null, ''));
    }

    public function testHeadlessAgentMarkersAreBots(): void
    {
        self::assertTrue($this->classifier->isBot('HeadlessChrome/120.0.0.0', false, ''));
        self::assertTrue($this->classifier->isBot('Selenium/4.16.0', false, ''));
        self::assertTrue($this->classifier->isBot('Chrome-Lighthouse', false, ''));
    }

    public function testEmptyOrMissingUserAgentIsNotABot(): void
    {
        self::assertFalse($this->classifier->isBot(null, false, ''));
        self::assertFalse($this->classifier->isBot('', false, ''));
        self::assertFalse($this->classifier->isBot('   ', false, ''));
        self::assertFalse($this->classifier->isBot(null, null, ''));
    }

    public function testFilledHoneypotFlagsABotEvenWithANormalUserAgent(): void
    {
        $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/120.0.0.0 Safari/537.36';

        self::assertTrue($this->classifier->isBot($chrome, false, 'http://spam.example'));
        self::assertFalse($this->classifier->isBot($chrome, false, ''));
        // Whitespace-only is not a filled form field.
        self::assertFalse($this->classifier->isBot($chrome, false, "  \t "));
    }

    public function testHoneypotFromReadsBothDocumentedFieldNames(): void
    {
        self::assertSame('http://spam', BotClassifier::honeypotFrom(['website_url' => '  http://spam ']));
        self::assertSame('acme', BotClassifier::honeypotFrom(['website' => 'acme']));
        // website_url wins; an empty website_url falls through to website.
        self::assertSame(
            'http://spam',
            BotClassifier::honeypotFrom(['website_url' => 'http://spam', 'website' => 'acme']),
        );
        self::assertSame('acme', BotClassifier::honeypotFrom(['website_url' => '', 'website' => 'acme']));
        self::assertSame('', BotClassifier::honeypotFrom([]));
        self::assertSame('', BotClassifier::honeypotFrom(['website' => '   ']));
    }
}
