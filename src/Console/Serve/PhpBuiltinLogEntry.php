<?php

declare(strict_types=1);

namespace Gereq\Console\Serve;

/** Parsed line from the PHP built-in development server. */
final readonly class PhpBuiltinLogEntry
{
    public function __construct(
        public bool $access,
        public string $method,
        public string $path,
        public int $status,
        public ?string $time,
        public string $raw,
        public bool $noise = false,
        public ?string $payload = null,
    ) {
    }
}
