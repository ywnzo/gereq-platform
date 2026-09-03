<?php

declare(strict_types=1);

namespace Gereq\Http\Exception;

final class UnauthorizedHttpException extends HttpException
{
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct(401, $message, [], $previous);
    }
}
