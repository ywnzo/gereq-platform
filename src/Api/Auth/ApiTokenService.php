<?php

declare(strict_types=1);

namespace Gereq\Api\Auth;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\Entity\ApiToken;
use Gereq\Config\Repository;
use Gereq\User\Entity\User;

final class ApiTokenService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Repository $config,
    ) {
    }

    /**
     * @param list<string> $scopes
     * @return array{token: ApiToken, plain: string}
     */
    public function create(string $name, User $user, array $scopes, bool $test = false, ?\DateTimeImmutable $expiresAt = null): array
    {
        $prefix = $test
            ? (string) $this->config->get('api.token_prefix_test', 'grq_test_')
            : (string) $this->config->get('api.token_prefix_live', 'grq_live_');
        $secret = bin2hex(random_bytes(24));
        $plain = $prefix . $secret;
        $displayPrefix = substr($plain, 0, strlen($prefix) + 6);
        $token = new ApiToken($name, $user->id(), $displayPrefix, hash('sha256', $plain), $scopes);
        if ($expiresAt !== null) {
            $token->setExpiresAt($expiresAt);
        }
        $this->em->persist($token);
        $this->em->flush();

        return ['token' => $token, 'plain' => $plain];
    }

    public function findByPlain(string $plain): ?ApiToken
    {
        $hash = hash('sha256', $plain);
        $token = $this->em->getRepository(ApiToken::class)->findOneBy(['tokenHash' => $hash]);
        if (!$token instanceof ApiToken || !$token->isActive()) {
            return null;
        }

        return $token;
    }

    public function touch(ApiToken $token): void
    {
        $token->markUsed();
        $this->em->flush();
    }
}
