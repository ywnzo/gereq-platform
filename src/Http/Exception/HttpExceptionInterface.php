<?php

declare(strict_types=1);

namespace Gereq\Http\Exception;

interface HttpExceptionInterface extends \Throwable
{
    public function status(): int;

    /** @return array<string, string> */
    public function headers(): array;
}
