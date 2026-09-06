<?php

declare(strict_types=1);

namespace Modules\Portals\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Listings\Application\ListingsProvider;
use Modules\Listings\Domain\Listing;
use Modules\Portals\Application\Drivers\BezrealitkyDriver;
use Modules\Portals\Application\Drivers\CustomPortalDriver;
use Modules\Portals\Application\Drivers\IdnesDriver;
use Modules\Portals\Application\Drivers\SrealityDriver;
use Modules\Portals\Domain\PortalAccount;
use Modules\Portals\Domain\PortalDriverInterface;
use Modules\Portals\Domain\PortalProvider;
use Modules\Portals\Domain\PortalPublication;

final class PortalsProvider
{
    /** @var array<string, PortalDriverInterface> */
    private array $drivers;

    public function __construct(
        private readonly EntityManagerInterface $em,
        SrealityDriver $sreality,
        IdnesDriver $idnes,
        BezrealitkyDriver $bezrealitky,
        CustomPortalDriver $custom,
    ) {
        $this->drivers = [
            $sreality->key() => $sreality,
            $idnes->key() => $idnes,
            $bezrealitky->key() => $bezrealitky,
            $custom->key() => $custom,
        ];
    }

    public function findAccount(string $id): ?PortalAccount
    {
        $item = $this->em->find(PortalAccount::class, $id);

        return $item instanceof PortalAccount ? $item : null;
    }

    public function findPublication(string $id): ?PortalPublication
    {
        $item = $this->em->find(PortalPublication::class, $id);

        return $item instanceof PortalPublication ? $item : null;
    }

    public function findPublicationFor(string $accountId, string $listingId): ?PortalPublication
    {
        $item = $this->em->getRepository(PortalPublication::class)->findOneBy([
            'portalAccountId' => $accountId,
            'listingId' => $listingId,
        ]);

        return $item instanceof PortalPublication ? $item : null;
    }

    public function findProvider(string $id): ?PortalProvider
    {
        $item = $this->em->find(PortalProvider::class, $id);

        return $item instanceof PortalProvider ? $item : null;
    }

    public function findProviderByCode(string $code): ?PortalProvider
    {
        $item = $this->em->getRepository(PortalProvider::class)->findOneBy(['code' => strtolower(trim($code))]);

        return $item instanceof PortalProvider ? $item : null;
    }

    public function driverFor(PortalAccount $account): PortalDriverInterface
    {
        $catalog = $this->findProvider($account->providerId());
        $key = $catalog?->driver() ?? 'custom';

        return $this->drivers[$key] ?? $this->drivers['custom'];
    }

    /**
     * Queue a listing for portal export (payload stub; remote sync later).
     */
    public function queuePublication(PortalAccount $account, Listing $listing): PortalPublication
    {
        $pub = $this->findPublicationFor($account->id(), $listing->id());
        if ($pub === null) {
            $pub = new PortalPublication($account->id(), $listing->id());
            $this->em->persist($pub);
        }

        $serialized = $this->serializeListing($listing);
        $catalog = $this->findProvider($account->providerId());
        $config = $account->config();
        if ($catalog !== null) {
            if ($catalog->apiBaseUrl() !== null && !isset($config['api_base_url'])) {
                $config['api_base_url'] = $catalog->apiBaseUrl();
            }
            $spec = $catalog->apiSpec();
            if ($spec !== []) {
                $config['_api_spec'] = $spec;
            }
        }
        $payload = $this->driverFor($account)->buildPayload($serialized, $config);
        $pub->setPayloadSnapshot($payload);
        $pub->markQueued();
        $this->em->flush();

        return $pub;
    }

    /**
     * Mark as published without remote call (dev / manual confirm).
     */
    public function markPublished(PortalPublication $pub, ?string $externalId = null): PortalPublication
    {
        $pub->markSynced($externalId ?? ('manual-' . substr($pub->id(), 0, 8)));
        $this->em->flush();

        return $pub;
    }

    /** @return array<string, mixed> */
    public function serializeAccount(PortalAccount $a): array
    {
        $catalog = $this->findProvider($a->providerId());

        return [
            'id' => $a->id(),
            'code' => $a->code(),
            'name' => $a->name(),
            'provider_id' => $a->providerId(),
            'provider_code' => $catalog?->code(),
            'provider_name' => $catalog?->name(),
            'driver' => $catalog?->driver(),
            'active' => $a->active(),
            'config' => $a->config(),
            'created_at' => $a->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $a->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function serializePublication(PortalPublication $p, bool $detail = false): array
    {
        $data = [
            'id' => $p->id(),
            'portal_account_id' => $p->portalAccountId(),
            'listing_id' => $p->listingId(),
            'external_id' => $p->externalId(),
            'status' => $p->status(),
            'last_error' => $p->lastError(),
            'last_synced_at' => $p->lastSyncedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $p->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $p->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
        if ($detail) {
            $data['payload_snapshot'] = $p->payloadSnapshot();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function serializeListing(Listing $listing): array
    {
        if (class_exists(ListingsProvider::class)) {
            try {
                /** @var ListingsProvider $provider */
                $provider = gereq()->make(ListingsProvider::class);

                return $provider->serialize($listing, true);
            } catch (\Throwable) {
                // fall through
            }
        }

        return [
            'id' => $listing->id(),
            'title' => $listing->title(),
            'slug' => $listing->slug(),
            'price' => $listing->price(),
            'currency' => $listing->currency(),
            'offer_type' => $listing->offerType(),
            'property_type' => $listing->propertyType(),
            'city' => $listing->city(),
            'street' => $listing->street(),
            'address' => $listing->address(),
            'latitude' => $listing->latitude(),
            'longitude' => $listing->longitude(),
            'description' => $listing->description(),
            'images' => $listing->images(),
            'area_m2' => $listing->areaM2(),
            'rooms' => $listing->rooms(),
        ];
    }
}
