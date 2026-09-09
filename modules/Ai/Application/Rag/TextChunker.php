<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Rag;

final class TextChunker
{
    public function __construct(
        private readonly int $chunkSize = 800,
        private readonly int $overlap = 100,
    ) {
    }

    /**
     * @return list<array{content: string, ordinal: int, token_estimate: int, hash: string}>
     */
    public function chunk(string $text): array
    {
        $text = trim(preg_replace("/\r\n|\r/", "\n", $text) ?? $text);
        if ($text === '') {
            return [];
        }

        $approxChars = $this->chunkSize * 4;
        $overlapChars = $this->overlap * 4;
        $parts = [];
        $len = mb_strlen($text);
        $offset = 0;
        $ordinal = 0;
        while ($offset < $len) {
            $slice = mb_substr($text, $offset, $approxChars);
            if ($slice === '') {
                break;
            }
            // prefer break on paragraph
            if (mb_strlen($slice) === $approxChars) {
                $break = mb_strrpos($slice, "\n\n");
                if ($break !== false && $break > (int) ($approxChars * 0.4)) {
                    $slice = mb_substr($slice, 0, $break);
                }
            }
            $slice = trim($slice);
            if ($slice !== '') {
                $parts[] = [
                    'content' => $slice,
                    'ordinal' => $ordinal,
                    'token_estimate' => (int) max(1, (int) round(mb_strlen($slice) / 4)),
                    'hash' => hash('sha256', $slice),
                ];
                $ordinal++;
            }
            $advance = max(1, mb_strlen($slice) - $overlapChars);
            $offset += $advance;
            if ($ordinal > 5000) {
                break;
            }
        }

        return $parts;
    }
}
