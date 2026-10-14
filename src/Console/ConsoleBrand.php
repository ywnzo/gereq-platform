<?php

declare(strict_types=1);

namespace Gereq\Console;

/**
 * Shared GEREQ CLI brand tokens and banner (matches public/brand signal lime).
 */
final class ConsoleBrand
{
    public const SIGNAL = '#D7FF45';

    public const INK = '#0A0B0A';

    public const MUTED = '#8A8B84';

    public const CYAN = '#5EEAD4';

    public static function banner(string $version): string
    {
        $signal = self::SIGNAL;
        $ink = self::INK;
        $muted = self::MUTED;

        return implode("\n", [
            sprintf(
                '  <bg=%s;fg=%s;options=bold> GEREQ </> <fg=%s;options=bold>Platform</>  <fg=%s>v%s</>',
                $signal,
                $ink,
                $signal,
                $muted,
                $version,
            ),
            sprintf(
                '  <fg=%s>■■■■</><fg=%s> Modular PHP · modules · admin · API</>',
                $signal,
                $muted,
            ),
        ]);
    }

    public static function wordmark(string $version): string
    {
        return sprintf(
            '<bg=%s;fg=%s;options=bold> GEREQ </> <fg=%s;options=bold>Platform</>  <fg=%s>v%s</>',
            self::SIGNAL,
            self::INK,
            self::SIGNAL,
            self::MUTED,
            $version,
        );
    }
}
