<?php

declare(strict_types=1);

namespace Gereq\Support;

/**
 * Lightweight Markdown → HTML for listing descriptions and similar prose.
 * Supports paragraphs, # / ## / ### headings, - / * bullet lists, and **bold**.
 */
final class SimpleMarkdown
{
    public static function toHtml(?string $raw): string
    {
        $text = trim((string) $raw);
        if ($text === '') {
            return '';
        }

        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $blocks = [];
        $paragraph = [];
        $list = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                self::flushList($list, $blocks);
                self::flushParagraph($paragraph, $blocks);
                continue;
            }

            if (preg_match('/^(#{1,3})\s+(.+)$/u', $trimmed, $heading) === 1) {
                self::flushList($list, $blocks);
                self::flushParagraph($paragraph, $blocks);
                $level = strlen($heading[1]) + 1; // # → h2, ## → h3, ### → h4
                $blocks[] = sprintf('<h%d>%s</h%d>', $level, self::inline($heading[2]), $level);
                continue;
            }

            if (preg_match('/^[-*•]\s+(.+)$/u', $trimmed, $bullet) === 1) {
                self::flushParagraph($paragraph, $blocks);
                $list[] = $bullet[1];
                continue;
            }

            self::flushList($list, $blocks);
            $paragraph[] = $trimmed;
        }

        self::flushList($list, $blocks);
        self::flushParagraph($paragraph, $blocks);

        return implode('', $blocks);
    }

    /** @param list<string> $paragraph @param list<string> $blocks */
    private static function flushParagraph(array &$paragraph, array &$blocks): void
    {
        if ($paragraph === []) {
            return;
        }
        $html = implode('<br>', array_map(self::inline(...), $paragraph));
        $blocks[] = '<p>' . $html . '</p>';
        $paragraph = [];
    }

    /** @param list<string> $list @param list<string> $blocks */
    private static function flushList(array &$list, array &$blocks): void
    {
        if ($list === []) {
            return;
        }
        $items = array_map(
            static fn (string $item): string => '<li>' . self::inline($item) . '</li>',
            $list,
        );
        $blocks[] = '<ul>' . implode('', $items) . '</ul>';
        $list = [];
    }

    private static function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped);
    }
}
