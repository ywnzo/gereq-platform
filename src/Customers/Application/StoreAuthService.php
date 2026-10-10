<?php

declare(strict_types=1);

namespace Gereq\Customers\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Audit\Auditor;
use Gereq\Auth\PasswordHasher;
use Gereq\Event\EventBus;
use Gereq\Hook\HookManager;
use Gereq\Module\CapabilityRegistry;
use Gereq\Customers\Domain\Customer;
use Gereq\Customers\Domain\CustomerAccount;

final class StoreAuthService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordHasher $hasher,
        private readonly EventBus $events,
        private readonly Auditor $auditor,
        private readonly CapabilityRegistry $capabilities,
        private readonly HookManager $hooks,
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

    /**
     * @param array{region?: mixed, locality?: mixed, newsletter?: mixed, categories?: mixed} $prefs
     */
    public function register(string $email, string $password, string $name, ?string $phone = null, array $prefs = []): CustomerAccount
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

        $this->applyPrefs($customer, $prefs, true);

        $account = new CustomerAccount($customer->id(), $email);
        $account->setPasswordHash($this->hasher->hash($password));
        $account->activate();
        // Do not mark email verified here — password registration has no mailbox proof.
        // OAuth providers call markEmailVerified() after provider-side verification.
        $this->em->persist($account);
        $this->em->flush();

        $this->syncNewsletter($customer);

        $this->events->emit('customer.registered', ['type' => 'customer_account', 'id' => $account->id()], [
            'customer_id' => $customer->id(),
            'email' => $email,
            'newsletter' => $customer->newsletterOptIn(),
            'region' => $customer->preferredRegion(),
            'locality' => $customer->locality(),
        ]);

        return $account;
    }

    public function completeRegistration(CustomerAccount $account, string $password, ?string $name = null): void
    {
        if (!$account->isSoft()) {
            throw new \InvalidArgumentException('customers.error.already_registered');
        }

        $account->setPasswordHash($this->hasher->hash($password));
        $account->activate();
        $account->bumpTokenVersion();
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

    /**
     * Update storefront profile fields (name/phone) and optional password.
     *
     * @param array{name?: string|null, phone?: string|null, current_password?: string|null, password?: string|null, region?: mixed, locality?: mixed, newsletter?: mixed, categories?: mixed} $data
     */
    public function updateProfile(CustomerAccount $account, array $data): CustomerAccount
    {
        $customer = $this->em->find(Customer::class, $account->customerId());
        if (!$customer instanceof Customer) {
            throw new \InvalidArgumentException('Customer not found');
        }

        if (array_key_exists('name', $data) && $data['name'] !== null) {
            $name = trim((string) $data['name']);
            if ($name !== '') {
                $customer->setName($name);
            }
        }

        if (array_key_exists('phone', $data)) {
            $phone = $data['phone'] !== null ? trim((string) $data['phone']) : null;
            $customer->setPhone($phone !== '' ? $phone : null);
        }

        $this->applyPrefs($customer, $data, false);

        $newPassword = isset($data['password']) ? (string) $data['password'] : '';
        $firstPassword = false;
        if ($newPassword !== '') {
            $current = (string) ($data['current_password'] ?? '');
            $hash = $account->passwordHash();
            $firstPassword = $hash === null;
            if ($firstPassword) {
                $account->setPasswordHash($this->hasher->hash($newPassword));
                $account->activate();
                $account->bumpTokenVersion();
            } elseif ($current === '' || !$this->hasher->verify($current, $hash)) {
                throw new \InvalidArgumentException('customers.error.password');
            } else {
                $account->setPasswordHash($this->hasher->hash($newPassword));
                $account->bumpTokenVersion();
            }
        }

        $this->em->flush();
        if (array_key_exists('newsletter', $data)) {
            $this->syncNewsletter($customer);
        }

        if ($firstPassword) {
            $this->events->emit('customer.registration_completed', ['type' => 'customer_account', 'id' => $account->id()], [
                'customer_id' => $account->customerId(),
                'email' => $account->email(),
            ]);
        }

        return $account;
    }

    public function requestErasure(CustomerAccount $account): Customer
    {
        $customer = $this->em->find(Customer::class, $account->customerId());
        if (!$customer instanceof Customer) {
            throw new \InvalidArgumentException('Customer not found');
        }
        $already = $customer->erasureRequestedAt() !== null;
        $customer->requestErasure();
        $this->em->flush();
        if (!$already) {
            $this->auditor->log('customer.erasure_requested', 'customer', $customer->id(), null, [
                'email' => $account->email(),
            ]);
            $this->events->emit('customer.erasure_requested', ['type' => 'customer', 'id' => $customer->id()], [
                'customer_id' => $customer->id(),
                'email' => $account->email(),
                'name' => $customer->name(),
            ]);
        }

        return $customer;
    }

    public function deleteStoreAccount(CustomerAccount $account): void
    {
        $customer = $this->em->find(Customer::class, $account->customerId());
        $email = $account->email();
        $customerId = $account->customerId();
        if ($customer instanceof Customer) {
            $customer->setNewsletterOptIn(false);
            $this->syncNewsletterEmail($email, false, $customer->name());
        }
        $this->events->emit('customer.store_deleted', ['type' => 'customer', 'id' => $customerId], [
            'customer_id' => $customerId,
            'email' => $email,
        ]);

        $tombstone = 'deleted-' . substr($customerId, 0, 8) . '@invalid.local';
        $account->setEmail($tombstone);
        $account->setPasswordHash(null);
        $account->deactivate();
        $account->bumpTokenVersion();

        if ($customer instanceof Customer) {
            $customer->setName('Smazaný účet');
            $customer->setEmail($tombstone);
            $customer->setPhone(null);
            $customer->setPreferredRegion(null);
            $customer->setLocality(null);
            $customer->setPreferredCategories(null);
            $customer->setNewsletterOptIn(false);
            $customer->archive();
            $customer->requestErasure();
        }

        $this->em->flush();
    }

    /** @return array<string, mixed> */
    public function serialize(CustomerAccount $account): array
    {
        $name = '';
        $phone = null;
        $region = null;
        $locality = null;
        $newsletter = false;
        $categories = [];
        $customer = $this->em->find(Customer::class, $account->customerId());
        if ($customer instanceof Customer) {
            $name = $customer->name();
            $phone = $customer->phone();
            $region = $customer->preferredRegion();
            $locality = $customer->locality();
            $newsletter = $customer->newsletterOptIn();
            $categories = $customer->preferredCategories();
        }

        $payload = [
            'id' => $account->id(),
            'customer_id' => $account->customerId(),
            'email' => $account->email(),
            'name' => $name,
            'phone' => $phone,
            'region' => $region,
            'locality' => $locality,
            'newsletter' => $newsletter,
            'categories' => $categories,
            'is_active' => $account->isActive(),
            'is_soft' => $account->isSoft(),
            'email_verified_at' => $account->emailVerifiedAt()?->format(\DateTimeInterface::ATOM),
            'token_version' => $account->tokenVersion(),
            'created_at' => $account->createdAt()->format(\DateTimeInterface::ATOM),
            'erasure_requested_at' => $customer instanceof Customer
                ? $customer->erasureRequestedAt()?->format(\DateTimeInterface::ATOM)
                : null,
        ];
        $filtered = $this->hooks->filter('store.auth.serialize', $payload, $account);

        return is_array($filtered) ? $filtered : $payload;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyPrefs(Customer $customer, array $data, bool $force): void
    {
        if ($force || array_key_exists('region', $data)) {
            $region = $data['region'] ?? null;
            $customer->setPreferredRegion(is_string($region) || is_numeric($region) ? (string) $region : null);
        }
        if ($force || array_key_exists('locality', $data)) {
            $locality = $data['locality'] ?? null;
            $customer->setLocality(is_string($locality) || is_numeric($locality) ? (string) $locality : null);
        }
        if ($force || array_key_exists('newsletter', $data)) {
            $customer->setNewsletterOptIn($this->truthy($data['newsletter'] ?? false));
        }
        if ($force || array_key_exists('categories', $data)) {
            $raw = $data['categories'] ?? null;
            $list = [];
            if (is_array($raw)) {
                foreach ($raw as $item) {
                    if (is_string($item) || is_numeric($item)) {
                        $list[] = (string) $item;
                    }
                }
            }
            $customer->setPreferredCategories($list);
        }
    }

    private function syncNewsletter(Customer $customer): void
    {
        $this->syncNewsletterEmail($customer->email(), $customer->newsletterOptIn(), $customer->name());
    }

    private function syncNewsletterEmail(string $email, bool $optIn, ?string $name): void
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@') || str_ends_with($email, '@invalid.local')) {
            return;
        }
        if (!$this->capabilities->has('newsletter')) {
            return;
        }
        $provider = $this->capabilities->get('newsletter');
        try {
            if ($optIn && method_exists($provider, 'subscribe')) {
                $provider->subscribe($email, $name, 'store-account');
            } elseif (!$optIn && method_exists($provider, 'unsubscribe')) {
                $provider->unsubscribe($email);
            }
        } catch (\Throwable) {
        }
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
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
}
