<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\PartsCatalog\Application\Dto\CatalogNode;
use Modules\PartsCatalog\Domain\CatalogCategory;
use Modules\PartsCatalog\Domain\CatalogVehicle;

/**
 * Strukturované kategorie per vozidlo (partscatalog_categories).
 * Browse nejdřív čte DB; po fetchi z Jama (nebo api_cache) zapisuje.
 */
final class CategoryTreeStore
{
    /** @var array<string, CatalogVehicle|null> */
    private array $vehicleByExternalId = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return list<CatalogNode>|null null = úroveň ještě není v DB
     */
    public function tryLoad(string $externalVehicleId, ?string $parentPath): ?array
    {
        $vehicle = $this->findVehicle($externalVehicleId);
        if ($vehicle === null) {
            return null;
        }

        $parentPath = $this->normalizePath($parentPath);

        if ($parentPath !== null) {
            return $this->resolveParentLevel($vehicle, $externalVehicleId, $parentPath);
        }

        $rows = $this->childrenOf($vehicle->id(), null);
        if ($rows === []) {
            return null;
        }

        return $this->toNodes($rows, $externalVehicleId, null);
    }

    /**
     * Dávkové načtení podstromů (jedno vozidlo + IN na parent paths) — bez N× findVehicle.
     *
     * @param list<string> $parentPaths
     * @return array<string, list<CatalogNode>|null> klíč = normalizovaná parent path
     */
    public function tryLoadMany(string $externalVehicleId, array $parentPaths): array
    {
        $wanted = [];
        foreach ($parentPaths as $path) {
            $normalized = $this->normalizePath($path);
            if ($normalized !== null) {
                $wanted[$normalized] = null;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $vehicle = $this->findVehicle($externalVehicleId);
        if ($vehicle === null) {
            return $wanted;
        }

        /** @var list<CatalogCategory> $parents */
        $parents = $this->em->createQueryBuilder()
            ->select('c')
            ->from(CatalogCategory::class, 'c')
            ->where('c.vehicleId = :vid')
            ->andWhere('c.path IN (:paths)')
            ->setParameter('vid', $vehicle->id())
            ->setParameter('paths', array_keys($wanted))
            ->getQuery()
            ->getResult();

        $parentByPath = [];
        $parentIds = [];
        foreach ($parents as $parent) {
            if (!$parent instanceof CatalogCategory) {
                continue;
            }
            $parentByPath[$parent->path()] = $parent;
            $parentIds[] = $parent->id();
        }

        /** @var array<string, list<CatalogCategory>> $childrenByParentId */
        $childrenByParentId = [];
        if ($parentIds !== []) {
            /** @var list<CatalogCategory> $kids */
            $kids = $this->em->createQueryBuilder()
                ->select('c')
                ->from(CatalogCategory::class, 'c')
                ->where('c.vehicleId = :vid')
                ->andWhere('c.parentId IN (:pids)')
                ->setParameter('vid', $vehicle->id())
                ->setParameter('pids', $parentIds)
                ->orderBy('c.name', 'ASC')
                ->getQuery()
                ->getResult();

            foreach ($kids as $kid) {
                if (!$kid instanceof CatalogCategory || $kid->parentId() === null) {
                    continue;
                }
                $childrenByParentId[$kid->parentId()][] = $kid;
            }
        }

        foreach ($wanted as $path => $_) {
            $parent = $parentByPath[$path] ?? null;
            if (!$parent instanceof CatalogCategory) {
                continue;
            }
            $rows = $childrenByParentId[$parent->id()] ?? [];
            if ($rows !== []) {
                $wanted[$path] = $this->toNodes($rows, $externalVehicleId, $path);
            } elseif (!$parent->hasChildren()) {
                $wanted[$path] = $this->toNodes([], $externalVehicleId, $path);
            }
            // hasChildren + prázdné děti → nechá null (neúplný sync)
        }

        return $wanted;
    }

    /**
     * @return list<CatalogNode>|null
     */
    private function resolveParentLevel(
        CatalogVehicle $vehicle,
        string $externalVehicleId,
        string $parentPath,
    ): ?array {
        $parent = $this->em->getRepository(CatalogCategory::class)->findOneBy([
            'vehicleId' => $vehicle->id(),
            'path' => $parentPath,
        ]);
        if (!$parent instanceof CatalogCategory) {
            return null;
        }

        $rows = $this->childrenOf($vehicle->id(), $parent->id());
        if ($rows !== []) {
            return $this->toNodes($rows, $externalVehicleId, $parentPath);
        }
        if (!$parent->hasChildren()) {
            return $this->toNodes([], $externalVehicleId, $parentPath);
        }

        return null;
    }

    /**
     * @param list<CatalogNode> $nodes
     */
    public function remember(string $externalVehicleId, ?string $parentPath, array $nodes): void
    {
        $vehicle = $this->findVehicle($externalVehicleId);
        if ($vehicle === null) {
            return;
        }

        $parentPath = $this->normalizePath($parentPath);
        $parentEntity = null;

        if ($parentPath !== null) {
            $parentEntity = $this->em->getRepository(CatalogCategory::class)->findOneBy([
                'vehicleId' => $vehicle->id(),
                'path' => $parentPath,
            ]);
            if (!$parentEntity instanceof CatalogCategory) {
                $seg = basename(str_replace('\\', '/', $parentPath));
                $parentEntity = new CatalogCategory(
                    $vehicle->id(),
                    $parentPath,
                    $seg !== '' ? $seg : $parentPath,
                    null,
                    $nodes !== [],
                    false,
                );
                $this->em->persist($parentEntity);
                $this->em->flush();
            } elseif ($nodes !== [] && !$parentEntity->hasChildren()) {
                $parentEntity->updateFlags($parentEntity->name(), true, $parentEntity->hasProducts());
            } elseif ($nodes === [] && $parentEntity->hasChildren()) {
                // Prázdný fetch u dříve označené složky → ber jako list.
                $parentEntity->updateFlags($parentEntity->name(), false, true);
            }
        }

        foreach ($nodes as $node) {
            if (!$node instanceof CatalogNode) {
                continue;
            }
            $this->upsertCategory(
                $vehicle->id(),
                $node->id,
                $node->name,
                $parentEntity?->id(),
                $node->hasChildren,
                $node->hasProducts,
            );
        }

        $this->em->flush();
        CatalogCallLogger::log('category.db.store', [
            'externalId' => mb_substr($externalVehicleId, 0, 120),
            'parent' => $parentPath,
            'count' => count($nodes),
        ]);
    }

    private function findVehicle(string $externalVehicleId): ?CatalogVehicle
    {
        $externalVehicleId = trim($externalVehicleId);
        if ($externalVehicleId === '') {
            return null;
        }

        if (array_key_exists($externalVehicleId, $this->vehicleByExternalId)) {
            return $this->vehicleByExternalId[$externalVehicleId];
        }

        $vehicle = $this->em->getRepository(CatalogVehicle::class)->findOneBy([
            'externalId' => $externalVehicleId,
        ]);
        $resolved = $vehicle instanceof CatalogVehicle ? $vehicle : null;
        $this->vehicleByExternalId[$externalVehicleId] = $resolved;

        return $resolved;
    }

    private function normalizePath(?string $path): ?string
    {
        $path = trim((string) $path, '/');

        return $path === '' ? null : $path;
    }

    /**
     * @return list<CatalogCategory>
     */
    private function childrenOf(string $vehicleId, ?string $parentId): array
    {
        /** @var list<CatalogCategory> $rows */
        $rows = $this->em->getRepository(CatalogCategory::class)->findBy(
            [
                'vehicleId' => $vehicleId,
                'parentId' => $parentId,
            ],
            ['name' => 'ASC'],
        );

        return $rows;
    }

    /**
     * @param list<CatalogCategory> $rows
     * @return list<CatalogNode>
     */
    private function toNodes(array $rows, string $externalVehicleId, ?string $parentPath): array
    {
        CatalogCallLogger::log('category.db.hit', [
            'externalId' => mb_substr($externalVehicleId, 0, 120),
            'parent' => $parentPath,
            'count' => count($rows),
        ]);

        $out = [];
        foreach ($rows as $row) {
            $out[] = new CatalogNode(
                id: $row->path(),
                name: $row->name(),
                nameOriginal: $row->path(),
                hasChildren: $row->hasChildren(),
                hasProducts: $row->hasProducts(),
            );
        }

        return $out;
    }

    private function upsertCategory(
        string $vehicleId,
        string $path,
        string $name,
        ?string $parentId,
        bool $hasChildren,
        bool $hasProducts,
    ): void {
        $cat = $this->em->getRepository(CatalogCategory::class)->findOneBy([
            'vehicleId' => $vehicleId,
            'path' => $path,
        ]);
        if ($cat === null) {
            $this->em->persist(new CatalogCategory(
                $vehicleId,
                $path,
                $name,
                $parentId,
                $hasChildren,
                $hasProducts,
            ));
        } else {
            $cat->updateFlags($name, $hasChildren, $hasProducts);
        }
    }
}
