<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Media\Application\MediaImporter;
use Modules\Media\Domain\MediaAsset;

/**
 * Stáhne obrázek dílu do Media (idempotentně přes MediaImporter).
 */
final class PartsCatalogImageService
{
    private ?MediaImporter $importer = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function isAvailable(): bool
    {
        return class_exists(MediaImporter::class) && class_exists(MediaAsset::class);
    }

    public function import(?string $url, string $title): ?MediaAsset
    {
        $url = trim((string) $url);
        if ($url === '' || !$this->isAvailable()) {
            return null;
        }
        try {
            return $this->importer()->importFromUrl($url, $title, $title);
        } catch (\Throwable) {
            CatalogCallLogger::log('media.import_fail', ['url' => mb_substr($url, 0, 160)]);

            return null;
        }
    }

    public function publicUrl(?MediaAsset $asset): ?string
    {
        if (!$asset instanceof MediaAsset) {
            return null;
        }

        return '/media/' . $asset->id() . '/file';
    }

    private function importer(): MediaImporter
    {
        return $this->importer ??= MediaImporter::fromBasePath($this->em, base_path());
    }
}
