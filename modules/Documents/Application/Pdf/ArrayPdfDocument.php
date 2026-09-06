<?php

declare(strict_types=1);

namespace Modules\Documents\Application\Pdf;

/** Ad-hoc PDF document without a dedicated type class. */
final class ArrayPdfDocument implements PdfDocumentType
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly string $type,
        private readonly string $view,
        private readonly array $data,
        private readonly string $title,
        private readonly string $suggestedFilename,
    ) {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function view(): string
    {
        return $this->view;
    }

    public function data(): array
    {
        return $this->data;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function suggestedFilename(): string
    {
        return $this->suggestedFilename;
    }
}
