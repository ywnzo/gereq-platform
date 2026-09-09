<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Extractors;

final class TextExtractor
{
    public function extract(string $binary, string $mime, string $filename = ''): string
    {
        $mime = strtolower($mime);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (str_starts_with($mime, 'text/') || in_array($ext, ['txt', 'md', 'csv', 'json'], true)) {
            return $this->normalize($binary);
        }
        if (str_contains($mime, 'html') || $ext === 'html' || $ext === 'htm') {
            return $this->normalize(html_entity_decode(strip_tags($binary), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($mime === 'application/pdf' || $ext === 'pdf') {
            return $this->extractPdf($binary);
        }

        // images / unknown: empty (vision path later)
        return '';
    }

    private function extractPdf(string $binary): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ai_pdf_');
        if ($tmp === false) {
            return '';
        }
        $path = $tmp . '.pdf';
        @unlink($tmp);
        file_put_contents($path, $binary);
        try {
            if ($this->commandExists('pdftotext')) {
                $out = [];
                $code = 0;
                exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>/dev/null', $out, $code);
                if ($code === 0) {
                    return $this->normalize(implode("\n", $out));
                }
            }
            // naive binary strip fallback
            $raw = preg_replace('/[^\P{C}\n\t ]+/u', ' ', $binary) ?? '';
            return $this->normalize($raw);
        } finally {
            @unlink($path);
        }
    }

    private function normalize(string $text): string
    {
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function commandExists(string $cmd): bool
    {
        $out = [];
        $code = 1;
        exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $code);

        return $code === 0;
    }
}
