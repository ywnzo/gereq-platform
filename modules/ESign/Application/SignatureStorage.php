<?php

declare(strict_types=1);

namespace Modules\ESign\Application;

use Gereq\File\FileManager;

final class SignatureStorage
{
    public function __construct(
        private readonly FileManager $files,
    ) {
    }

    public function writePng(string $relativePath, string $binary): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $this->files->filesystem()->write($relativePath, $binary);

        return $relativePath;
    }

    public function writePngDataUri(string $relativePath, string $dataUri): string
    {
        return $this->writePng($relativePath, $this->decodePngDataUri($dataUri));
    }

    public function read(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }
        $absolute = $this->files->absolutePath($relativePath);
        if ($absolute === null || !is_file($absolute)) {
            return null;
        }
        $binary = file_get_contents($absolute);

        return $binary === false ? null : $binary;
    }

    public function dataUri(?string $relativePath): ?string
    {
        $binary = $this->read($relativePath);
        if ($binary === null) {
            return null;
        }
        $mime = str_ends_with(strtolower((string) $relativePath), '.svg') ? 'image/svg+xml' : 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode($binary);
    }

    public function decodePngDataUri(string $dataUri): string
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/]+=*)$#', trim($dataUri), $m)) {
            throw new \InvalidArgumentException('esign.error.invalid_signature');
        }
        $binary = base64_decode($m[1], true);
        if ($binary === false || strlen($binary) < 67 || !str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            throw new \InvalidArgumentException('esign.error.invalid_signature');
        }

        return $binary;
    }

    public function documentDir(string $documentId): string
    {
        return 'esign/signatures/' . $documentId;
    }
}
