<?php

declare(strict_types=1);

namespace Modules\Products\Domain\Events;

final class ProductPriceChanged extends AbstractPlatformEvent
{
    /** @param array<string, mixed> $data @param array{type: string, id: ?string}|null $actor */
    public static function make(string $id, array $data = [], ?array $actor = null): self
    {
        return new self('product.price_changed', ['type' => 'product', 'id' => $id], $data, $actor);
    }
}
