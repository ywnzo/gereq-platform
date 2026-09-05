<?php

declare(strict_types=1);

namespace Modules\Products\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Products\Domain\Product;
use Modules\Products\Domain\ProductUserImageRule;

final class ProductPersonalizationService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findRule(string $productId): ?ProductUserImageRule
    {
        return $this->findRulesByProductIds([$productId])[$productId] ?? null;
    }

    /**
     * @param list<string> $productIds
     * @return array<string, ProductUserImageRule>
     */
    public function findRulesByProductIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(
            $productIds,
            static fn (string $id): bool => $id !== '',
        )));
        if ($productIds === []) {
            return [];
        }

        /** @var list<ProductUserImageRule> $rows */
        $rows = $this->em->getRepository(ProductUserImageRule::class)->createQueryBuilder('r')
            ->where('r.productId IN (:ids)')
            ->setParameter('ids', $productIds)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $rule) {
            if ($rule instanceof ProductUserImageRule) {
                $map[$rule->productId()] = $rule;
            }
        }

        return $map;
    }

    public function activeRule(string $productId): ?ProductUserImageRule
    {
        $rule = $this->findRule($productId);

        return $rule !== null && $rule->isActive() && $rule->allowsImages() ? $rule : null;
    }

    /**
     * @param list<string> $productIds
     * @return array<string, ProductUserImageRule>
     */
    public function activeRulesByProductIds(array $productIds): array
    {
        $map = [];
        foreach ($this->findRulesByProductIds($productIds) as $productId => $rule) {
            if ($rule->isActive() && $rule->allowsImages()) {
                $map[$productId] = $rule;
            }
        }

        return $map;
    }

    /**
     * @param array{
     *   enabled?: bool,
     *   max_images?: int,
     *   crop_required?: bool,
     *   crop_config?: array<string, mixed>
     * } $data
     */
    public function upsertRule(string $productId, array $data): ?ProductUserImageRule
    {
        $enabled = (bool) ($data['enabled'] ?? false);
        $rule = $this->findRule($productId);

        if (!$enabled) {
            if ($rule !== null) {
                $rule->setActive(false);
                $this->em->flush();
            }

            return $rule;
        }

        if ($rule === null) {
            $rule = new ProductUserImageRule($productId);
            $this->em->persist($rule);
        }
        $rule->setActive(true);
        $rule->setMaxImages((int) ($data['max_images'] ?? 1));
        $rule->setCropRequired((bool) ($data['crop_required'] ?? false));
        if (isset($data['crop_config']) && is_array($data['crop_config'])) {
            $rule->setCropConfig($data['crop_config']);
        }
        $this->em->flush();

        return $rule;
    }

    /**
     * @param array{rule?: ?ProductUserImageRule}|null $preloaded
     * @return array<string, mixed>
     */
    public function serializeForStore(Product $product, ?array $preloaded = null): array
    {
        $rule = array_key_exists('rule', $preloaded ?? [])
            ? $preloaded['rule']
            : $this->activeRule($product->id());
        $out = [
            'customer_note_mode' => $product->customerNoteMode(),
            'user_image_rule' => null,
        ];
        if ($rule !== null) {
            $out['user_image_rule'] = [
                'max_images' => $rule->maxImages(),
                'crop_required' => $rule->cropRequired(),
                'required_count' => $rule->requiredImageCount(),
                'crop_config' => $rule->cropConfig(),
            ];
        }

        return $out;
    }

    public function assertLinePersonalization(Product $product, ?string $note, array $uploadIds): void
    {
        $mode = $product->customerNoteMode();
        $note = trim((string) $note);
        if ($mode === 'required' && $note === '') {
            throw new \InvalidArgumentException('products.error.note_required');
        }

        $rule = $this->activeRule($product->id());
        $count = count($uploadIds);
        if ($rule === null) {
            if ($count > 0) {
                throw new \InvalidArgumentException('products.error.images_not_allowed');
            }

            return;
        }

        $required = $rule->requiredImageCount();
        if ($required > 0 && $count < $required) {
            throw new \InvalidArgumentException('products.error.images_required');
        }
        if ($rule->maxImages() > 0 && $count > $rule->maxImages()) {
            throw new \InvalidArgumentException('products.error.images_too_many');
        }
    }
}
