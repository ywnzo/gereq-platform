<?php

declare(strict_types=1);

namespace Gereq\File;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Config\Repository;
use Gereq\User\Entity\User;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FileManager
{
    private Filesystem $filesystem;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Repository $config,
        private readonly string $basePath,
    ) {
        $root = $this->basePath . '/' . trim((string) $this->config->get('files.local_path', 'storage/uploads'), '/');
        if (!is_dir($root)) {
            mkdir($root, 0775, true);
        }

        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($root));
    }

    public function store(UploadedFile $uploadedFile, ?User $user = null): StoredFile
    {
        $max = (int) $this->config->get('files.max_size', 10 * 1024 * 1024);
        if ($uploadedFile->getSize() > $max) {
            throw new \RuntimeException('Soubor je příliš velký.');
        }

        $mime = (string) ($uploadedFile->getMimeType() ?? 'application/octet-stream');
        $allowed = $this->config->get('files.allowed_mimes', []);
        if (is_array($allowed) && $allowed !== [] && !in_array($mime, $allowed, true)) {
            throw new \RuntimeException('Tento typ souboru není povolen.');
        }

        $name = bin2hex(random_bytes(16)) . '.' . ($uploadedFile->guessExtension() ?: 'bin');
        $path = date('Y/m') . '/' . $name;
        $contents = file_get_contents($uploadedFile->getPathname());
        if ($contents === false) {
            throw new \RuntimeException('Soubor nelze přečíst.');
        }

        $this->filesystem->write($path, $contents);

        $file = new StoredFile(
            path: $path,
            originalName: $uploadedFile->getClientOriginalName(),
            mime: $mime,
            size: (int) $uploadedFile->getSize(),
            disk: (string) $this->config->get('files.driver', 'local'),
            uploadedBy: $user?->id(),
        );

        $this->em->persist($file);
        $this->em->flush();

        return $file;
    }

    public function filesystem(): Filesystem
    {
        return $this->filesystem;
    }
}
