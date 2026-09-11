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
        $scopes = $this->constrainScopes($user, $scopes);
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

    /**
     * Ephemeral token for the Developer API tester (one plain value kept in session).
     * Privileged admins get `*`; others get the listed known scopes (RBAC still enforced).
     *
     * @param list<string> $knownScopes
     * @return array{token: ApiToken, plain: string}
     */
    public function issueTesterToken(User $user, array $knownScopes = []): array
    {
        $name = self::TESTER_TOKEN_NAME;
        /** @var list<ApiToken> $existing */
        $existing = $this->em->getRepository(ApiToken::class)->findBy([
            'userId' => $user->id(),
            'name' => $name,
        ]);
        foreach ($existing as $token) {
            if ($token->isActive()) {
                $token->revoke();
            }
        }
        $this->em->flush();

        $scopes = $user->isAdministrator()
            ? ['*']
            : array_values(array_unique(array_filter(array_map('strval', $knownScopes))));

        return $this->create(
            $name,
            $user,
            $scopes,
            true,
            new \DateTimeImmutable('+12 hours'),
        );
    }

    public const TESTER_TOKEN_NAME = 'API Tester';

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

    /**
     * Drop `*` / full unless the owner is privileged; otherwise keep requested scopes
     * (RBAC on the owner is enforced again at request time).
     *
     * @param list<string> $scopes
     * @return list<string>
     */
    public function constrainScopes(User $user, array $scopes): array
    {
        $scopes = array_values(array_unique(array_filter(array_map('strval', $scopes))));
        $wantsFull = in_array('*', $scopes, true) || in_array('full', $scopes, true);
        if ($wantsFull) {
            if (!$user->isAdministrator()) {
                return array_values(array_filter(
                    $scopes,
                    static fn (string $s): bool => $s !== '*' && $s !== 'full',
                ));
            }

            return ['*'];
        }

        return $scopes;
    }
}
