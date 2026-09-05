<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\File\FileManager;
use Gereq\Module\CapabilityRegistry;
use Modules\Media\Application\MediaProvider;
use Modules\Media\Domain\MediaAsset;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Soft Media bridge — no-op when media capability is missing.
 */
final class OrderMediaService
{
    public const ENTITY_TYPE = 'carservice_order';

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

    /**
     * @return list<array{link: \Modules\Media\Domain\MediaLink, asset: MediaAsset}>
     */
    public function forOrder(string $orderId, ?string $phase = null): array
    {
        return $this->provider()?->forEntity(self::ENTITY_TYPE, $orderId, $phase) ?? [];
    }

    public function upload(string $orderId, UploadedFile $file, string $phase): MediaAsset
    {
        $provider = $this->provider();
        if ($provider === null) {
            throw new \RuntimeException('carservice.error.media_unavailable');
        }
        $stored = $this->files->store($file, $this->auth->user());
        $asset = new MediaAsset(
            $stored->originalName(),
            $stored->path(),
            $stored->mime(),
            $stored->size(),
            $this->auth->user()?->id(),
            $phase,
        );
        $this->em->persist($asset);
        $this->em->flush();
        $provider->attach($asset->id(), self::ENTITY_TYPE, $orderId, $phase);

        return $asset;
    }
}
