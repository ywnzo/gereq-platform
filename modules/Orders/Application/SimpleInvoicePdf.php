<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

/**
 * Minimal PDF writer (no Dompdf) — Latin-1/transliterated text for invoice downloads.
 * Prefer Dompdf when available; this guarantees a .pdf file without Composer network.
 */
final class SimpleInvoicePdf
{
    /**
     * @param list<string> $lines
     */
    public static function render(array $lines, string $title = 'Invoice'): string
    {
        $content = "BT /F1 11 Tf 50 800 Td 14 TL\n";
        $y = 0;
        foreach ($lines as $i => $line) {
            $safe = self::pdfText($line);
            if ($i === 0) {
                $content .= "/F1 16 Tf ({$safe}) Tj T*\n/F1 11 Tf\n";
            } else {
                $content .= "({$safe}) Tj T*\n";
            }
            $y++;
            if ($y > 55) {
                break;
            }
        }
        $content .= "ET\n";

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>';
        $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref\n0 ' . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . ' /Root 1 0 R >>' . "\n";
        $pdf .= "startxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private static function pdfText(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $ascii = $ascii !== false ? $ascii : $value;
        $ascii = preg_replace('/[^\x20-\x7E]/', '?', $ascii) ?? '?';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }
}
