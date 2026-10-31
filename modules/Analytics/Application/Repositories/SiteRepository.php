<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Repositories;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Analytics\Domain\Site;

/**
 * Data access for `analytics_sites`.
 *
 * Reads go through DQL partial selects (no ORM associations are used, so partial hydration is
 * cheap); writes are explicit SQL against the column contract in `modules/Analytics/README.md`,
 * which is what makes the ingest path and per-site deletes cheap and predictable.
 * Row keys are the database column names, so the same array feeds the services and Twig.
 *
 * Every identifier that reaches a statement is quoted through {@see SqlIdentifier} because
 * `key` is reserved on MySQL/MariaDB. `Domain\Site` stays the single source of truth for the
 * generated id, tracker key and HMAC secret.
 */
final class SiteRepository
{
    /** Column list shared by inserts and updates. @var list<string> */
    private const WRITABLE = [
        'name', 'domains', 'enabled', 'timezone', 'secret', 'retention_override_days',
        'keep_bot_hits', 'respect_dnt', 'excluded_paths', 'excluded_ips',
    ];

    /** Dependent tables removed together with a site (explicit per-site deletes). */
    private const DEPENDENT_TABLES = [
        'analytics_events',
        'analytics_pageviews',
        'analytics_sessions',
        'analytics_visitors',
        'analytics_daily_dimensions',
        'analytics_daily_stats',
        'analytics_consent_events',
    ];

    /** Placeholder name for the throwaway `Domain\Site` instance used by {@see self::newSecret()}. */
    private const SECRET_FACTORY_NAME = 'secret-rotation';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select(
                's.id AS id',
                's.name AS name',
                's.key AS key',
                's.domains AS domains',
                's.enabled AS enabled',
                's.timezone AS timezone',
                's.secret AS secret',
                's.retentionOverrideDays AS retention_override_days',
                's.keepBotHits AS keep_bot_hits',
                's.respectDnt AS respect_dnt',
                's.excludedPaths AS excluded_paths',
                's.excludedIps AS excluded_ips',
                's.createdAt AS created_at',
                's.updatedAt AS updated_at',
            )
            ->from(Site::class, 's')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(fn (array $row): array => $this->normalize($row), $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->em->createQueryBuilder()
            ->select(
                's.id AS id',
                's.name AS name',
                's.key AS key',
                's.domains AS domains',
                's.enabled AS enabled',
                's.timezone AS timezone',
                's.secret AS secret',
                's.retentionOverrideDays AS retention_override_days',
                's.keepBotHits AS keep_bot_hits',
                's.respectDnt AS respect_dnt',
                's.excludedPaths AS excluded_paths',
                's.excludedIps AS excluded_ips',
                's.createdAt AS created_at',
                's.updatedAt AS updated_at',
            )
            ->from(Site::class, 's')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $row !== null ? $this->normalize($row) : null;
    }

    /** @return array<string, mixed>|null Enabled site by tracker key (`grq_an_…`). */
    public function findEnabledByKey(string $key): ?array
    {
        $site = $this->findByKey($key);

        return $site !== null && ($site['enabled'] ?? false) === true ? $site : null;
    }

    /** @return array<string, mixed>|null */
    public function findByKey(string $key): ?array
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        /** @var array<string, mixed>|null $row */
        $row = $this->em->createQueryBuilder()
            ->select(
                's.id AS id',
                's.name AS name',
                's.key AS key',
                's.domains AS domains',
                's.enabled AS enabled',
                's.timezone AS timezone',
                's.secret AS secret',
                's.retentionOverrideDays AS retention_override_days',
                's.keepBotHits AS keep_bot_hits',
                's.respectDnt AS respect_dnt',
                's.excludedPaths AS excluded_paths',
                's.excludedIps AS excluded_ips',
                's.createdAt AS created_at',
                's.updatedAt AS updated_at',
            )
            ->from(Site::class, 's')
            ->where('s.key = :key')
            ->setParameter('key', $key)
            ->getQuery()
            ->getOneOrNullResult();

        return $row !== null ? $this->normalize($row) : null;
    }

    /**
     * Enabled site whose `domains` list matches the request host (exact or `*.example.com`).
     *
     * @return array<string, mixed>|null
     */
    public function findEnabledByDomain(string $host): ?array
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return null;
        }

        foreach ($this->all() as $site) {
            if (($site['enabled'] ?? false) !== true) {
                continue;
            }
            foreach (self::domainsOf($site) as $domain) {
                if (self::hostMatches($host, $domain)) {
                    return $site;
                }
            }
        }

        return null;
    }

    /**
     * Insert one site row and return its id.
     *
     * The row is built from `Domain\Site::create()`, which owns id, tracker key and secret
     * generation (including the key length); only the editable columns come from `$data`.
     *
     * @param array{name: string, domains?: list<string>, enabled?: bool, timezone?: string,
     *   keep_bot_hits?: bool, respect_dnt?: bool, excluded_paths?: list<string>,
     *   excluded_ips?: list<string>, retention_override_days?: int|null} $data
     */
    public function create(array $data): string
    {
        $site = Site::create($data['name'], $data['domains'] ?? []);
        $connection = $this->em->getConnection();

        $row = [
            'id' => $site->id(),
            'name' => $site->name(),
            'key' => $site->key(),
            'domains' => self::encodeList($site->domains()),
            'enabled' => ($data['enabled'] ?? true) ? 1 : 0,
            'timezone' => $data['timezone'] ?? $site->timezone(),
            'secret' => $site->secret(),
            'retention_override_days' => $data['retention_override_days'] ?? null,
            'keep_bot_hits' => ($data['keep_bot_hits'] ?? false) ? 1 : 0,
            'respect_dnt' => ($data['respect_dnt'] ?? true) ? 1 : 0,
            'excluded_paths' => self::encodeList($data['excluded_paths'] ?? []),
            'excluded_ips' => self::encodeList($data['excluded_ips'] ?? []),
            'created_at' => $site->createdAt()->format('Y-m-d H:i:s'),
            'updated_at' => $site->updatedAt()->format('Y-m-d H:i:s'),
        ];

        $columns = [];
        foreach (array_keys($row) as $column) {
            $columns[] = SqlIdentifier::quote($connection, $column);
        }

        $connection->executeStatement(
            'INSERT INTO ' . SqlIdentifier::quote($connection, 'analytics_sites')
            . ' (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($row), '?')) . ')',
            array_values($row),
        );

        return $site->id();
    }

    /**
     * @param array<string, mixed> $data Only the writable columns present here are updated.
     */
    public function update(string $id, array $data): void
    {
        $assignments = [];
        $params = [];
        $connection = $this->em->getConnection();

        foreach (self::WRITABLE as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $assignments[] = SqlIdentifier::quote($connection, $column) . ' = ?';
            $params[] = match ($column) {
                'domains', 'excluded_paths', 'excluded_ips' => self::encodeList(self::asArray($data[$column])),
                'enabled', 'keep_bot_hits', 'respect_dnt' => $data[$column] ? 1 : 0,
                'retention_override_days' => $data[$column] !== null && $data[$column] !== ''
                    ? (int) $data[$column]
                    : null,
                default => (string) $data[$column],
            };
        }

        if ($assignments === []) {
            return;
        }

        $assignments[] = SqlIdentifier::quote($connection, 'updated_at') . ' = ?';
        $params[] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $params[] = $id;

        $connection->executeStatement(
            'UPDATE ' . SqlIdentifier::quote($connection, 'analytics_sites')
            . ' SET ' . implode(', ', $assignments) . ' WHERE ' . SqlIdentifier::quote($connection, 'id') . ' = ?',
            $params,
        );
    }

    /**
     * Replace the stored HMAC secret and return the new one (shown once in the admin UI).
     *
     * The value comes from the domain entity's generator, so the repository never grows a second
     * secret implementation.
     */
    public function rotateSecret(string $id): string
    {
        $secret = self::newSecret();
        $connection = $this->em->getConnection();

        $connection->executeStatement(
            'UPDATE ' . SqlIdentifier::quote($connection, 'analytics_sites')
            . ' SET ' . SqlIdentifier::quote($connection, 'secret') . ' = ?, '
            . SqlIdentifier::quote($connection, 'updated_at') . ' = ? WHERE '
            . SqlIdentifier::quote($connection, 'id') . ' = ?',
            [$secret, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id],
        );

        return $secret;
    }

    /** Deletes the site and every row that belongs to it (explicit, per README). */
    public function delete(string $id): void
    {
        $connection = $this->em->getConnection();
        foreach (self::DEPENDENT_TABLES as $table) {
            $connection->executeStatement(
                'DELETE FROM ' . SqlIdentifier::quote($connection, $table) . ' WHERE site_id = ?',
                [$id],
            );
        }
        $connection->executeStatement(
            'DELETE FROM ' . SqlIdentifier::quote($connection, 'analytics_sites')
            . ' WHERE ' . SqlIdentifier::quote($connection, 'id') . ' = ?',
            [$id],
        );
    }

    /**
     * Fresh HMAC secret taken from `Domain\Site`.
     *
     * The entity keeps its generator private and exposes it only through `rotateSecret()`, so a
     * throwaway instance is the reuse point it offers; no second generator lives in the repository.
     */
    private static function newSecret(): string
    {
        return Site::create(self::SECRET_FACTORY_NAME, [])->rotateSecret();
    }

    /**
     * @param array<string, mixed> $site
     * @return list<string>
     */
    public static function domainsOf(array $site): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $domain): string => self::normalizeHost((string) $domain),
            self::asArray($site['domains'] ?? []),
        )));
    }

    public static function normalizeHost(string $host): string
    {
        $host = mb_strtolower(trim($host));
        if ($host === '') {
            return '';
        }
        if (str_contains($host, '://')) {
            $host = (string) (parse_url($host, PHP_URL_HOST) ?? $host);
        }
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        return rtrim($host, '.');
    }

    /**
     * Origin binding for a request that identifies its site by tracker key.
     *
     * A tracker key is public, so a key alone must not let a requester write traffic into a foreign
     * site: every supplied non-empty host (typically the request `Host` plus the page
     * `Origin`/`Referer` host, when present) has to match one of the site's configured domains.
     * An empty `domains` list means "no domain restriction configured" and accepts the key on its
     * own (documented in `modules/Analytics/README.md`); when no usable host is supplied at all the
     * key is not accepted either. The comparison is the same exact / `*.suffix` rule as
     * {@see self::findEnabledByDomain()}.
     *
     * @param array<string, mixed> $site
     * @param list<string|null> $hosts
     */
    public static function hostsMatchSite(array $site, array $hosts): bool
    {
        $domains = self::domainsOf($site);
        if ($domains === []) {
            return true;
        }

        $checked = false;
        foreach ($hosts as $host) {
            $normalized = self::normalizeHost((string) $host);
            if ($normalized === '') {
                continue;
            }
            $checked = true;

            $matched = false;
            foreach ($domains as $domain) {
                if (self::hostMatches($normalized, $domain)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        return $checked;
    }

    private static function hostMatches(string $host, string $domain): bool
    {
        if ($domain === '') {
            return false;
        }
        if (str_starts_with($domain, '*.')) {
            $suffix = substr($domain, 1);

            return $host === substr($domain, 2) || str_ends_with($host, $suffix);
        }

        return $host === $domain;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $row['domains'] = self::asArray($row['domains'] ?? []);
        $row['excluded_paths'] = self::asArray($row['excluded_paths'] ?? []);
        $row['excluded_ips'] = self::asArray($row['excluded_ips'] ?? []);
        $row['enabled'] = (bool) ($row['enabled'] ?? false);
        $row['keep_bot_hits'] = (bool) ($row['keep_bot_hits'] ?? false);
        $row['respect_dnt'] = (bool) ($row['respect_dnt'] ?? false);
        $override = $row['retention_override_days'] ?? null;
        $row['retention_override_days'] = is_numeric($override) ? (int) $override : null;
        $row['created_at'] = self::stringify($row['created_at'] ?? null);
        $row['updated_at'] = self::stringify($row['updated_at'] ?? null);

        return $row;
    }

    /** @return list<mixed> */
    private static function asArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? array_values($decoded) : [];
        }

        return [];
    }

    /** @param list<mixed> $value */
    private static function encodeList(array $value): string
    {
        return json_encode(array_values($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private static function stringify(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return (string) $value;
    }
}
