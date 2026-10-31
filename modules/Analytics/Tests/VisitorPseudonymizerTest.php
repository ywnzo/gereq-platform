<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Modules\Analytics\Application\VisitorPseudonymizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pseudonymity rules (README): `visitor_hash = hash_hmac('sha256', $rawVisitorId, $site->secret())`.
 */
final class VisitorPseudonymizerTest extends TestCase
{
    private VisitorPseudonymizer $pseudonymizer;

    protected function setUp(): void
    {
        $this->pseudonymizer = new VisitorPseudonymizer();
    }

    public function testHashIsStableAndMatchesTheFrozenContract(): void
    {
        $raw = 'a1b2c3d4e5f6';

        self::assertSame(hash_hmac('sha256', $raw, 'secret'), $this->pseudonymizer->pseudonymize($raw, 'secret'));
        self::assertSame(
            $this->pseudonymizer->pseudonymize($raw, 'secret'),
            $this->pseudonymizer->pseudonymize($raw, 'secret'),
        );
    }

    public function testDifferentSecretProducesDifferentHash(): void
    {
        self::assertNotSame(
            $this->pseudonymizer->pseudonymize('a1b2c3d4e5f6', 'secret-one'),
            $this->pseudonymizer->pseudonymize('a1b2c3d4e5f6', 'secret-two'),
        );
    }

    public function testHashVersionIsTheDocumentedOne(): void
    {
        self::assertSame(1, $this->pseudonymizer->hashVersion());
        self::assertSame(1, VisitorPseudonymizer::HASH_VERSION);
        self::assertSame('grq_vid', VisitorPseudonymizer::VISITOR_COOKIE);
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function rawIdCases(): array
    {
        return [
            'null' => [null, null],
            'empty' => ['', null],
            'whitespace' => ['   ', null],
            'too-short' => ['abc', null],
            'minimum-length' => ['abcdefgh', 'abcdefgh'],
            'maximum-length' => [str_repeat('a', 128), str_repeat('a', 128)],
            'too-long' => [str_repeat('a', 129), null],
            'allowed-characters' => ['aA0._~-zz', 'aA0._~-zz'],
            'trimmed' => ['  abcdefgh  ', 'abcdefgh'],
            'space-inside' => ['abcd efgh', null],
            'angle-brackets' => ['abcdefg<h', null],
            'slash' => ['abcdefg/h', null],
        ];
    }

    #[DataProvider('rawIdCases')]
    public function testRawIdNormalisation(?string $value, ?string $expected): void
    {
        self::assertSame($expected, $this->pseudonymizer->rawId($value));
    }
}
