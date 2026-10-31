<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Modules\Analytics\Application\ConsentGate;
use Modules\Analytics\Domain\ConsentDecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Consent gate — layer 2 of the consent contract (README): collection requires **both** the
 * `grq_consent` cookie assertion and the matching assertion in the beacon payload.
 */
final class ConsentGateTest extends TestCase
{
    private ConsentGate $gate;

    protected function setUp(): void
    {
        $this->gate = new ConsentGate();
    }

    /** @return array<string, array{0: ?string, 1: ?array<string, mixed>}> */
    public static function assertionCases(): array
    {
        return [
            'documented-cookie-shape' => ['{"v":1,"statistics":true,"marketing":false,"ts":1699999999}', [
                'v' => 1,
                'statistics' => true,
                'marketing' => false,
                'ts' => 1699999999,
            ]],
            'payload-shape-without-marketing' => ['{"v":1,"statistics":true}', ['v' => 1, 'statistics' => true]],
            'single-entry' => ['{"statistics":true}', ['statistics' => true]],
            'null' => [null, null],
            'empty' => ['', null],
            'whitespace' => ['   ', null],
            'invalid-json' => ['{not json', null],
            'json-scalar' => ['true', null],
        ];
    }

    /**
     * @param array<string, mixed>|null $expected
     */
    #[DataProvider('assertionCases')]
    public function testAssertionParsesDocumentedCookieShapes(?string $raw, ?array $expected): void
    {
        self::assertSame($expected, $this->gate->assertion($raw));
    }

    public function testNormalizeAssertionAcceptsArrayOrJsonString(): void
    {
        self::assertSame(['statistics' => true], $this->gate->normalizeAssertion(['statistics' => true]));
        self::assertSame(['statistics' => true], $this->gate->normalizeAssertion('{"statistics":true}'));
        self::assertNull($this->gate->normalizeAssertion(null));
        self::assertNull($this->gate->normalizeAssertion(1));
        self::assertNull($this->gate->normalizeAssertion(false));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: bool}> */
    public static function categoryCases(): array
    {
        return [
            'boolean-true' => [['statistics' => true], 'statistics', true],
            'integer-one' => [['statistics' => 1], 'statistics', true],
            'string-one' => [['statistics' => '1'], 'statistics', true],
            'string-true' => [['statistics' => 'true'], 'statistics', true],
            'boolean-false' => [['statistics' => false], 'statistics', false],
            'integer-zero' => [['statistics' => 0], 'statistics', false],
            'string-zero' => [['statistics' => '0'], 'statistics', false],
            'string-false' => [['statistics' => 'false'], 'statistics', false],
            'missing-category' => [['marketing' => true], 'statistics', false],
            'missing-both' => [[], 'statistics', false],
        ];
    }

    /**
     * @param array<string, mixed> $assertion
     */
    #[DataProvider('categoryCases')]
    public function testCategoryGranted(array $assertion, string $category, bool $expected): void
    {
        self::assertSame($expected, $this->gate->categoryGranted($assertion, $category));
    }

    public function testGrantedRequiresBothCookieAndPayloadAssertions(): void
    {
        $cookie = '{"v":1,"statistics":true}';
        $payload = ['v' => 1, 'statistics' => true];

        self::assertTrue($this->gate->granted($cookie, $payload, 'statistics'));
        // Only the cookie grants.
        self::assertFalse($this->gate->granted($cookie, ['v' => 1, 'statistics' => false], 'statistics'));
        self::assertFalse($this->gate->granted($cookie, null, 'statistics'));
        // Only the payload grants.
        self::assertFalse($this->gate->granted('{"v":1,"statistics":false}', $payload, 'statistics'));
        self::assertFalse($this->gate->granted(null, $payload, 'statistics'));
        self::assertFalse($this->gate->granted('', $payload, 'statistics'));
        // Neither.
        self::assertFalse($this->gate->granted(null, null, 'statistics'));
    }

    public function testGrantedRejectsMismatchedConsentVersions(): void
    {
        self::assertFalse($this->gate->granted(
            '{"v":1,"statistics":true}',
            ['v' => 2, 'statistics' => true],
            'statistics',
        ));
        // A missing version on either side is tolerated.
        self::assertTrue($this->gate->granted('{"statistics":true}', ['statistics' => true], 'statistics'));
        self::assertTrue($this->gate->granted('{"v":1,"statistics":true}', ['statistics' => true], 'statistics'));
    }

    /** @return array<string, array{0: mixed, 1: ?string, 2: string}> [raw decision, cookie, expected] */
    public static function decisionCases(): array
    {
        $granting = '{"v":1,"statistics":true}';
        $denying = '{"v":1,"statistics":false}';

        return [
            'explicit-granted' => ['granted', null, ConsentDecision::GRANTED],
            'explicit-denied' => ['denied', $granting, ConsentDecision::DENIED],
            'explicit-revoked' => ['revoked', $granting, ConsentDecision::REVOKED],
            'uppercase-and-spaced' => ['  REVOKED  ', null, ConsentDecision::REVOKED],
            'unknown-falls-back-to-granting-cookie' => ['bogus', $granting, ConsentDecision::GRANTED],
            'absent-with-granting-cookie' => [null, $granting, ConsentDecision::GRANTED],
            'absent-with-denying-cookie' => [null, $denying, ConsentDecision::DENIED],
            'absent-without-cookie' => [null, null, ConsentDecision::DENIED],
            'non-string-with-granting-cookie' => [1, $granting, ConsentDecision::GRANTED],
        ];
    }

    #[DataProvider('decisionCases')]
    public function testDecision(mixed $raw, ?string $cookie, string $expected): void
    {
        self::assertSame($expected, $this->gate->decision($raw, 'statistics', $cookie));
    }

    public function testConstantsMatchTheFrozenContract(): void
    {
        self::assertSame('grq_consent', ConsentGate::COOKIE);
        self::assertSame(['granted', 'denied', 'revoked'], ConsentGate::DECISIONS);
        self::assertSame(ConsentDecision::GRANTED, ConsentGate::DECISION_GRANTED);
        self::assertSame(ConsentDecision::DENIED, ConsentGate::DECISION_DENIED);
        self::assertSame(ConsentDecision::REVOKED, ConsentGate::DECISION_REVOKED);
    }
}
