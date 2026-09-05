<?php

declare(strict_types=1);

namespace Gereq\Http\Exception;

class HttpException extends \RuntimeException implements HttpExceptionInterface
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : self::defaultMessage($status),
            $status,
            $previous,
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    /** Default messages are translation keys resolved by ExceptionHandler. */
    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            401 => 'errors.401.message',
            403 => 'errors.403.message',
            404 => 'errors.404.message',
            405 => 'errors.405.message',
            419 => 'errors.419.message',
            422 => 'validation.form_failed',
            429 => 'errors.429.message',
            503 => 'errors.503.message',
            default => 'errors.500.message',
        };
    }
}
