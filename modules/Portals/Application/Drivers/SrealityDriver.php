<?php

declare(strict_types=1);

namespace Modules\Portals\Application\Drivers;

use Modules\Portals\Application\Sreality\SrealityAdvertMapper;
use Modules\Portals\Application\Sreality\SrealityApiSpec;
use Modules\Portals\Domain\PortalDriverInterface;

final class SrealityDriver implements PortalDriverInterface
{
    public function __construct(
        private readonly SrealityAdvertMapper $mapper = new SrealityAdvertMapper(),
    ) {
    }

    public function key(): string
    {
        return 'sreality';
    }

    public function buildPayload(array $listing, array $config): array
    {
        /** @var array<string, mixed> $apiSpec */
        $apiSpec = is_array($config['_api_spec'] ?? null) ? $config['_api_spec'] : [];
        if ($apiSpec === []) {
            $apiSpec = SrealityApiSpec::load();
        }

        $advert = $this->mapper->map($listing, $config, $apiSpec);
        $endpoint = (string) ($config['api_base_url'] ?? $apiSpec['endpoint'] ?? 'https://import.sreality.cz/RPC2');

        return [
            'portal' => $this->key(),
            'protocol' => 'xmlrpc',
            'endpoint' => $endpoint,
            'method' => 'addAdvert',
            'external_ready' => true,
            'api_version' => (string) ($apiSpec['version'] ?? '3.0.0'),
            'credentials' => [
                'client_id' => (string) ($config['client_id'] ?? ''),
                'has_password' => trim((string) ($config['password'] ?? '')) !== '',
                'has_software_key' => trim((string) ($config['software_key'] ?? '')) !== '',
            ],
            'advert' => $advert,
            'images' => is_array($listing['images'] ?? null) ? $listing['images'] : [],
            'codebooks_ref' => array_keys(is_array($apiSpec['codebooks'] ?? null) ? $apiSpec['codebooks'] : []),
        ];
    }
}
