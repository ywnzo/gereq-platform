<?php

declare(strict_types=1);

namespace Modules\Documents\Application\Pdf;

final class PdfPersistOptions
{
    /**
     * @param string $directory Relative directory under uploads (e.g. "carservice/2026/09"), no trailing slash.
     * @param list<array{entityType: string, entityId: string}> $extraLinks Additional entity links (e.g. customer).
     */
    public function __construct(
        public readonly string $directory,
        public readonly ?string $entityType = null,
        public readonly ?string $entityId = null,
        public readonly ?string $uploadedBy = null,
        public readonly ?string $filename = null,
        public readonly array $extraLinks = [],
    ) {
    }
}
