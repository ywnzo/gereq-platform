<?php

declare(strict_types=1);

namespace Modules\Cms\Domain\Events;

final class BlockDeleted extends AbstractCmsEvent
{
    /** @param array<string, mixed> $data */
    public static function make(string $id, array $data = [], ?array $actor = null): self
    {
        return new self('block.deleted', ['type' => 'cms_page_block', 'id' => $id], $data, $actor);
    }
}
