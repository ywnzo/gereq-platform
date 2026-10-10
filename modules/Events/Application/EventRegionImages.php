<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\File\FileManager;
use Gereq\Media\Application\MediaImporter;
use Gereq\Media\Domain\MediaAsset;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Events\Domain\CatalogEvent;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Per-district hero photos for the storefront (admin-managed media asset IDs). */
final class EventRegionImages
{
    public const SETTING_KEY = 'events.region_images';

    /**
     * Default seed files under resources/seed/regions/.
     * Plzeň-jih / Plzeň-sever share plzen until a dedicated photo is uploaded.
     *
     * @var array<string, string>
     */
    public const SEED_FILES = [
        'Domažlice' => 'domazlice.avif',
        'Klatovy' => 'klatovy.avif',
        'Plzeň-město' => 'plzen.avif',
        'Plzeň-jih' => 'plzen.avif',
        'Plzeň-sever' => 'plzen.avif',
        'Rokycany' => 'rokycany.avif',
        'Tachov' => 'tachov.avif',
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
        private readonly FileManager $files,
    ) {
    }

    /** @return array<string, string> region => media asset id */
    public function assetMap(): array
    {
        $raw = $this->settings->getLive(self::SETTING_KEY, []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $region => $assetId) {
            $region = trim((string) $region);
            $assetId = trim((string) $assetId);
            if ($region === '' || $assetId === '') {
                continue;
            }
            $out[$region] = $assetId;
        }

        return $out;
    }

    /** @return array<string, string> region => public /media/…/file URL */
    public function urlMap(): array
    {
        $out = [];
        foreach ($this->assetMap() as $region => $assetId) {
            $url = EventMediaLibrary::publicUrl($assetId);
            if ($url !== null) {
                $out[$region] = $url;
            }
        }

        return $out;
    }

    public function urlFor(string $region): ?string
    {
        $region = trim($region);
        if ($region === '') {
            return null;
        }
        $map = $this->urlMap();

        return $map[$region] ?? null;
    }

    public function assetIdFor(string $region): ?string
    {
        $map = $this->assetMap();
        $id = $map[trim($region)] ?? null;

        return $id !== null && $id !== '' ? $id : null;
    }

    /** Seed missing districts from resources/seed/regions/*.avif. */
    public function seedMissing(): int
    {
        $map = $this->assetMap();
        $importer = MediaImporter::fromBasePath($this->em, base_path());
        $changed = 0;

        foreach (CatalogEvent::REGIONS as $region) {
            if (isset($map[$region]) && $this->em->find(MediaAsset::class, $map[$region]) instanceof MediaAsset) {
                continue;
            }
            $file = self::SEED_FILES[$region] ?? null;
            if ($file === null) {
                continue;
            }
            $absolute = rtrim(base_path(), '/') . '/resources/seed/regions/' . $file;
            if (!is_file($absolute)) {
                continue;
            }
            $asset = $importer->importFromUrl('file://' . $absolute, 'Okres · ' . $region, $region);
            if (!$asset instanceof MediaAsset) {
                continue;
            }
            $map[$region] = $asset->id();
            $changed++;
        }

        if ($changed > 0) {
            $this->persist($map);
        }

        return $changed;
    }

    public function setAsset(string $region, ?string $assetId): void
    {
        $region = trim($region);
        if (!in_array($region, CatalogEvent::REGIONS, true)) {
            throw new \InvalidArgumentException('events.regions.error.unknown');
        }
        $map = $this->assetMap();
        $id = $assetId !== null ? trim($assetId) : '';
        if ($id === '') {
            unset($map[$region]);
        } else {
            $map[$region] = $id;
        }
        $this->persist($map);
    }

    public function storeUpload(string $region, UploadedFile $uploaded, ?string $uploadedBy = null): MediaAsset
    {
        $region = trim($region);
        if (!in_array($region, CatalogEvent::REGIONS, true)) {
            throw new \InvalidArgumentException('events.regions.error.unknown');
        }
        $mime = (string) ($uploaded->getMimeType() ?: '');
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
        if (!in_array($mime, $allowed, true) && !str_starts_with($mime, 'image/')) {
            throw new \InvalidArgumentException('events.error.not_image');
        }

        $stored = $this->files->store($uploaded, null);
        $info = @getimagesize($uploaded->getPathname());
        $asset = new MediaAsset(
            'Okres · ' . $region,
            $stored->path(),
            $stored->mime(),
            $stored->size(),
            $uploadedBy,
            $region,
            is_array($info) ? (int) $info[0] : null,
            is_array($info) ? (int) $info[1] : null,
        );
        $this->em->persist($asset);
        $this->em->flush();
        $this->setAsset($region, $asset->id());

        return $asset;
    }

    /**
     * @return list<array{value: string, label: string, last?: bool, image_url: ?string}>
     */
    public function publicRegions(): array
    {
        $urls = $this->urlMap();
        $values = CatalogEvent::REGIONS;
        $last = array_key_last($values);
        $rows = [];
        foreach ($values as $index => $value) {
            $row = [
                'label' => $value,
                'value' => $value,
                'image_url' => $urls[$value] ?? null,
            ];
            if ($index === $last) {
                $row['last'] = true;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<string, string> $map */
    private function persist(array $map): void
    {
        $ordered = [];
        foreach (CatalogEvent::REGIONS as $region) {
            if (isset($map[$region]) && $map[$region] !== '') {
                $ordered[$region] = $map[$region];
            }
        }
        $this->settings->setLive(self::SETTING_KEY, $ordered, SettingRecord::TYPE_JSON, 'events');
    }
}
