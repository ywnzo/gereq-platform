<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Modules\Analytics\Application\Repositories\SiteRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Origin binding for a key-resolved site ({@see SiteRepository::hostsMatchSite()}).
 *
 * The tracker key is public, so a key alone must not let a requester write traffic into a foreign
 * site: every supplied host (request `Host` plus the page `Origin`/`Referer` host) has to match one
 * of the configured domains. An empty `domains` list means "no restriction configured" and accepts
 * the key on its own; with domains configured at least one usable host must be supplied.
 */
final class SiteRepositoryHostsMatchSiteTest extends TestCase
{
    /** @return array<string, array{0: list<string>, 1: list<string|null>, 2: bool}> */
    public static function hostBindingCases(): array
    {
        return [
            'empty-domains-accepts-without-evidence' => [[], [null, null], true],
            'empty-domains-accepts-any-host' => [[], ['evil.test', null], true],
            'exact-host' => [['example.com'], ['example.com', null], true],
            'host-normalised-case-and-port' => [['example.com'], ['EXAMPLE.com:8443', null], true],
            'wildcard-subdomain' => [['*.example.com'], ['shop.example.com', null], true],
            'wildcard-bare-domain' => [['*.example.com'], ['example.com', null], true],
            'wildcard-deeper-subdomain' => [['*.example.com'], ['a.b.example.com', null], true],
            'wildcard-rejects-other-domain' => [['*.example.com'], ['example.org', null], false],
            'foreign-host' => [['example.com'], ['evil.test', null], false],
            'missing-evidence-with-domains' => [['example.com'], [null, null], false],
            'blank-evidence-with-domains' => [['example.com'], ['', '   '], false],
            'origin-host-satisfies-when-request-host-blank' => [['example.com'], [null, 'example.com'], true],
            'every-supplied-host-must-match' => [['example.com'], ['example.com', 'evil.test'], false],
            'second-domain-matches' => [['example.com', 'shop.test'], ['shop.test', null], true],
        ];
    }

    /**
     * @param list<string> $domains
     * @param list<string|null> $hosts
     */
    #[DataProvider('hostBindingCases')]
    public function testHostsMatchSite(array $domains, array $hosts, bool $expected): void
    {
        self::assertSame($expected, SiteRepository::hostsMatchSite(['domains' => $domains], $hosts));
    }

    public function testDomainsOfNormalisesAndDropsEmpties(): void
    {
        self::assertSame([], SiteRepository::domainsOf(['domains' => []]));
        self::assertSame(
            ['example.com', '*.example.org'],
            SiteRepository::domainsOf(['domains' => ['Example.com', '*.Example.org', '', '  ']]),
        );
        // A JSON string column decodes to the same list.
        self::assertSame(['example.com'], SiteRepository::domainsOf(['domains' => '["Example.com"]']));
    }
}
