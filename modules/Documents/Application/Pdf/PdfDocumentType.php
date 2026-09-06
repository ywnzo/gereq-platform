<?php

declare(strict_types=1);

namespace Modules\Documents\Application\Pdf;

/**
 * Domain-agnostic PDF document definition.
 * Modules implement this (or use {@see ArrayPdfDocument}) and pass it to {@see PdfDocumentGenerator}.
 */
interface PdfDocumentType
{
    /** Stable machine key, e.g. "worksheet", "invoice". */
    public function type(): string;

    /** Twig view name, e.g. "@carservice/pdf/worksheet.html.twig". */
    public function view(): string;

    /** @return array<string, mixed> Variables passed to the Twig template. */
    public function data(): array;

    public function title(): string;

    /** Suggested download / storage filename including extension, e.g. "zakazka-abc.pdf". */
    public function suggestedFilename(): string;
}
