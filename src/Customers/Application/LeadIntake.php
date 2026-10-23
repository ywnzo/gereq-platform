<?php

declare(strict_types=1);

namespace Gereq\Customers\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Customers\Domain\Customer;

/** Public intake may create a lead, but cannot edit a customer identified only by email. */
final class LeadIntake
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findOrCreate(string $name, string $email, string $phone, string $note): string
    {
        $email = strtolower(trim($email));
        $existing = $this->em->getRepository(Customer::class)->findOneBy(['email' => $email]);
        if ($existing instanceof Customer) {
            return $existing->id();
        }
        $customer = new Customer($name, $email);
        $customer->setPhone($phone);
        $customer->setStatus('lead');
        $customer->setNote($note);
        $this->em->persist($customer);
        $this->em->flush();
        return $customer->id();
    }
}
