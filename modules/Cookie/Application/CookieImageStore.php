<?php

declare(strict_types=1);

namespace Modules\Cookie\Application;

use Gereq\File\FileManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Immutable public images; replacing a draft never overwrites the published image. */
final class CookieImageStore
{
    public function __construct(private readonly FileManager $files)
    {
    }

    public function store(UploadedFile $upload): string
    {
        if (!$upload->isValid() || $upload->getSize() > 2 * 1024 * 1024) {
            throw new \RuntimeException('cookie.image.error');
        }
        $info = @getimagesize($upload->getPathname());
        if (!is_array($info)) {
            throw new \RuntimeException('cookie.image.error');
        }
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = $info['mime'];
        if (!isset($extensions[$mime]) || $info[0] > 4096 || $info[1] > 4096) {
            throw new \RuntimeException('cookie.image.error');
        }
        $contents = file_get_contents($upload->getPathname());
        if ($contents === false) {
            throw new \RuntimeException('cookie.image.error');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        try {
            $this->files->filesystem()->write('cookie/' . $name, $contents);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('cookie.image.error', 0, $exception);
        }

        return $name;
    }

    public function path(string $name): ?string
    {
        if (preg_match('/\A[a-f0-9]{32}\.(?:png|jpg|webp|gif)\z/D', $name) !== 1) {
            return null;
        }

        return $this->files->absolutePath('cookie/' . $name);
    }

    public static function mime(string $name): string
    {
        return match (pathinfo($name, PATHINFO_EXTENSION)) {
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }
}
