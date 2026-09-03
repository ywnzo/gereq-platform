<?php

declare(strict_types=1);

namespace Gereq\Foundation;

final class Gereq
{
    private static ?Application $app = null;

    public static function set(Application $app): void
    {
        self::$app = $app;
    }

    public static function app(): Application
    {
        if (self::$app === null) {
            throw new \RuntimeException('GEREQ Platform ještě nebyla inicializována.');
        }

        return self::$app;
    }

    public static function has(): bool
    {
        return self::$app !== null;
    }
}
