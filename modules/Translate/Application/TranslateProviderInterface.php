<?php

declare(strict_types=1);

namespace Modules\Translate\Application;

interface TranslateProviderInterface
{
    /**
     * @param list<string> $texts
     * @return list<string> same order/count as $texts
     */
    public function translateBatch(array $texts, string $sourceLocale, string $targetLocale): array;

    public function isConfigured(): bool;

    /**
     * Rychlá kontrola API klíče (malý testovací překlad).
     *
     * @return array{ok: bool, message: string, details?: array<string, mixed>}
     */
    public function ping(): array;
}
