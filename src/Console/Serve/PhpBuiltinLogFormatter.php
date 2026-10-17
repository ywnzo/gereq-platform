<?php

declare(strict_types=1);

namespace Gereq\Console\Serve;

/**
 * Parses and colourises PHP built-in server (`php -S`) log lines.
 *
 * Access example:
 * `[Sat Oct 17 08:00:00 2026] 127.0.0.1:54321 [200]: POST /admin | {"a":1}`
 */
final class PhpBuiltinLogFormatter
{
    public const PAYLOAD_PREVIEW = 200;

    private const ACCESS = '/^\[(?P<stamp>[^\]]+)\]\s+\S+\s+\[(?P<status>\d{3})\]:\s+(?P<method>[A-Z]+)\s+(?P<path>\S+)(?:\s+\|\s+(?P<payload>.*))?$/';

    private const NOISE = '/^\[(?P<stamp>[^\]]+)\]\s+\S+\s+(?:Accepted|Closing)$/';

    /**
     * Format a raw log line for console output.
     * Returns null to hide noise (Accepted / Closing).
     */
    public function format(string $line): ?string
    {
        $entry = $this->parse($line);
        if ($entry === null) {
            $trimmed = rtrim($line, "\r\n");
            if ($trimmed === '') {
                return null;
            }

            return '<fg=gray>' . $this->escape($trimmed) . '</>';
        }

        return $this->formatEntry($entry);
    }

    /** Format a parsed entry; null means hide (noise). */
    public function formatEntry(PhpBuiltinLogEntry $entry, bool $expandPayload = false): ?string
    {
        if ($entry->noise) {
            return null;
        }

        if (!$entry->access) {
            return '<fg=gray>' . $this->escape($entry->raw) . '</>';
        }

        $time = str_pad($entry->time ?? '--:--:--', 8);
        $method = str_pad($entry->method, 6);
        $status = str_pad((string) $entry->status, 3);
        $path = $this->escape($entry->path);

        $line = sprintf(
            '<fg=gray>%s</>  %s  %s  %s',
            $time,
            $this->colourMethod($method, $entry->method),
            $this->colourStatus($status, $entry->status),
            $path
        );

        $payloadPart = $this->payloadSuffix($entry->payload, $expandPayload, symfony: true);
        if ($payloadPart !== '') {
            $line .= '  ' . $payloadPart;
        }

        return $line;
    }

    /**
     * ANSI variant for TUI (no Symfony tags). Null = hide.
     *
     * @return list<string>|null One or more display rows (payload may wrap when expanded).
     */
    public function formatAnsiRows(PhpBuiltinLogEntry $entry, int $count = 1, bool $expandPayload = false, int $width = 100): ?array
    {
        if ($entry->noise) {
            return null;
        }

        if (!$entry->access) {
            return [$this->ansi('90', $entry->raw)];
        }

        $time = str_pad($entry->time ?? '--:--:--', 8);
        $method = str_pad($entry->method, 6);
        $status = str_pad((string) $entry->status, 3);
        $methodAnsi = $this->ansiMethod($method, $entry->method);
        $statusAnsi = $this->ansiStatus($status, $entry->status);

        $line = $this->ansi('90', $time) . '  ' . $methodAnsi . '  ' . $statusAnsi . '  ' . $entry->path;
        if ($count > 1) {
            $line .= '  ' . $this->ansi('90', $count . '×');
        }

        $payload = $entry->payload;
        if ($payload === null || $payload === '') {
            return [$line];
        }

        if (!$expandPayload) {
            $preview = $this->previewPayload($payload, self::PAYLOAD_PREVIEW);
            $line .= '  ' . $this->ansi('95', $preview);

            return [$line];
        }

        $rows = [$line . '  ' . $this->ansi('90', '▾ payload')];
        $innerWidth = max(20, $width - 6);
        $full = $payload;
        while ($full !== '') {
            $chunk = mb_substr($full, 0, $innerWidth);
            $full = mb_substr($full, mb_strlen($chunk));
            $rows[] = '    ' . $this->ansi('95', $chunk);
        }

        return $rows;
    }

    /** @deprecated use formatAnsiRows */
    public function formatAnsi(PhpBuiltinLogEntry $entry, int $count = 1, bool $expandPayload = false): ?string
    {
        $rows = $this->formatAnsiRows($entry, $count, $expandPayload);
        if ($rows === null) {
            return null;
        }

        return $rows[0];
    }

    private function ansi(string $code, string $text): string
    {
        return "\033[{$code}m{$text}\033[0m";
    }

    public function parse(string $line): ?PhpBuiltinLogEntry
    {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            return null;
        }

        if (preg_match(self::NOISE, $line, $m) === 1) {
            return new PhpBuiltinLogEntry(
                access: false,
                method: '',
                path: '',
                status: 0,
                time: $this->shortTime((string) $m['stamp']),
                raw: $line,
                noise: true,
            );
        }

        if (preg_match(self::ACCESS, $line, $m) === 1) {
            $path = (string) $m['path'];
            if (str_contains($path, '?')) {
                $path = explode('?', $path, 2)[0];
            }
            $payload = isset($m['payload']) && $m['payload'] !== '' ? (string) $m['payload'] : null;

            return new PhpBuiltinLogEntry(
                access: true,
                method: (string) $m['method'],
                path: $path,
                status: (int) $m['status'],
                time: $this->shortTime((string) $m['stamp']),
                raw: $line,
                payload: $payload,
            );
        }

        return new PhpBuiltinLogEntry(
            access: false,
            method: '',
            path: $line,
            status: 0,
            time: null,
            raw: $line,
        );
    }

    private function colourMethod(string $padded, string $method): string
    {
        // Distinct hues — POST must not look like GET.
        return match ($method) {
            'GET' => '<fg=cyan>' . $padded . '</>',
            'POST' => '<fg=magenta;options=bold>' . $padded . '</>',
            'PUT' => '<fg=blue;options=bold>' . $padded . '</>',
            'PATCH' => '<fg=yellow;options=bold>' . $padded . '</>',
            'DELETE' => '<fg=red;options=bold>' . $padded . '</>',
            'HEAD' => '<fg=gray>' . $padded . '</>',
            'OPTIONS' => '<fg=white>' . $padded . '</>',
            default => '<fg=bright-yellow>' . $padded . '</>',
        };
    }

    private function colourStatus(string $padded, int $status): string
    {
        return match (true) {
            $status >= 500 => '<fg=red;options=bold>' . $padded . '</>',
            $status >= 400 => '<fg=yellow>' . $padded . '</>',
            $status >= 300 => '<fg=bright-cyan>' . $padded . '</>',
            default => '<fg=green>' . $padded . '</>',
        };
    }

    private function ansiMethod(string $padded, string $method): string
    {
        return match ($method) {
            'GET' => $this->ansi('36', $padded),           // cyan
            'POST' => $this->ansi('95;1', $padded),        // bright magenta
            'PUT' => $this->ansi('94;1', $padded),         // bright blue
            'PATCH' => $this->ansi('93;1', $padded),       // bright yellow
            'DELETE' => $this->ansi('91;1', $padded),      // bright red
            'HEAD' => $this->ansi('90', $padded),          // gray
            'OPTIONS' => $this->ansi('37', $padded),       // white
            default => $this->ansi('33', $padded),
        };
    }

    private function ansiStatus(string $padded, int $status): string
    {
        return match (true) {
            $status >= 500 => $this->ansi('91;1', $padded),
            $status >= 400 => $this->ansi('33', $padded),
            $status >= 300 => $this->ansi('96', $padded),  // bright cyan (not same as GET 36)
            default => $this->ansi('32', $padded),
        };
    }

    private function payloadSuffix(?string $payload, bool $expand, bool $symfony): string
    {
        if ($payload === null || $payload === '') {
            return '';
        }
        $text = $expand ? $payload : $this->previewPayload($payload, self::PAYLOAD_PREVIEW);
        if ($symfony) {
            return '<fg=magenta>' . $this->escape($text) . '</>';
        }

        return $this->ansi('95', $text);
    }

    public function previewPayload(string $payload, int $max = self::PAYLOAD_PREVIEW): string
    {
        $payload = trim($payload);
        if (mb_strlen($payload) <= $max) {
            return $payload;
        }

        return mb_substr($payload, 0, $max) . '…';
    }

    private function shortTime(string $stamp): ?string
    {
        if (preg_match('/\b(\d{2}:\d{2}:\d{2})\b/', $stamp, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function escape(string $value): string
    {
        return str_replace(['<', '>'], ['\<', '\>'], $value);
    }
}
