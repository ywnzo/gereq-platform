<?php

declare(strict_types=1);

namespace Gereq\Http\Exception;

final class CsrfTokenMismatchException extends HttpException
{
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct(419, $message, [], $previous);
    }
}
