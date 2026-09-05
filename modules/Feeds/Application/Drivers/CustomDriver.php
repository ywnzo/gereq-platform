<?php

declare(strict_types=1);

namespace Modules\Feeds\Application\Drivers;

use Modules\Feeds\Domain\FeedDriverInterface;

final class CustomDriver implements FeedDriverInterface
{
    public function key(): string
    {
        return 'custom';
    }

    public function contentType(): string
    {
        return 'application/json; charset=UTF-8';
    }

    public function render(array $config, array $products): string
    {
        return (string) json_encode([
            'config' => $config,
            'products' => $products,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
