<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Orders\Domain\Order;

final class OrdersProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<Order> */
    public function forCustomer(string $customerId, int $limit = 10): array
    {
        return $this->em->getRepository(Order::class)->findBy(
            ['customerId' => $customerId],
            ['createdAt' => 'DESC'],
            $limit,
        );
    }
}
