<?php

declare(strict_types=1);

namespace Modules\ProductDiscounts\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\ProductDiscounts\Domain\ProductDiscount;

final class ProductDiscountsProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findForProduct(string $productId): ?ProductDiscount
    {
        $discount = $this->em->getRepository(ProductDiscount::class)->findOneBy(['productId' => $productId]);

        return $discount instanceof ProductDiscount ? $discount : null;
    }

    public function findActiveForProduct(string $productId, ?\DateTimeImmutable $now = null): ?ProductDiscount
    {
        $discount = $this->findForProduct($productId);
        if ($discount === null || !$discount->isCurrentlyActive($now)) {
            return null;
        }

        return $discount;
    }

    /**
     * @return array{price: string, original_price: ?string, discount: ?array<string, mixed>}
     */
    public function priceForProduct(string $productId, string|float $basePrice, ?\DateTimeImmutable $now = null): array
    {
        $base = number_format((float) $basePrice, 2, '.', '');
        $discount = $this->findActiveForProduct($productId, $now);
        if ($discount === null) {
            return [
                'price' => $base,
                'original_price' => null,
                'discount' => null,
            ];
        }

        $priced = $this->applyCatalogDiscount($base, $discount->kind(), (float) $discount->value());

        return [
            'price' => $priced['price'],
            'original_price' => $priced['original_price'],
            'discount' => [
                'kind' => $discount->kind(),
                'value' => $discount->value(),
                'description' => $discount->description(),
            ],
        ];
    }

    /**
     * @return array{price: string, original_price: ?string}
     */
    public function applyCatalogDiscount(string|float $basePrice, ?string $kind, ?float $value): array
    {
        $base = (float) $basePrice;
        $effective = $base;
        if ($kind !== null && $value !== null) {
            if ($kind === 'percent') {
                $effective = max(0, round($base * (1 - $value / 100) * 100) / 100);
            } elseif ($kind === 'amount') {
                $effective = max(0, round(($base - $value) * 100) / 100);
            }
        }

        if ($kind !== null && abs($effective - $base) > 0.00001) {
            return [
                'price' => number_format($effective, 2, '.', ''),
                'original_price' => number_format($base, 2, '.', ''),
            ];
        }

        return [
            'price' => number_format($base, 2, '.', ''),
            'original_price' => null,
        ];
    }

    /**
     * @param array{
     *   kind?: string,
     *   value?: string|float|int,
     *   description?: ?string,
     *   starts_at?: ?\DateTimeImmutable,
     *   ends_at?: ?\DateTimeImmutable,
     *   active?: bool
     * } $data
     */
    public function upsert(string $productId, array $data): ProductDiscount
    {
        $discount = $this->findForProduct($productId);
        if ($discount === null) {
            $discount = new ProductDiscount(
                $productId,
                (string) ($data['kind'] ?? 'percent'),
                (string) ($data['value'] ?? '0'),
            );
            $this->em->persist($discount);
        } else {
            if (isset($data['kind'])) {
                $discount->setKind((string) $data['kind']);
            }
            if (array_key_exists('value', $data)) {
                $discount->setValue((string) $data['value']);
            }
        }

        if (array_key_exists('description', $data)) {
            $discount->setDescription($data['description'] !== null ? (string) $data['description'] : null);
        }
        if (array_key_exists('starts_at', $data)) {
            $discount->setStartsAt($data['starts_at'] instanceof \DateTimeImmutable ? $data['starts_at'] : null);
        }
        if (array_key_exists('ends_at', $data)) {
            $discount->setEndsAt($data['ends_at'] instanceof \DateTimeImmutable ? $data['ends_at'] : null);
        }
        if (array_key_exists('active', $data)) {
            $discount->setActive((bool) $data['active']);
        }

        $this->em->flush();

        return $discount;
    }

    public function deleteForProduct(string $productId): bool
    {
        $discount = $this->findForProduct($productId);
        if ($discount === null) {
            return false;
        }
        $this->em->remove($discount);
        $this->em->flush();

        return true;
    }

    /** @return array<string, mixed> */
    public function serialize(?ProductDiscount $discount): ?array
    {
        if ($discount === null) {
            return null;
        }

        return [
            'id' => $discount->id(),
            'product_id' => $discount->productId(),
            'kind' => $discount->kind(),
            'value' => $discount->value(),
            'description' => $discount->description(),
            'starts_at' => $discount->startsAt()?->format(\DateTimeInterface::ATOM),
            'ends_at' => $discount->endsAt()?->format(\DateTimeInterface::ATOM),
            'active' => $discount->isActive(),
            'currently_active' => $discount->isCurrentlyActive(),
        ];
    }
}
