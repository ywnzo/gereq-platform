<?php

declare(strict_types=1);

namespace Modules\Gereq\Application\Security;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Config\Repository;
use Modules\Gereq\Application\Support\HttpOrigin;
use Modules\Gereq\Domain\SecuritySite;
use Symfony\Component\HttpFoundation\Request;
use Gereq\Http\Exception\HttpException;

final class SecuritySiteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Repository $config,
    ) {
    }

    public function findActiveSiteByPublicKey(string $key): SecuritySite
    {
        /** @var SecuritySite|null $site */
        $site = $this->em->createQueryBuilder()
            ->select('s')
            ->from(SecuritySite::class, 's')
            ->where('s.isActive = true')
            ->andWhere('s.publicKey = :key OR s.testPublicKey = :key')
            ->setParameter('key', $key)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$site instanceof SecuritySite) {
            throw new HttpException(404, 'Site not found.');
        }

        $test = is_string($site->testPublicKey())
            && $site->testPublicKey() !== ''
            && hash_equals((string) $site->testPublicKey(), $key);
        $site->markCredentialMode($test);

        return $site;
    }

    public function findActiveSiteBySecret(string $secret): SecuritySite
    {
        if ($secret === '' || !str_starts_with($secret, 'sk_')) {
            throw new HttpException(401, 'Neplatný secret klíč.');
        }
        $hash = SecuritySite::hashSecret($secret);
        /** @var list<SecuritySite> $sites */
        $sites = $this->em->createQueryBuilder()
            ->select('s')
            ->from(SecuritySite::class, 's')
            ->where('s.isActive = true')
            ->andWhere('s.secretKeyHash = :hash OR s.testSecretKeyHash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getResult();

        $site = $sites[0] ?? null;
        if (!$site instanceof SecuritySite) {
            throw new HttpException(401, 'Neplatný secret klíč.');
        }

        $testHash = $site->testSecretKeyHash();
        $isTest = is_string($testHash)
            && $testHash !== ''
            && hash_equals($testHash, $hash)
            && !hash_equals($site->secretKeyHash(), $hash);
        $site->markCredentialMode($isTest);

        return $site;
    }

    public function assertOrigin(SecuritySite $site, ?string $origin, ?Request $request = null): void
    {
        $claimed = HttpOrigin::normalize((string) ($origin ?? ''));
        $header = $this->requestOrigin($request);

        if ($claimed === '' || !$site->allowsOrigin($claimed)) {
            throw new HttpException(403, 'Origin není povolený pro tento widget.');
        }

        if ($header !== null && $header !== '') {
            if ($header !== $claimed || !$site->allowsOrigin($header)) {
                throw new HttpException(403, 'Origin hlavičky neodpovídá povolenému webu.');
            }

            return;
        }

        $env = (string) $this->config->get('app.env', 'production');
        if ($env === 'production') {
            throw new HttpException(403, 'Chybí Origin hlavička.');
        }
    }

    public function requestOrigin(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }
        $header = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        if (!is_string($header) || $header === '') {
            return null;
        }

        return HttpOrigin::normalize($header) ?: null;
    }

    /** @return array<string, string> */
    public function corsHeaders(string $origin): array
    {
        $normalized = HttpOrigin::normalize($origin);
        $value = $normalized !== '' ? $normalized : ($origin === '*' ? '*' : '');
        if ($value === '') {
            return [];
        }

        return [
            'Access-Control-Allow-Origin' => $value,
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept, Origin',
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Origin',
        ];
    }

    /** @return array<string, string> */
    public function corsHeadersForSite(SecuritySite $site, ?string $origin): array
    {
        $normalized = $origin ? HttpOrigin::normalize($origin) : '';
        if ($normalized === '' || !$site->allowsOrigin($normalized)) {
            return [];
        }

        return $this->corsHeaders($normalized);
    }

    public function originAllowedByAnyActiveSite(string $origin): bool
    {
        $normalized = HttpOrigin::normalize($origin);
        if ($normalized === '') {
            return false;
        }
        if (HttpOrigin::isLoopback($normalized)) {
            return true;
        }
        /** @var list<SecuritySite> $sites */
        $sites = $this->em->getRepository(SecuritySite::class)->findBy(['isActive' => true]);
        foreach ($sites as $site) {
            if (in_array($normalized, $site->allowedOrigins(), true)) {
                return true;
            }
        }

        return false;
    }

    public function visitorHash(Request $request): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [
                (new \DateTimeImmutable('today'))->format('Y-m-d'),
                (string) $request->getClientIp(),
                (string) $request->headers->get('User-Agent', ''),
            ]),
            (string) $this->config->get('app.key', ''),
        );
    }
}
