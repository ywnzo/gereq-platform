<?php

declare(strict_types=1);

namespace Modules\Fleet\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\File\FileManager;
use Gereq\Module\CapabilityRegistry;
use Modules\Media\Application\MediaProvider;
use Modules\Media\Domain\MediaAsset;
use Modules\Media\Domain\MediaLink;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class VehicleMediaService
{
    public const ENTITY_TYPE = 'fleet_vehicle';

    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly EntityManagerInterface $em,
        private readonly FileManager $files,
        private readonly Authenticator $auth,
    ) {
    }

    public function available(): bool
    {
        return $this->capabilities->has('media') && class_exists(MediaAsset::class);
    }

    public function provider(): ?MediaProvider
    {
        if (!$this->available()) {
            return null;
        }

        return $this->capabilities->get('media');
    }

    /** @return list<array{link: MediaLink, asset: MediaAsset}> */
    public function forVehicle(string $vehicleId, ?string $label = null): array
    {
        $items = $this->provider()?->forEntity(self::ENTITY_TYPE, $vehicleId, $label) ?? [];
        usort($items, static function (array $a, array $b): int {
            $order = $a['link']->sortOrder() <=> $b['link']->sortOrder();
            if ($order !== 0) {
                return $order;
            }

            // Stejné sort_order → první nahraná dřív (defaultní cover).
            return $a['link']->createdAt() <=> $b['link']->createdAt();
        });

        return $items;
    }

    public function coverPhoto(string $vehicleId): ?MediaAsset
    {
        if ($vehicleId === '' || !$this->available()) {
            return null;
        }
        try {
            $items = $this->forVehicle($vehicleId);
        } catch (\Throwable) {
            return null;
        }
        foreach ($items as $row) {
            $asset = $row['asset'] ?? null;
            if ($asset instanceof MediaAsset && $asset->isImage()) {
                return $asset;
            }
        }

        return null;
    }

    public function coverLinkId(string $vehicleId): ?string
    {
        foreach ($this->forVehicle($vehicleId) as $row) {
            $asset = $row['asset'] ?? null;
            $link = $row['link'] ?? null;
            if ($asset instanceof MediaAsset && $asset->isImage() && $link instanceof MediaLink) {
                return $link->id();
            }
        }

        return null;
    }

    /**
     * @param list<\Modules\Fleet\Domain\Vehicle|object> $vehicles
     * @return array<string, MediaAsset>
     */
    public function coversForVehicles(array $vehicles): array
    {
        $out = [];
        foreach ($vehicles as $vehicle) {
            if (!is_object($vehicle) || !method_exists($vehicle, 'id')) {
                continue;
            }
            $id = (string) $vehicle->id();
            if ($id === '' || isset($out[$id])) {
                continue;
            }
            $cover = $this->coverPhoto($id);
            if ($cover instanceof MediaAsset) {
                $out[$id] = $cover;
            }
        }

        return $out;
    }

    public function upload(string $vehicleId, UploadedFile $file, string $label = 'photo', ?int $sortOrder = null): MediaAsset
    {
        $provider = $this->provider();
        if ($provider === null) {
            throw new \RuntimeException('fleet.error.media_unavailable');
        }
        $stored = $this->files->store($file, $this->auth->user());
        $asset = new MediaAsset(
            $stored->originalName(),
            $stored->path(),
            $stored->mime(),
            $stored->size(),
            $this->auth->user()?->id(),
            $label,
        );
        $this->em->persist($asset);
        $this->em->flush();
        $order = $sortOrder ?? $this->nextSortOrder($vehicleId, $asset->isImage());
        $provider->attach($asset->id(), self::ENTITY_TYPE, $vehicleId, $label, $order);

        return $asset;
    }

    /**
     * @param list<UploadedFile> $files
     * @return int počet úspěšně nahraných
     */
    public function uploadMany(string $vehicleId, array $files, string $label = 'photo'): int
    {
        $count = 0;
        $hasCover = $this->coverPhoto($vehicleId) !== null;
        $next = $this->maxSortOrder($vehicleId) + 1;
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }
            $mime = (string) ($file->getMimeType() ?? '');
            $isImage = str_starts_with($mime, 'image/');
            if (!$hasCover && $isImage) {
                $sort = 0;
                $hasCover = true;
                if ($next < 1) {
                    $next = 1;
                }
            } else {
                $sort = $next++;
            }
            $this->upload($vehicleId, $file, $label, $sort);
            ++$count;
        }

        return $count;
    }

    /**
     * Nahrání s automatickým odhadem typu podle MIME / názvu souboru.
     *
     * @param list<UploadedFile> $files
     */
    public function uploadManyGuessed(string $vehicleId, array $files): int
    {
        $count = 0;
        $hasCover = $this->coverPhoto($vehicleId) !== null;
        $next = $this->maxSortOrder($vehicleId) + 1;
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }
            $mime = (string) ($file->getMimeType() ?? '');
            $isImage = str_starts_with($mime, 'image/');
            if (!$hasCover && $isImage) {
                $sort = 0;
                $hasCover = true;
                if ($next < 1) {
                    $next = 1;
                }
            } else {
                $sort = $next++;
            }
            $this->upload($vehicleId, $file, self::guessLabel($file), $sort);
            ++$count;
        }

        return $count;
    }

    public static function guessLabel(UploadedFile $file): string
    {
        $mime = strtolower((string) ($file->getMimeType() ?? ''));
        if (str_starts_with($mime, 'image/')) {
            return 'photo';
        }

        $name = strtolower((string) ($file->getClientOriginalName() ?: $file->getFilename()));
        $name = strtr($name, [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
            'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
            'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        ]);

        if (preg_match('/\b(stk|mot|technicka|tech[-_ ]?prohlidka)\b/', $name) === 1) {
            return 'stk';
        }
        if (preg_match('/\b(orv|osvedceni|technicky[-_ ]?prukaz|tp)\b/', $name) === 1) {
            return 'orv';
        }
        if (str_contains($mime, 'pdf') || str_ends_with($name, '.pdf')) {
            return 'document';
        }

        return 'photo';
    }

    public function setCover(string $vehicleId, string $linkId): void
    {
        $items = $this->forVehicle($vehicleId);
        $target = null;
        foreach ($items as $row) {
            if ($row['link']->id() === $linkId && $row['asset']->isImage()) {
                $target = $row['link'];
                break;
            }
        }
        if (!$target instanceof MediaLink) {
            throw new \InvalidArgumentException('fleet.error.media_not_found');
        }
        $order = 1;
        foreach ($items as $row) {
            $link = $row['link'];
            if (!$row['asset']->isImage()) {
                continue;
            }
            if ($link->id() === $target->id()) {
                $link->setSortOrder(0);
            } else {
                $link->setSortOrder($order++);
            }
        }
        $this->em->flush();
    }

    private function nextSortOrder(string $vehicleId, bool $isImage): int
    {
        if ($isImage && $this->coverPhoto($vehicleId) === null) {
            return 0;
        }

        return $this->maxSortOrder($vehicleId) + 1;
    }

    private function maxSortOrder(string $vehicleId): int
    {
        $max = -1;
        foreach ($this->forVehicle($vehicleId) as $row) {
            $max = max($max, $row['link']->sortOrder());
        }

        return $max;
    }
}
