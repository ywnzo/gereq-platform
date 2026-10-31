<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

final class VisualElement
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function normalize(string $type, array $payload): array
    {
        if ($type === 'badge') {
            return [
                'label' => mb_substr((string) ($payload['label'] ?? ''), 0, 1000),
                'tone' => in_array($payload['tone'] ?? '', ['soft', 'outline', 'solid'], true) ? $payload['tone'] : 'soft',
            ];
        }
        $url = trim((string) ($payload['url'] ?? ''));
        return [
            'name' => mb_substr((string) ($payload['name'] ?? ''), 0, 500),
            'initials' => mb_substr((string) ($payload['initials'] ?? ''), 0, 100),
            'url' => preg_match('~^(?:https?://|/(?!/)|\{\{)~i', $url) === 1 ? mb_substr($url, 0, 2000) : '',
            'avatar_size' => max(16, min(256, (int) ($payload['avatar_size'] ?? 38))),
            'shape' => ($payload['shape'] ?? '') === 'square' ? 'square' : 'circle',
        ];
    }
}
