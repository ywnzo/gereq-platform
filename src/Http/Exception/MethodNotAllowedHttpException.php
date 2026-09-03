<?php

declare(strict_types=1);

namespace Gereq\Http\Exception;

final class MethodNotAllowedHttpException extends HttpException
{
    /** @param list<string> $allowed */
    public function __construct(array $allowed = [], string $message = '', ?\Throwable $previous = null)
    {
        $headers = [];
        if ($allowed !== []) {
            $headers['Allow'] = implode(', ', $allowed);
        }

        parent::__construct(405, $message, $headers, $previous);
    }
}
