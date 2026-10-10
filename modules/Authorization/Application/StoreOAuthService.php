<?php

declare(strict_types=1);

namespace Modules\Authorization\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Customers\Application\StoreAuthService;
use Gereq\Customers\Domain\CustomerAccount;
use Modules\Authorization\Domain\OauthIdentity;

final class StoreOAuthService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StoreAuthService $storeAuth,
    ) {
    }

    public function accountByCustomerId(string $customerId): ?CustomerAccount
    {
        if ($customerId === '') {
            return null;
        }

        return $this->storeAuth->findAccountByCustomerId($customerId);
    }

    /** @return array{account: CustomerAccount, created: bool} */
    public function loginOrRegister(string $provider, string $providerUserId, string $email, string $name): array
    {
        $email = strtolower(trim($email));
        $identity = $this->identityByRemote($provider, $providerUserId);
        if ($identity instanceof OauthIdentity) {
            $account = $this->storeAuth->findAccountByCustomerId($identity->customerId());
            if ($account instanceof CustomerAccount) {
                if (!$account->isActive()) {
                    $account->activate();
                    $account->markEmailVerified();
                    $this->em->flush();
                }

                return ['account' => $account, 'created' => false];
            }
        }

        $account = $this->storeAuth->findAccountByEmail($email);
        if ($account instanceof CustomerAccount) {
            throw new OauthNotLinkedException($provider);
        }

        $account = $this->storeAuth->softRegister($email, $name !== '' ? $name : $email);
        $account->activate();
        $account->markEmailVerified();
        if (!$identity instanceof OauthIdentity) {
            $this->em->persist(new OauthIdentity($provider, $providerUserId, $account->customerId(), $email));
        }
        $this->em->flush();

        return ['account' => $account, 'created' => true];
    }

    public function link(CustomerAccount $account, string $provider, string $providerUserId, string $email): void
    {
        $email = strtolower(trim($email));
        $byRemote = $this->identityByRemote($provider, $providerUserId);
        if ($byRemote instanceof OauthIdentity && $byRemote->customerId() !== $account->customerId()) {
            throw new OauthIdentityTakenException();
        }
        $existing = $this->identityByCustomer($account->customerId(), $provider);
        if ($existing instanceof OauthIdentity) {
            return;
        }
        if (!$byRemote instanceof OauthIdentity) {
            $this->em->persist(new OauthIdentity($provider, $providerUserId, $account->customerId(), $email));
        }
        if (!$account->isActive()) {
            $account->activate();
            $account->markEmailVerified();
        }
        $this->em->flush();
    }

    public function unlink(CustomerAccount $account, string $provider): void
    {
        $existing = $this->identityByCustomer($account->customerId(), $provider);
        if (!$existing instanceof OauthIdentity) {
            return;
        }
        $remaining = $this->identities($account->customerId());
        $others = array_values(array_filter(
            $remaining,
            static fn (OauthIdentity $row): bool => $row->provider() !== $provider,
        ));
        if ($account->isSoft() && $others === []) {
            throw new OauthUnlinkBlockedException();
        }
        $this->em->remove($existing);
        $this->em->flush();
    }

    /** @return list<OauthIdentity> */
    public function identities(string $customerId): array
    {
        /** @var list<OauthIdentity> $rows */
        $rows = $this->em->getRepository(OauthIdentity::class)->findBy(
            ['customerId' => $customerId],
            ['createdAt' => 'ASC'],
        );

        return $rows;
    }

    /** @return list<array{id: string, email: string, linked: bool}> */
    public function publicLinks(string $customerId): array
    {
        $out = [];
        foreach ($this->identities($customerId) as $row) {
            $out[] = [
                'id' => $row->provider(),
                'email' => $row->email(),
                'linked' => true,
            ];
        }

        return $out;
    }

    public function purgeCustomer(string $customerId): void
    {
        $this->em->createQueryBuilder()
            ->delete(OauthIdentity::class, 'o')
            ->where('o.customerId = :id')
            ->setParameter('id', $customerId)
            ->getQuery()
            ->execute();
    }

    private function identityByRemote(string $provider, string $providerUserId): ?OauthIdentity
    {
        $row = $this->em->getRepository(OauthIdentity::class)->findOneBy([
            'provider' => $provider,
            'providerUserId' => $providerUserId,
        ]);

        return $row instanceof OauthIdentity ? $row : null;
    }

    private function identityByCustomer(string $customerId, string $provider): ?OauthIdentity
    {
        $row = $this->em->getRepository(OauthIdentity::class)->findOneBy([
            'customerId' => $customerId,
            'provider' => $provider,
        ]);

        return $row instanceof OauthIdentity ? $row : null;
    }
}
