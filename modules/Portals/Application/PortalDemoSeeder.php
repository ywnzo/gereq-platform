<?php

declare(strict_types=1);

namespace Modules\Portals\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Listings\Domain\Listing;
use Modules\Portals\Domain\PortalAccount;
use Modules\Portals\Domain\PortalProvider;
use Modules\Portals\Domain\PortalPublication;

/**
 * Idempotentní demo: číselník poskytovatelů + účty + napojení na nabídky.
 */
final class PortalDemoSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PortalsProvider $provider,
    ) {
    }

    /** @return list<string> */
    public function seed(): array
    {
        $log = [];
        if (!class_exists(PortalAccount::class) || !class_exists(PortalProvider::class)) {
            return ['Portals není k dispozici.'];
        }

        $providers = $this->ensureProviders($log);
        $accounts = $this->ensureAccounts($providers, $log);

        if (!class_exists(Listing::class)) {
            $this->em->flush();

            return $log;
        }

        $listings = $this->em->getRepository(Listing::class)->findBy(
            ['status' => 'published'],
            ['featured' => 'DESC', 'createdAt' => 'DESC'],
            6,
        );
        if ($listings === []) {
            $listings = $this->em->getRepository(Listing::class)->findBy([], ['createdAt' => 'DESC'], 6);
        }
        if ($listings === []) {
            $log[] = 'Žádné nabídky — publikace přeskočeny.';
            $this->em->flush();

            return $log;
        }

        $plan = [
            'sreality-rk' => [0 => 'published', 1 => 'queued', 2 => 'published'],
            'idnes-reality' => [0 => 'queued', 1 => 'published'],
            'bezrealitky-api' => [0 => 'draft', 3 => 'error'],
        ];
        foreach ($plan as $code => $map) {
            $account = $accounts[$code] ?? null;
            if (!$account instanceof PortalAccount) {
                continue;
            }
            foreach ($map as $index => $status) {
                $listing = $listings[$index] ?? null;
                if (!$listing instanceof Listing) {
                    continue;
                }
                $this->ensurePublication($account, $listing, $status, $log);
            }
        }

        $this->em->flush();
        $log[] = 'Demo portálů hotovo.';

        return $log;
    }

    /**
     * @param list<string> $log
     * @return array<string, PortalProvider>
     */
    private function ensureProviders(array &$log): array
    {
        // Migration afterUp už seeduje číselník; tady jen načteme.
        $rows = $this->em->getRepository(PortalProvider::class)->findBy([], ['sortOrder' => 'ASC']);
        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof PortalProvider) {
                $out[$row->code()] = $row;
            }
        }
        if ($out === []) {
            $log[] = 'Číselník poskytovatelů je prázdný — spusťte update modulu Portals.';
        } else {
            $log[] = 'Poskytovatelé v číselníku: ' . implode(', ', array_keys($out));
        }

        return $out;
    }

    /**
     * @param array<string, PortalProvider> $providers
     * @param list<string> $log
     * @return array<string, PortalAccount>
     */
    private function ensureAccounts(array $providers, array &$log): array
    {
        $defs = [
            [
                'code' => 'sreality-rk',
                'name' => 'Sreality — RK účet',
                'provider' => 'sreality',
                'config' => [
                    'client_id' => '1',
                    'password' => 'demo-md5-hash',
                    'software_key' => 'demo-import-key',
                    'advert_lifetime' => '3',
                ],
            ],
            [
                'code' => 'idnes-reality',
                'name' => 'Idnes Reality — partner',
                'provider' => 'idnes',
                'config' => ['partner_id' => 'IDNES-DEMO-77'],
            ],
            [
                'code' => 'bezrealitky-api',
                'name' => 'Bezrealitky — API',
                'provider' => 'bezrealitky',
                'config' => [
                    'api_key' => 'demo-key-replace-me',
                    'endpoint' => 'https://api.bezrealitky.cz/v1',
                ],
            ],
        ];

        $out = [];
        $repo = $this->em->getRepository(PortalAccount::class);
        foreach ($defs as $def) {
            $provider = $providers[$def['provider']] ?? null;
            if (!$provider instanceof PortalProvider) {
                $log[] = 'Chybí poskytovatel ' . $def['provider'];
                continue;
            }
            $account = $repo->findOneBy(['code' => $def['code']]);
            if (!$account instanceof PortalAccount) {
                $account = new PortalAccount($def['code'], $def['name'], $provider->id());
                $this->em->persist($account);
                $log[] = 'Účet vytvořen: ' . $def['code'];
            } else {
                $account->setName($def['name']);
                $account->setProviderId($provider->id());
                $log[] = 'Účet aktualizován: ' . $def['code'];
            }
            $account->setActive(true);
            $account->setConfig($def['config']);
            $out[$def['code']] = $account;
        }
        $this->em->flush();

        return $out;
    }

    /** @param list<string> $log */
    private function ensurePublication(
        PortalAccount $account,
        Listing $listing,
        string $status,
        array &$log,
    ): void {
        $pub = $this->provider->findPublicationFor($account->id(), $listing->id());
        if ($pub === null) {
            $pub = $this->provider->queuePublication($account, $listing);
            $log[] = sprintf('Fronta %s → %s', $account->code(), $listing->slug());
        }

        match ($status) {
            'published' => $pub->markSynced('demo-' . $account->code() . '-' . substr($listing->id(), 0, 8)),
            'queued' => $pub->markQueued(),
            'error' => $pub->markError('Demo: chybí platný API klíč / remote sync zatím není napojen.'),
            'draft' => $pub->setStatus('draft'),
            default => $pub->markQueued(),
        };
    }
}
