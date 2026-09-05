<?php

declare(strict_types=1);

namespace Modules\Customers\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\PasswordHasher;
use Gereq\Event\EventBus;
use Modules\Customers\Domain\Customer;
use Modules\Customers\Domain\CustomerAccount;

final class StoreAuthService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordHasher $hasher,
        private readonly EventBus $events,
    ) {
    }

    public function findAccountByEmail(string $email): ?CustomerAccount
    {
        $account = $this->em->getRepository(CustomerAccount::class)->findOneBy([
            'email' => strtolower(trim($email)),
        ]);

        return $account instanceof CustomerAccount ? $account : null;
    }

    public function findAccountByCustomerId(string $customerId): ?CustomerAccount
    {
        $account = $this->em->getRepository(CustomerAccount::class)->findOneBy(['customerId' => $customerId]);

        return $account instanceof CustomerAccount ? $account : null;
    }

    /**
     * Soft registration: customer + account without password (inactive until completed).
     */
    public function softRegister(string $email, string $name, ?string $phone = null): CustomerAccount
    {
        $email = strtolower(trim($email));
        $existing = $this->findAccountByEmail($email);
        if ($existing instanceof CustomerAccount) {
            return $existing;
        }

        $customer = $this->em->getRepository(Customer::class)->findOneBy(['email' => $email]);
        if (!$customer instanceof Customer) {
            $customer = new Customer($name !== '' ? $name : $email, $email);
            if ($phone !== null && $phone !== '') {
                $customer->setPhone($phone);
            }
            $this->em->persist($customer);
        }

        $account = new CustomerAccount($customer->id(), $email);
        $this->em->persist($account);
        $this->em->flush();

        $this->events->emit('customer.soft_registered', ['type' => 'customer_account', 'id' => $account->id()], [
            'customer_id' => $customer->id(),
            'email' => $email,
        ]);

        return $account;
    }

    public function register(string $email, string $password, string $name, ?string $phone = null): CustomerAccount
    {
        $email = strtolower(trim($email));
        if ($this->findAccountByEmail($email) !== null) {
            throw new \InvalidArgumentException('customers.error.email_taken');
        }

        $customer = $this->em->getRepository(Customer::class)->findOneBy(['email' => $email]);
        if (!$customer instanceof Customer) {
            $customer = new Customer($name !== '' ? $name : $email, $email);
            if ($phone) {
                $customer->setPhone($phone);
            }
            $this->em->persist($customer);
        }

        $account = new CustomerAccount($customer->id(), $email);
        $account->setPasswordHash($this->hasher->hash($password));
        $account->activate();
        $account->markEmailVerified();
        $this->em->persist($account);
        $this->em->flush();

        $this->events->emit('customer.registered', ['type' => 'customer_account', 'id' => $account->id()], [
            'customer_id' => $customer->id(),
            'email' => $email,
        ]);

        return $account;
    }

    public function completeRegistration(CustomerAccount $account, string $password, ?string $name = null): void
    {
        $account->setPasswordHash($this->hasher->hash($password));
        $account->activate();
        $account->markEmailVerified();
        if ($name !== null && $name !== '') {
            $customer = $this->em->find(Customer::class, $account->customerId());
            if ($customer instanceof Customer) {
                $customer->setName($name);
            }
        }
        $this->em->flush();
        $this->events->emit('customer.registration_completed', ['type' => 'customer_account', 'id' => $account->id()], [
            'customer_id' => $account->customerId(),
            'email' => $account->email(),
        ]);
    }

    public function attempt(string $email, string $password): ?CustomerAccount
    {
        $account = $this->findAccountByEmail($email);
        if (!$account instanceof CustomerAccount || !$account->isActive() || $account->passwordHash() === null) {
            return null;
        }
        if (!$this->hasher->verify($password, $account->passwordHash())) {
            return null;
        }

        return $account;
    }

    /** Opaque guest/order access token (store hash on Order). */
    public function issueOrderAccessToken(): array
    {
        $plain = bin2hex(random_bytes(24));

        return [
            'token' => $plain,
            'hash' => hash('sha256', $plain),
        ];
    }

    public function verifyOrderAccessToken(string $plain, ?string $hash): bool
    {
        if ($hash === null || $hash === '' || $plain === '') {
            return false;
        }

        return hash_equals($hash, hash('sha256', $plain));
    }

    /** @return array<string, mixed> */
    public function serialize(CustomerAccount $account): array
    {
        return [
            'id' => $account->id(),
            'customer_id' => $account->customerId(),
            'email' => $account->email(),
            'is_active' => $account->isActive(),
            'is_soft' => $account->isSoft(),
            'email_verified_at' => $account->emailVerifiedAt()?->format(\DateTimeInterface::ATOM),
            'token_version' => $account->tokenVersion(),
            'created_at' => $account->createdAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
