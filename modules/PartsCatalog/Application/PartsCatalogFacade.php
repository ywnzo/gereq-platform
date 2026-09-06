<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Application;

use Modules\PartsCatalog\Application\Dto\CatalogArticle;
use Modules\PartsCatalog\Application\Dto\CatalogNode;
use Modules\PartsCatalog\Application\Dto\CatalogProductDetail;
use Modules\PartsCatalog\Application\Dto\CatalogVehicleOption;
use Modules\PartsCatalog\Application\Provider\CatalogProviderInterface;
use Modules\PartsCatalog\Application\Provider\JamaCatalogProvider;

final class PartsCatalogFacade
{
    public function __construct(
        private readonly JamaCatalogProvider $jama,
    ) {
    }

    public function activeProviderId(): string
    {
        return 'jama';
    }

    public function provider(?string $id = null): CatalogProviderInterface
    {
        return $this->jama;
    }

    /** @return list<array{id: string, label: string, configured: bool}> */
    public function providersMeta(): array
    {
        return [
            ['id' => 'jama', 'label' => 'Autodíly Jama', 'configured' => $this->jama->isConfigured()],
        ];
    }

    /** @return list<CatalogVehicleOption> */
    public function vehicles(?string $query = null): array
    {
        return $this->jama->vehicles($query);
    }

    /** @return list<CatalogNode> */
    public function tree(string $vehicleId, ?string $parentNodeId = null): array
    {
        return $this->jama->tree($vehicleId, $parentNodeId);
    }

    /** @return list<CatalogArticle> */
    public function products(string $vehicleId, string $nodeId): array
    {
        return $this->jama->products($vehicleId, $nodeId);
    }

    public function productDetail(string $productPath): ?CatalogProductDetail
    {
        return $this->jama->productDetail($productPath);
    }

    /**
     * @return list<CatalogArticle>
     */
    public function searchByArticle(string $article): array
    {
        $article = trim($article);
        if ($article === '') {
            return [];
        }

        return $this->jama->searchByArticle($article);
    }

    public function suggestCatalogVehicleId(?string $brand, ?string $model): ?string
    {
        return $this->jama->suggestVehicleId($brand, $model);
    }

    public function softError(): ?string
    {
        return $this->jama->softError();
    }
}
