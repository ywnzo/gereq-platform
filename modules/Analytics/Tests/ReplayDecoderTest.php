<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Modules\Analytics\Application\ReplayDecoder;
use PHPUnit\Framework\TestCase;

final class ReplayDecoderTest extends TestCase
{
    private const RECORDING = '01234567-89ab-4cde-8fab-0123456789ab';

    public function testReassemblesOutOfOrderPartsAndSortsEvents(): void
    {
        $events = [
            ['type' => 4, 'timestamp' => 1000, 'data' => ['href' => 'https://example.com/']],
            ['type' => 2, 'timestamp' => 1001, 'data' => ['node' => []]],
        ];
        $data = base64_encode(gzencode(json_encode($events, JSON_THROW_ON_ERROR)));
        $middle = intdiv(strlen($data), 2);
        $rows = [$this->row(substr($data, $middle), 1, 2), $this->row(substr($data, 0, $middle), 0, 2)];

        self::assertSame([self::RECORDING => $events], (new ReplayDecoder())->decode($rows));
    }

    public function testMissingInitialBatchCannotPlayIncrementalEvents(): void
    {
        $events = [['type' => 3, 'timestamp' => 1002, 'data' => []]];
        $data = base64_encode(gzencode(json_encode($events, JSON_THROW_ON_ERROR)));

        self::assertSame([], (new ReplayDecoder())->decode([$this->row($data, 0, 1, 1)]));
    }

    public function testIncompleteBatchAndMalformedCompressionAreRejected(): void
    {
        $decoder = new ReplayDecoder();
        self::assertSame([], $decoder->decode([$this->row('abc', 0, 2)]));
        self::assertSame([], $decoder->decode([$this->row('not-gzip', 0, 1)]));
        self::assertSame([], $decoder->decode([['props' => '{invalid']]));
    }

    public function testDecompressionLimitRejectsOversizedRecordings(): void
    {
        $data = base64_encode(gzencode(str_repeat('x', 2000001)));

        self::assertSame([], (new ReplayDecoder())->decode([$this->row($data, 0, 1)]));
    }

    /** @return array<string, mixed> */
    private function row(string $data, int $part, int $total, int $batch = 0): array
    {
        return ['props' => json_encode([
            'recording' => self::RECORDING, 'batch' => $batch, 'part' => $part,
            'total' => $total, 'encoding' => 'gzip-base64', 'data' => $data,
        ], JSON_THROW_ON_ERROR)];
    }
}
