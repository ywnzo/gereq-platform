<?php

declare(strict_types=1);

namespace Modules\Cms\Domain\Events;

final class PagePublished extends AbstractCmsEvent
{
    /** @param array<string, mixed> $data */
    public static function make(string $id, array $data = [], ?array $actor = null): self
    {
        return new self('page.published', ['type' => 'cms_page', 'id' => $id], $data, $actor);
    }
}
