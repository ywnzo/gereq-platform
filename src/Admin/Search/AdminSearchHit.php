<?php

declare(strict_types=1);

namespace Gereq\Admin\Search;

/**
 * Jedna položka výsledku živého admin search (Ctrl+K).
 */
final class AdminSearchHit
{
    /**
     * @param list<string> $keywords
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $url,
        public readonly string $group,
        public readonly ?string $subtitle = null,
        public readonly ?string $icon = null,
        public readonly array $keywords = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'url' => $this->url,
            'group' => $this->group,
            'subtitle' => $this->subtitle,
            'icon' => $this->icon,
            'keywords' => $this->keywords,
            'kind' => 'hit',
        ];
    }
}
