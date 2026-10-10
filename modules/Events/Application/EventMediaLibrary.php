<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\File\FileManager;
use Gereq\Media\Application\MediaImporter;
use Gereq\Media\Domain\MediaAsset;
use Gereq\Media\Domain\MediaLink;
use Modules\Events\Domain\CatalogEvent;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Imports event photos into the core media library and exposes public /media/{id}/file URLs. */
final class EventMediaLibrary
{
    /** @var array<string, string> key => unsplash source */
    public const SOURCES = [
        'castle' => 'https://images.unsplash.com/photo-1467269204594-9661b134dd2b?w=1400&q=70',
        'band' => 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?w=1400&q=70',
        'run' => 'https://images.unsplash.com/photo-1476480862126-209bfaa8edc8?w=1400&q=70',
        'child' => 'https://images.unsplash.com/photo-1503454537195-1dcabb73ffb9?w=1400&q=70',
        'music' => 'https://images.unsplash.com/photo-1470229722913-7c0e2dbbafd3?w=1400&q=70',
        'bike' => 'https://images.unsplash.com/photo-1541625602330-2277a4c46182?w=1400&q=70',
        'theatre' => 'https://images.unsplash.com/photo-1503095396549-807759245b35?w=1400&q=70',
        'rock' => 'https://images.unsplash.com/photo-1493225457124-a3eb161ffa5f?w=1400&q=70',
        'sport' => 'https://images.unsplash.com/photo-1517649763962-0c623066013b?w=1400&q=70',
        'kids' => 'https://images.unsplash.com/photo-1503454537195-1dcabb73ffb9?w=1400&q=70',
        'other' => 'https://images.unsplash.com/photo-1488459716781-31db52582fe9?w=1400&q=70',
        // Hero: Domažlice square — seeded from resources/seed/hero-city.jpg (see importKey).
        'city' => 'kdeaco-local://resources/seed/hero-city.jpg',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?MediaImporter $importer = null,
    ) {
    }

    public static function fromBasePath(EntityManagerInterface $em, string $basePath): self
    {
        return new self($em, MediaImporter::fromBasePath($em, $basePath));
    }

    public static function publicUrl(?string $assetId, ?int $width = null): ?string
    {
        if ($assetId === null || $assetId === '') {
            return null;
        }
        $url = '/media/' . $assetId . '/file';

        return $width !== null ? $url . '?w=' . $width : $url;
    }

    public function importKey(string $key, string $title): ?MediaAsset
    {
        if ($this->importer === null) {
            return null;
        }
        $source = self::SOURCES[$key] ?? self::SOURCES['other'];
        if (str_starts_with($source, 'kdeaco-local://')) {
            $relative = substr($source, strlen('kdeaco-local://'));
            $absolute = rtrim(base_path(), '/') . '/' . ltrim($relative, '/');
            if (!is_file($absolute)) {
                return null;
            }
            $source = 'file://' . $absolute;
        }

        return $this->importer->importFromUrl($source, $title, $title);
    }

    public function bindExistingCover(CatalogEvent $event): void
    {
        $id = $event->mediaAssetId();
        if ($id === null || $id === '') {
            return;
        }
        $asset = $this->em->find(MediaAsset::class, $id);
        if (!$asset instanceof MediaAsset) {
            return;
        }
        $this->bindCover($event, $asset, $event->imageKey());
    }

    public function importUploaded(
        FileManager $files,
        UploadedFile $uploaded,
        string $title,
        ?string $uploadedBy = null,
    ): MediaAsset {
        $stored = $files->store($uploaded, null);
        $label = trim($title);
        if ($label === '') {
            $label = $stored->originalName();
        }
        $width = null;
        $height = null;
        $meta = $stored->metadata();
        if (is_array($meta) && isset($meta['width'], $meta['height'])) {
            $width = (int) $meta['width'];
            $height = (int) $meta['height'];
        } else {
            $info = @getimagesize($uploaded->getPathname());
            if (is_array($info)) {
                $width = (int) $info[0];
                $height = (int) $info[1];
            }
        }
        $asset = new MediaAsset(
            $label,
            $stored->path(),
            $stored->mime(),
            $stored->size(),
            $uploadedBy,
            $label,
            $width,
            $height,
        );
        $this->em->persist($asset);
        $this->em->flush();

        return $asset;
    }

    public function attachCover(CatalogEvent $event, string $key): void
    {
        $asset = $this->importKey($key, $event->title());
        if (!$asset instanceof MediaAsset) {
            return;
        }
        $this->bindCover($event, $asset, $key);
    }

    public function attachCoverFromUrl(CatalogEvent $event, string $url, string $fallbackKey = 'other'): void
    {
        if ($this->importer === null) {
            $this->attachCover($event, $fallbackKey);

            return;
        }
        $asset = $this->importer->importFromUrl($url, $event->title(), $event->title());
        if (!$asset instanceof MediaAsset) {
            $this->attachCover($event, $fallbackKey);

            return;
        }
        $this->bindCover($event, $asset, $fallbackKey);
    }

    public function coverMatchesUrl(CatalogEvent $event, string $url): bool
    {
        $id = $event->mediaAssetId();
        if ($id === null) {
            return false;
        }
        $asset = $this->em->find(MediaAsset::class, $id);
        if (!$asset instanceof MediaAsset) {
            return false;
        }

        return str_contains($asset->path(), sha1(trim($url)));
    }

    private function bindCover(CatalogEvent $event, MediaAsset $asset, string $imageKey): void
    {
        $event->setMediaAssetId($asset->id());
        $event->setImageKey($imageKey);
        $existing = $this->em->getRepository(MediaLink::class)->findOneBy([
            'entityType' => 'catalog_event',
            'entityId' => $event->id(),
            'label' => 'cover',
        ]);
        if ($existing instanceof MediaLink) {
            if ($existing->mediaAssetId() === $asset->id()) {
                return;
            }
            $this->em->remove($existing);
        }
        $this->em->persist(new MediaLink($asset->id(), 'catalog_event', $event->id(), 'cover'));
    }

    /** @return array<string, string> key => /media/{id}/file */
    public function urls(): array
    {
        $out = [];
        foreach (array_keys(self::SOURCES) as $key) {
            $asset = $this->importKey($key, 'KdeAco · ' . $key);
            if ($asset instanceof MediaAsset) {
                $url = self::publicUrl($asset->id());
                if ($url !== null) {
                    $out[$key] = $url;
                }
            }
        }

        return $out;
    }

    public function assetIdFor(string $key): ?string
    {
        $asset = $this->importKey($key, 'KdeAco · ' . $key);

        return $asset instanceof MediaAsset ? $asset->id() : null;
    }
}
