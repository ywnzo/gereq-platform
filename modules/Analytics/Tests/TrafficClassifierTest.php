<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Modules\Analytics\Application\TrafficClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Internal / excluded traffic classification (README "Bot and internal traffic").
 */
final class TrafficClassifierTest extends TestCase
{
    private TrafficClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new TrafficClassifier();
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> [ip, cidr, expected] */
    public static function cidrCases(): array
    {
        return [
            'ipv4-/8-inside' => ['10.1.2.3', '10.0.0.0/8', true],
            'ipv4-/8-outside' => ['11.0.0.1', '10.0.0.0/8', false],
            'ipv4-/24-inside-boundary' => ['192.168.1.255', '192.168.1.0/24', true],
            'ipv4-/24-outside' => ['192.168.2.0', '192.168.1.0/24', false],
            'ipv4-/32-exact' => ['203.0.113.7', '203.0.113.7/32', true],
            'ipv4-/32-other' => ['203.0.113.8', '203.0.113.7/32', false],
            'ipv4-/0-matches-everything' => ['8.8.8.8', '0.0.0.0/0', true],
            'ipv4-/25-low-boundary' => ['192.168.1.127', '192.168.1.128/25', false],
            'ipv4-/25-high-boundary' => ['192.168.1.128', '192.168.1.128/25', true],
            'ipv6-/32-inside' => ['2001:db8::1', '2001:db8::/32', true],
            'ipv6-/32-outside' => ['2001:db9::1', '2001:db8::/32', false],
            'ipv6-/128-exact' => ['2001:db8::1', '2001:db8::1/128', true],
            'ipv6-/128-other' => ['2001:db8::2', '2001:db8::1/128', false],
            'single-ipv4-match' => ['10.0.0.1', '10.0.0.1', true],
            'single-ipv4-different' => ['10.0.0.2', '10.0.0.1', false],
            'family-mismatch' => ['10.0.0.1', '::/0', false],
            'invalid-prefix-length' => ['10.0.0.1', '10.0.0.0/33', false],
            'non-numeric-prefix' => ['10.0.0.1', '10.0.0.0/abc', false],
            'empty-cidr' => ['10.0.0.1', '', false],
        ];
    }

    #[DataProvider('cidrCases')]
    public function testIpInCidr(string $ip, string $cidr, bool $expected): void
    {
        self::assertSame($expected, $this->classifier->ipInCidr($ip, $cidr));
    }

    public function testMatchesIpScansTheWholeListAndTrims(): void
    {
        self::assertTrue($this->classifier->matchesIp(' 10.0.0.5 ', [' 10.0.0.0/8 ', '192.168.0.0/16']));
        self::assertFalse($this->classifier->matchesIp('172.16.0.1', ['10.0.0.0/8', '192.168.0.0/16']));
        self::assertFalse($this->classifier->matchesIp('', ['10.0.0.0/8']));
        self::assertFalse($this->classifier->matchesIp('10.0.0.1', []));
    }

    /** @return array<string, array{0: string, 1: list<string>, 2: bool}> */
    public static function pathCases(): array
    {
        return [
            'exact-prefix' => ['/admin', ['/admin'], true],
            'nested' => ['/admin/users/42', ['/admin'], true],
            'prefix-semantics-not-segment' => ['/administrator', ['/admin'], true],
            'no-match' => ['/public', ['/admin'], false],
            'missing-leading-slash-on-path' => ['admin/x', ['/admin'], true],
            'missing-leading-slash-on-prefix' => ['/admin/x', ['admin'], true],
            'trailing-slash-prefix' => ['/admin/x', ['/admin/'], true],
            'trailing-slash-prefix-exact-path' => ['/admin', ['/admin/'], false],
            'empty-prefix-ignored' => ['/admin', ['', '  '], false],
            'empty-list' => ['/admin', [], false],
        ];
    }

    /**
     * @param list<string> $prefixes
     */
    #[DataProvider('pathCases')]
    public function testMatchesPath(string $path, array $prefixes, bool $expected): void
    {
        self::assertSame($expected, $this->classifier->matchesPath($path, $prefixes));
    }

    /** @return array<string, array{0: ?string, 1: bool}> */
    public static function optOutCookieCases(): array
    {
        return [
            'null' => [null, false],
            'empty' => ['', false],
            'zero' => ['0', false],
            'false' => ['false', false],
            'off' => ['off', false],
            'no' => ['no', false],
            'false-case-insensitive' => ['  FALSE  ', false],
            'one' => ['1', true],
            'yes' => ['yes', true],
            'true' => ['true', true],
            'arbitrary' => ['opted-out', true],
        ];
    }

    #[DataProvider('optOutCookieCases')]
    public function testIsOptedOut(?string $cookie, bool $expected): void
    {
        self::assertSame($expected, $this->classifier->isOptedOut($cookie));
    }

    public function testIsInternalMatchesOptOutPathAndCidrs(): void
    {
        self::assertTrue($this->classifier->isInternal('8.8.8.8', '/shop', [], [], [], '1'), 'opt-out cookie');
        self::assertTrue($this->classifier->isInternal('8.8.8.8', '/admin/x', [], [], ['/admin']), 'excluded path');
        self::assertTrue($this->classifier->isInternal('10.0.0.5', '/', ['10.0.0.0/8']), 'global internal CIDR');
        self::assertTrue($this->classifier->isInternal('192.168.1.9', '/', [], ['192.168.1.0/24']), 'per-site CIDR');
        self::assertTrue($this->classifier->isInternal(null, '/admin/x', [], [], ['/admin']), 'path wins without IP');
        self::assertFalse($this->classifier->isInternal(null, '/', ['10.0.0.0/8']), 'no IP is not internal');
        self::assertFalse($this->classifier->isInternal('', '/', ['10.0.0.0/8']), 'empty IP is not internal');
        self::assertFalse($this->classifier->isInternal('8.8.8.8', '/shop', ['10.0.0.0/8'], ['192.168.0.0/16']));
    }

    public function testExcludedDeviceAndPropsMarkers(): void
    {
        self::assertSame('', $this->classifier->excludedDevice(false, false));
        self::assertSame(TrafficClassifier::EXCLUDED_BOT, $this->classifier->excludedDevice(true, false));
        self::assertSame(TrafficClassifier::EXCLUDED_INTERNAL, $this->classifier->excludedDevice(false, true));
        // Bot wins over internal when both flags are set.
        self::assertSame(TrafficClassifier::EXCLUDED_BOT, $this->classifier->excludedDevice(true, true));

        self::assertSame([], $this->classifier->propsMarkers(false, false));
        self::assertSame(
            [TrafficClassifier::EXCLUDED_PROP => true, 'reason' => TrafficClassifier::EXCLUDED_BOT],
            $this->classifier->propsMarkers(true, false),
        );
        self::assertSame(
            [TrafficClassifier::EXCLUDED_PROP => true, 'reason' => TrafficClassifier::EXCLUDED_INTERNAL],
            $this->classifier->propsMarkers(false, true),
        );
        self::assertSame(
            [TrafficClassifier::EXCLUDED_PROP => true, 'reason' => TrafficClassifier::EXCLUDED_BOT],
            $this->classifier->propsMarkers(true, true),
        );

        self::assertSame(['bot', 'internal'], TrafficClassifier::EXCLUDED_DEVICES);
        self::assertSame('grq_no_track', TrafficClassifier::OPTOUT_COOKIE);
        self::assertStringContainsString('"excluded":true', TrafficClassifier::EXCLUDED_PROPS_LIKE);
    }
}
