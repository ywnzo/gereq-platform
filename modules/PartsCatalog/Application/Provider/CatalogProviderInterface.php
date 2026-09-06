<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Application\Provider;

use Modules\PartsCatalog\Application\Dto\CatalogArticle;
use Modules\PartsCatalog\Application\Dto\CatalogNode;
use Modules\PartsCatalog\Application\Dto\CatalogVehicleOption;

interface CatalogProviderInterface
{
    public function id(): string;

    public function isConfigured(): bool;

    /** @return list<CatalogVehicleOption> */
    public function vehicles(?string $query = null): array;

    /** @return list<CatalogNode> */
    public function tree(string $vehicleId, ?string $parentNodeId = null): array;

    /** @return list<CatalogArticle> */
    public function products(string $vehicleId, string $nodeId): array;
}
