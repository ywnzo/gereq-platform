<?php

declare(strict_types=1);

namespace Modules\Feeds\Domain;

interface FeedDriverInterface
{
    public function key(): string;

    /**
     * @param array<string, mixed> $config
     * @param list<array<string, mixed>> $products
     */
    public function render(array $config, array $products): string;

    public function contentType(): string;
}
