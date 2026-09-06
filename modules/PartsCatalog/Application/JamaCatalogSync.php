<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\PartsCatalog\Application\Provider\JamaCatalogProvider;
use Modules\PartsCatalog\Domain\CatalogBrand;
use Modules\PartsCatalog\Domain\CatalogCategory;
use Modules\PartsCatalog\Domain\CatalogModel;
use Modules\PartsCatalog\Domain\CatalogPart;
use Modules\PartsCatalog\Domain\CatalogVehicle;
use Modules\PartsCatalog\Domain\CatalogVehiclePart;

/**
 * Full dump katalogu Jama → normalizované tabulky PartsCatalog.
 * Resume přes CatalogVehicle.syncStatus (pending → categories → parts → done).
 */
final class JamaCatalogSync
{
    /** @var callable(string): void|null */
    private $logger = null;

    private int $flushEvery = 25;

    private int $pendingFlush = 0;

    private int $delayMs = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JamaCatalogProvider $jama,
    ) {
    }

    /**
     * @param callable(string): void|null $logger
     * @return array{
     *   brands: int,
     *   models: int,
     *   vehicles: int,
     *   categories: int,
     *   parts: int,
     *   links: int,
     *   vehicles_done: int,
     *   vehicles_error: int,
     *   soft_error: ?string
     * }
     */
    public function sync(
        ?string $onlyBrand = null,
        bool $structureOnly = false,
        bool $partsOnly = false,
        ?int $vehicleLimit = null,
        int $delayMs = 120,
        ?callable $logger = null,
    ): array {
        $this->logger = $logger;
        $this->delayMs = max(0, $delayMs);
        $stats = [
            'brands' => 0,
            'models' => 0,
            'vehicles' => 0,
            'categories' => 0,
            'parts' => 0,
            'links' => 0,
            'vehicles_done' => 0,
            'vehicles_error' => 0,
            'soft_error' => null,
        ];

        if (!$partsOnly) {
            $this->log('Načítám značky z Jama…');
            $brands = $this->jama->listAllBrands();
            if ($brands === []) {
                $stats['soft_error'] = $this->jama->softError() ?? 'Žádné značky (Anubis / síť).';
                // Pokud už máme strukturu v DB, pokračuj aspoň na díly.
                if ($structureOnly) {
                    return $stats;
                }
                $this->log('Remote značky nedostupné — pokračuji z DB vozidel.');
            } else {
                if ($onlyBrand !== null && $onlyBrand !== '') {
                    $needle = mb_strtolower(trim($onlyBrand));
                    $brands = array_values(array_filter(
                        $brands,
                        static fn (array $b): bool => $b['slug'] === $needle
                            || str_contains($b['slug'], $needle)
                            || str_contains(mb_strtolower($b['name']), $needle),
                    ));
                    if ($brands === []) {
                        $stats['soft_error'] = 'Značka „' . $onlyBrand . '“ nenalezena.';

                        return $stats;
                    }
                }

                foreach ($brands as $brandRow) {
                    $brand = $this->upsertBrand($brandRow['slug'], $brandRow['name']);
                    $stats['brands']++;
                    $this->log('Značka: ' . $brand->name() . ' (' . $brand->slug() . ')');

                    $models = $this->jama->listAllModels($brand->slug());
                    $this->throttle();
                    foreach ($models as $modelRow) {
                        $model = $this->upsertModel($brand->id(), $modelRow['slug'], $modelRow['name']);
                        $stats['models']++;

                        $motors = $this->jama->listAllMotors($brand->slug(), $model->slug());
                        $this->throttle();
                        foreach ($motors as $motor) {
                            $created = $this->upsertVehicle(
                                $model->id(),
                                (string) $motor['id'],
                                (string) $motor['motor_slug'],
                                (string) $motor['label'],
                                (string) $motor['path'],
                            );
                            if ($created) {
                                $stats['vehicles']++;
                            }
                        }
                        $model->markSynced();
                        $this->touch();
                    }
                    $brand->markSynced();
                    $this->flush(true);
                    $this->log(sprintf(
                        '  → %s: %d modelů (nová vozidla: %d)',
                        $brand->slug(),
                        count($models),
                        $stats['vehicles'],
                    ));
                }
            }
        } else {
            $this->log('Režim parts-only — přeskakuji discovery značek/modelů.');
        }

        if ($structureOnly) {
            $this->flush(true);
            $this->log('Hotovo (jen struktura značky/modely/motorizace).');

            return $stats;
        }

        $qb = $this->em->createQueryBuilder()
            ->select('v')
            ->from(CatalogVehicle::class, 'v')
            ->where('v.syncStatus != :done')
            ->setParameter('done', CatalogVehicle::STATUS_DONE)
            ->orderBy('v.externalId', 'ASC');

        if ($onlyBrand !== null && $onlyBrand !== '') {
            $needle = mb_strtolower(trim($onlyBrand));
            $qb->andWhere('v.externalId LIKE :pfx OR v.externalId LIKE :pfx2')
                ->setParameter('pfx', 'jama:' . $needle . ':%')
                ->setParameter('pfx2', 'jama:%' . $needle . ':%');
        }
        if ($vehicleLimit !== null && $vehicleLimit > 0) {
            $qb->setMaxResults($vehicleLimit);
        }

        /** @var list<CatalogVehicle> $pending */
        $pending = $qb->getQuery()->getResult();
        $this->log('K synchronizaci kategorií/dílů: ' . count($pending) . ' vozidel');

        foreach ($pending as $i => $vehicleRef) {
            $vehicleId = $vehicleRef->id();
            /** @var CatalogVehicle|null $vehicle */
            $vehicle = $this->em->find(CatalogVehicle::class, $vehicleId);
            if ($vehicle === null) {
                continue;
            }
            $this->log(sprintf('[%d/%d] %s', $i + 1, count($pending), $vehicle->label()));
            try {
                $catStats = $this->syncVehicleTree($vehicleId);
                $stats['categories'] += $catStats['categories'];
                $stats['parts'] += $catStats['parts'];
                $stats['links'] += $catStats['links'];
                $vehicle = $this->em->find(CatalogVehicle::class, $vehicleId);
                if ($vehicle !== null) {
                    $vehicle->setSyncStatus(CatalogVehicle::STATUS_DONE);
                }
                $stats['vehicles_done']++;
            } catch (\Throwable $e) {
                $vehicle = $this->em->find(CatalogVehicle::class, $vehicleId);
                if ($vehicle !== null) {
                    $vehicle->setSyncStatus(CatalogVehicle::STATUS_ERROR, mb_substr($e->getMessage(), 0, 2000));
                }
                $stats['vehicles_error']++;
                $this->log('  ERROR: ' . $e->getMessage());
                CatalogCallLogger::log('jama.sync.vehicle_error', [
                    'vehicle' => $vehicleId,
                    'error' => $e->getMessage(),
                ]);
            }
            $this->flush(true);
        }

        $stats['soft_error'] = $this->jama->softError();
        $this->log('Sync dokončen.');

        return $stats;
    }

    /**
     * @return array{categories: int, parts: int, links: int}
     */
    private function syncVehicleTree(string $vehicleId): array
    {
        $stats = ['categories' => 0, 'parts' => 0, 'links' => 0];
        $vehicle = $this->requireVehicle($vehicleId);
        $vehicle->setSyncStatus(CatalogVehicle::STATUS_CATEGORIES);
        $this->flush(false);
        $externalId = $vehicle->externalId();

        $queue = [null];
        $seenParents = [];
        while ($queue !== []) {
            $parentPath = array_shift($queue);
            $parentKey = $parentPath ?? '';
            if (isset($seenParents[$parentKey])) {
                continue;
            }
            $seenParents[$parentKey] = true;

            $nodes = $this->jama->tree($externalId, $parentPath);
            $this->throttle();
            if ($nodes === [] && $this->jama->softError() !== null && $parentPath === null) {
                throw new \RuntimeException((string) $this->jama->softError());
            }

            $parentEntity = $parentPath !== null
                ? $this->em->getRepository(CatalogCategory::class)->findOneBy([
                    'vehicleId' => $vehicleId,
                    'path' => $parentPath,
                ])
                : null;

            foreach ($nodes as $node) {
                $cat = $this->upsertCategory(
                    $vehicleId,
                    $node->id,
                    $node->name,
                    $parentEntity?->id(),
                    $node->hasChildren,
                    $node->hasProducts,
                );
                $stats['categories']++;

                if ($node->hasChildren) {
                    $queue[] = $node->id;
                }
                if ($node->hasProducts || !$node->hasChildren) {
                    $vehicle = $this->requireVehicle($vehicleId);
                    $vehicle->setSyncStatus(CatalogVehicle::STATUS_PARTS);
                    $partStats = $this->syncCategoryProducts($externalId, $vehicleId, $cat->id(), $cat->path());
                    $stats['parts'] += $partStats['parts'];
                    $stats['links'] += $partStats['links'];
                }
            }
            // Pravidelný flush bez clear — identity map roste, ale statusy zůstanou managed.
            $this->flush(false);
        }

        return $stats;
    }

    /**
     * @return array{parts: int, links: int}
     */
    private function syncCategoryProducts(
        string $externalVehicleId,
        string $vehicleId,
        string $categoryId,
        string $categoryPath,
    ): array {
        $stats = ['parts' => 0, 'links' => 0];
        $rows = $this->jama->productsRaw($externalVehicleId, $categoryPath);
        $this->throttle();

        foreach ($rows as $row) {
            $externalId = mb_substr((string) $row['id'], 0, 255);
            $part = $this->em->getRepository(CatalogPart::class)->findOneBy([
                'provider' => 'jama',
                'externalId' => $externalId,
            ]);
            $imageUrl = isset($row['image_url']) ? (string) $row['image_url'] : null;
            $sourceUrl = null;
            if (isset($row['attrs']['url']) && is_string($row['attrs']['url']) && $row['attrs']['url'] !== '') {
                $sourceUrl = str_starts_with($row['attrs']['url'], 'http')
                    ? $row['attrs']['url']
                    : 'https://www.autodily-jama.cz' . $row['attrs']['url'];
            }
            $attrsJson = isset($row['attrs']) ? json_encode($row['attrs'], JSON_UNESCAPED_UNICODE) : null;
            if ($part === null) {
                $part = new CatalogPart(
                    'jama',
                    $externalId,
                    (string) $row['name'],
                    isset($row['oem']) ? (string) $row['oem'] : null,
                    isset($row['brand']) ? (string) $row['brand'] : null,
                    $imageUrl,
                    $sourceUrl,
                    $attrsJson !== false ? $attrsJson : null,
                );
                $this->em->persist($part);
                $stats['parts']++;
            } else {
                $part->refresh(
                    (string) $row['name'],
                    isset($row['oem']) ? (string) $row['oem'] : null,
                    isset($row['brand']) ? (string) $row['brand'] : null,
                    $imageUrl,
                    $sourceUrl,
                    $attrsJson !== false ? $attrsJson : null,
                );
            }

            $link = $this->em->getRepository(CatalogVehiclePart::class)->findOneBy([
                'vehicleId' => $vehicleId,
                'categoryId' => $categoryId,
                'partId' => $part->id(),
            ]);
            if ($link === null) {
                $this->em->persist(new CatalogVehiclePart($vehicleId, $categoryId, $part->id()));
                $stats['links']++;
            }
            $this->touch();
        }

        return $stats;
    }

    private function requireVehicle(string $vehicleId): CatalogVehicle
    {
        $vehicle = $this->em->find(CatalogVehicle::class, $vehicleId);
        if ($vehicle === null) {
            throw new \RuntimeException('Vozidlo ' . $vehicleId . ' zmizelo z DB během syncu.');
        }

        return $vehicle;
    }

    private function upsertBrand(string $slug, string $name): CatalogBrand
    {
        $brand = $this->em->getRepository(CatalogBrand::class)->findOneBy([
            'provider' => 'jama',
            'slug' => $slug,
        ]);
        if ($brand === null) {
            $brand = new CatalogBrand('jama', $slug, $name);
            $this->em->persist($brand);
        } else {
            $brand->rename($name);
        }
        $this->touch();

        return $brand;
    }

    private function upsertModel(string $brandId, string $slug, string $name): CatalogModel
    {
        $model = $this->em->getRepository(CatalogModel::class)->findOneBy([
            'brandId' => $brandId,
            'slug' => $slug,
        ]);
        if ($model === null) {
            $model = new CatalogModel($brandId, $slug, $name);
            $this->em->persist($model);
        } else {
            $model->rename($name);
        }
        $this->touch();

        return $model;
    }

    private function upsertVehicle(
        string $modelId,
        string $externalId,
        string $motorSlug,
        string $label,
        string $path,
    ): bool {
        $vehicle = $this->em->getRepository(CatalogVehicle::class)->findOneBy([
            'externalId' => $externalId,
        ]);
        if ($vehicle === null) {
            $vehicle = new CatalogVehicle($modelId, $externalId, $motorSlug, $label, $path);
            $this->em->persist($vehicle);
            $this->touch();

            return true;
        }
        $vehicle->refreshMeta($label, $path);
        $this->touch();

        return false;
    }

    private function upsertCategory(
        string $vehicleId,
        string $path,
        string $name,
        ?string $parentId,
        bool $hasChildren,
        bool $hasProducts,
    ): CatalogCategory {
        $cat = $this->em->getRepository(CatalogCategory::class)->findOneBy([
            'vehicleId' => $vehicleId,
            'path' => $path,
        ]);
        if ($cat === null) {
            $cat = new CatalogCategory($vehicleId, $path, $name, $parentId, $hasChildren, $hasProducts);
            $this->em->persist($cat);
        } else {
            $cat->updateFlags($name, $hasChildren, $hasProducts);
        }
        $this->touch();

        return $cat;
    }

    private function touch(): void
    {
        $this->pendingFlush++;
        if ($this->pendingFlush >= $this->flushEvery) {
            $this->flush(false);
        }
    }

    private function flush(bool $clear = false): void
    {
        $this->em->flush();
        $this->pendingFlush = 0;
        if ($clear) {
            $this->em->clear();
        }
    }

    private function throttle(): void
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
        CatalogCallLogger::log('jama.sync', ['msg' => $message]);
    }
}
