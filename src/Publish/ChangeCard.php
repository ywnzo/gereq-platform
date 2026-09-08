<?php

declare(strict_types=1);

namespace Gereq\Publish;

final class ChangeCard
{
    public function __construct(
        public readonly string $title,
        public readonly string $summary = '',
        public readonly ?string $href = null,
    ) {
    }

    /** @return array{title: string, summary: string, href: ?string} */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'summary' => $this->summary,
            'href' => $this->href,
        ];
    }
}
