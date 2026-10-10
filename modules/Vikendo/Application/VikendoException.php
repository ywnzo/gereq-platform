<?php

declare(strict_types=1);

namespace Modules\Vikendo\Application;

final class VikendoException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 0,
        private readonly mixed $payload = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function payload(): mixed
    {
        return $this->payload;
    }
}
