<?php

declare(strict_types=1);

namespace Modules\Fleet\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\File\FileManager;
use Gereq\Module\CapabilityRegistry;
use Modules\Media\Application\MediaProvider;
use Modules\Media\Domain\MediaAsset;
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

    /** @return list<array{link: \Modules\Media\Domain\MediaLink, asset: MediaAsset}> */
    public function forVehicle(string $vehicleId, ?string $label = null): array
    {
        return $this->provider()?->forEntity(self::ENTITY_TYPE, $vehicleId, $label) ?? [];
    }

    public function upload(string $vehicleId, UploadedFile $file, string $label = 'photo'): MediaAsset
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
        $provider->attach($asset->id(), self::ENTITY_TYPE, $vehicleId, $label);

        return $asset;
    }
}
