<?php

declare(strict_types=1);

namespace Modules\Portals\Application\Sreality;

/**
 * Načte vestavěnou Sreality API specifikaci (XML-RPC + číselníky).
 */
final class SrealityApiSpec
{
    /** @return array<string, mixed> */
    public static function load(): array
    {
        $path = dirname(__DIR__, 2) . '/Resources/sreality/api_spec.php';
        if (!is_file($path)) {
            return [];
        }
        /** @var mixed $data */
        $data = require $path;

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<int|string, mixed>
     */
    public static function codebook(array $spec, string $name): array
    {
        $books = $spec['codebooks'] ?? null;
        if (!is_array($books)) {
            return [];
        }
        $book = $books[$name] ?? null;

        return is_array($book) ? $book : [];
    }
}
