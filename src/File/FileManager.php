<?php

declare(strict_types=1);

namespace Gereq\File;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Config\Repository;
use Gereq\User\Entity\User;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;

final class FileManager
{
    private Filesystem $filesystem;
    private ImageConverter $converter;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Repository $config,
        private readonly string $basePath,
        private readonly TranslatorInterface $translator,
        ?ImageConverter $converter = null,
    ) {
        $root = $this->basePath . '/' . trim((string) $this->config->get('files.local_path', 'storage/uploads'), '/');
        if (!is_dir($root)) {
            mkdir($root, 0775, true);
        }

        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($root));
        $this->converter = $converter ?? new ImageConverter(
            avifQuality: (int) $this->config->get('files.image_convert.avif_quality', 55),
            webpQuality: (int) $this->config->get('files.image_convert.webp_quality', 82),
        );
    }

    public function store(UploadedFile $uploadedFile, ?User $user = null): StoredFile
    {
        $max = (int) $this->config->get('files.max_size', 10 * 1024 * 1024);
        if ($uploadedFile->getSize() > $max) {
            throw new \RuntimeException($this->translator->trans('files.too_large', ['%max%' => $max]));
        }

        $mime = (string) ($uploadedFile->getMimeType() ?? 'application/octet-stream');
        $allowed = $this->config->get('files.allowed_mimes', []);
        if (is_array($allowed) && $allowed !== [] && !in_array($mime, $allowed, true)) {
            throw new \RuntimeException($this->translator->trans('files.mime_not_allowed', ['%mime%' => $mime]));
        }

        $contents = file_get_contents($uploadedFile->getPathname());
        if ($contents === false) {
            throw new \RuntimeException($this->translator->trans('files.unreadable'));
        }

        $extension = $uploadedFile->guessExtension() ?: 'bin';
        $metadata = null;

        if ($this->shouldConvertImages() && str_starts_with($mime, 'image/')) {
            $converted = $this->converter->convert($contents, $mime);
            if ($converted !== null) {
                $contents = $converted['contents'];
                $mime = $converted['mime'];
                $extension = $converted['extension'];
                $metadata = [
                    'converted_to' => $converted['extension'],
                    'width' => $converted['width'],
                    'height' => $converted['height'],
                ];
            }
        }

        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $path = date('Y/m') . '/' . $name;

        $this->filesystem->write($path, $contents);

        $file = new StoredFile(
            path: $path,
            originalName: $uploadedFile->getClientOriginalName(),
            mime: $mime,
            size: strlen($contents),
            disk: (string) $this->config->get('files.driver', 'local'),
            uploadedBy: $user?->id(),
            metadata: $metadata,
        );

        $this->em->persist($file);
        $this->em->flush();

        return $file;
    }

    public function filesystem(): Filesystem
    {
        return $this->filesystem;
    }

    public function converter(): ImageConverter
    {
        return $this->converter;
    }

    /** Absolute path under the uploads root, or null if missing/unsafe. */
    public function absolutePath(string $relative): ?string
    {
        $root = realpath($this->basePath . '/' . trim((string) $this->config->get('files.local_path', 'storage/uploads'), '/'));
        if ($root === false) {
            return null;
        }
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..')) {
            return null;
        }

        $candidate = $root . DIRECTORY_SEPARATOR . ltrim(str_replace('\\', '/', $relative), '/');
        $real = realpath($candidate);
        if ($real === false) {
            return null;
        }
        if ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    private function shouldConvertImages(): bool
    {
        return (bool) $this->config->get('files.image_convert.enabled', true);
    }
}
