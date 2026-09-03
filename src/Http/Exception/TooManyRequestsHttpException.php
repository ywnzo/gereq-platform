<?php

declare(strict_types=1);

namespace Gereq\Http\Exception;

final class TooManyRequestsHttpException extends HttpException
{
    /** @param array<string, string> $headers */
    public function __construct(string $message = '', array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct(429, $message, $headers, $previous);
    }
}
