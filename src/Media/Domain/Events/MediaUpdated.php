<?php

declare(strict_types=1);

namespace Gereq\Media\Domain\Events;

final class MediaUpdated extends AbstractMediaEvent
{
    /**
     * @param array<string, mixed> $data
     * @param array{type: string, id: ?string}|null $actor
     */
    public static function make(string $id, array $data = [], ?array $actor = null): self
    {
        return new self('media.updated', ['type' => 'media_asset', 'id' => $id], $data, $actor);
    }
}
