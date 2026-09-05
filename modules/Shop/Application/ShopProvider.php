<?php

declare(strict_types=1);

namespace Modules\Shop\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Shop\Domain\Cart;
use Modules\Shop\Domain\ShippingMethod;

final class ShopProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function openCartsCount(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Cart::class, 'c')
            ->where('c.status = :status')
            ->setParameter('status', 'open')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findCart(string $id): ?Cart
    {
        $cart = $this->em->find(Cart::class, $id);

        return $cart instanceof Cart ? $cart : null;
    }

    /** @return list<ShippingMethod> */
    public function activeShippingMethods(): array
    {
        return $this->em->getRepository(ShippingMethod::class)->findBy(
            ['active' => true],
            ['sortOrder' => 'ASC', 'name' => 'ASC'],
        );
    }
}
