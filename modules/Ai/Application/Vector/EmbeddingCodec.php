<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Vector;

final class EmbeddingCodec
{
    /**
     * @param list<float> $vector
     */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /**
     * @return list<float>
     */
    public static function unpack(string $binary): array
    {
        if ($binary === '') {
            return [];
        }
        $arr = unpack('g*', $binary);
        if ($arr === false) {
            return [];
        }

        return array_map(static fn ($v) => (float) $v, array_values($arr));
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }
}
