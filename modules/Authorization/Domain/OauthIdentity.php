<?php

declare(strict_types=1);

namespace Modules\Authorization\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'auth_oauth_identities')]
#[ORM\UniqueConstraint(name: 'uniq_auth_oauth_provider_user', columns: ['provider', 'provider_user_id'])]
class OauthIdentity
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 32)]
    private string $provider;

    #[ORM\Column(name: 'provider_user_id', length: 190)]
    private string $providerUserId;

    #[ORM\Column(name: 'customer_id', length: 36)]
    private string $customerId;

    #[ORM\Column(length: 190)]
    private string $email;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $provider, string $providerUserId, string $customerId, string $email)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->provider = $provider;
        $this->providerUserId = $providerUserId;
        $this->customerId = $customerId;
        $this->email = strtolower(trim($email));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function providerUserId(): string
    {
        return $this->providerUserId;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function email(): string
    {
        return $this->email;
    }
}
