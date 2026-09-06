<?php

declare(strict_types=1);

namespace Modules\Documents\Application\Pdf;

final class PdfRenderOptions
{
    public function __construct(
        public readonly string $paper = 'A4',
        public readonly string $orientation = 'portrait',
        public readonly bool $remoteEnabled = false,
    ) {
    }

    public static function a4(): self
    {
        return new self();
    }
}
