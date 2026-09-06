<?php

declare(strict_types=1);

namespace Modules\Documents\Application\Pdf;

final class PdfRenderResult
{
    public function __construct(
        public readonly string $binary,
        public readonly string $mime,
        public readonly string $extension,
    ) {
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }
}
