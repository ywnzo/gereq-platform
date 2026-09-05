<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Payments\Domain\PaymentMethod;

final class PaymentsProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(string $id): ?PaymentMethod
    {
        $method = $this->em->find(PaymentMethod::class, $id);

        return $method instanceof PaymentMethod ? $method : null;
    }

    public function findByCode(string $code): ?PaymentMethod
    {
        $method = $this->em->getRepository(PaymentMethod::class)->findOneBy([
            'code' => strtoupper(trim($code)),
        ]);

        return $method instanceof PaymentMethod ? $method : null;
    }

    /** @return list<PaymentMethod> */
    public function activeMethods(): array
    {
        return $this->em->getRepository(PaymentMethod::class)->findBy(
            ['active' => true],
            ['sortOrder' => 'ASC', 'name' => 'ASC'],
        );
    }
}
