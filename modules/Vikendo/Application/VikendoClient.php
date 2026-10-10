<?php

declare(strict_types=1);

namespace Modules\Vikendo\Application;

use Gereq\Setting\Settings;

/**
 * Tenký HTTP klient pro interní API Vikendo.cz.
 *
 * API není oficiálně verzované ani smluvně garantované — viz vikendo-api-endpoints.md.
 * Preferuj {@see self::eventListings()} před staršími /api/events endpointy.
 */
final class VikendoClient
{
    public const DEFAULT_BASE_URL = 'https://api.vikendo.cz';

    /** Plzeňský kraj (region EID). */
    public const REGION_PLZENSKY = '43';

    /** @var array<string, string> okres → EID (Plzeňský kraj) */
    public const DISTRICT_EIDS_PLZENSKY = [
        'domazlice' => '3401',
        'klatovy' => '3404',
        'plzen_mesto' => '3405',
        'plzen_jih' => '3406',
        'plzen_sever' => '3407',
        'rokycany' => '3408',
        'tachov' => '3410',
    ];

    private const UA = 'Vikendo/1.0';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function baseUrl(): string
    {
        $configured = trim((string) $this->settings->get('vikendo.base_url', self::DEFAULT_BASE_URL));

        return rtrim($configured !== '' ? $configured : self::DEFAULT_BASE_URL, '/');
    }

    public function authToken(): string
    {
        return trim((string) $this->settings->get('vikendo.auth_token', ''));
    }

    public function defaultRegionEid(): string
    {
        $eid = trim((string) $this->settings->get('vikendo.default_region_eid', self::REGION_PLZENSKY));

        return $eid !== '' ? $eid : self::REGION_PLZENSKY;
    }

    public function timeout(): int
    {
        $seconds = (int) $this->settings->get('vikendo.timeout', 20);

        return max(3, min(120, $seconds > 0 ? $seconds : 20));
    }

    // ——— Event listings (preferované) ———

    /**
     * @param array<string, mixed> $query page, per_page, q[...] filtry
     * @return array<string, mixed>
     */
    public function eventListings(array $query = []): array
    {
        return $this->get('/api/event_listings', $query);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function eventListingsPopular(array $query = []): array
    {
        return $this->get('/api/event_listings/popular', $query);
    }

    /**
     * @param array<string, mixed> $query region_eid, page, per_page…
     * @return array<string, mixed>
     */
    public function eventListingsPopularMix(array $query = []): array
    {
        return $this->get('/api/event_listings/popular_mix', $query);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function eventListingsPersonalized(array $query = [], bool $auth = true): array
    {
        return $this->get('/api/event_listings/personalized', $query, $auth);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function eventListingsWithDiscount(array $query = []): array
    {
        return $this->get('/api/event_listings/with_discount', $query);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function eventListingsBookmarked(array $query = []): array
    {
        return $this->get('/api/event_listings/bookmarked', $query, true);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function eventListingsFulltext(string $text, array $query = []): array
    {
        $query['q'] = array_merge(
            is_array($query['q'] ?? null) ? $query['q'] : [],
            ['search_fulltext' => $text],
        );

        return $this->get('/api/event_listings/fulltext_search', $query);
    }

    // ——— Events (starší varianta) ———

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function events(array $query = []): array
    {
        return $this->get('/api/events', $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function event(int|string $id): array
    {
        return $this->get('/api/events/' . rawurlencode((string) $id));
    }

    /**
     * @return array<string, mixed>
     */
    public function eventBySlug(string $slug): array
    {
        return $this->get('/api/events/link/' . rawurlencode($slug));
    }

    /**
     * Listing/detail často vrací `url` na vikendo.cz — skutečný odkaz pořadatele
     * je v {@see self::eventBySlug()} (`GET /api/events/link/{slug}`).
     */
    public function resolveSourceUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !self::isVikendoHostUrl($url)) {
            return $url;
        }
        $slug = self::vikendoPathSlug($url);
        if ($slug === null || $slug === '') {
            return $url;
        }

        foreach (self::slugCandidates($slug) as $candidate) {
            try {
                $payload = $this->eventBySlug($candidate);
                $event = is_array($payload['event'] ?? null) ? $payload['event'] : $payload;
                $resolved = trim((string) ($event['url'] ?? ''));
                if ($resolved !== '' && !self::isVikendoHostUrl($resolved)) {
                    return $resolved;
                }
            } catch (\Throwable) {
                // zkus další kandidát
            }
        }

        return $url;
    }

    /**
     * @return list<string>
     */
    public static function slugCandidates(string $slug): array
    {
        $out = [$slug];
        // …-edc4cac4-4532-400b-a16a-8eefa6600454 → bez UUID suffixu
        if (preg_match('/^(.*)-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $slug, $m) === 1) {
            $base = trim($m[1]);
            if ($base !== '' && $base !== $slug) {
                $out[] = $base;
            }
        }

        return array_values(array_unique($out));
    }

    public static function isVikendoHostUrl(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        return $host === 'vikendo.cz' || str_ends_with($host, '.vikendo.cz');
    }

    /** Slug z cesty /events/{slug} nebo /udalost/{slug}. */
    public static function vikendoPathSlug(string $url): ?string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if (preg_match('~^/(?:events|udalost)/([^/]+)/?$~', $path, $m) !== 1) {
            return null;
        }
        $slug = rawurldecode($m[1]);

        return $slug !== '' ? $slug : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function eventMetadata(int|string $id): array
    {
        return $this->get('/api/events/' . rawurlencode((string) $id) . '/metadata');
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function searchDefinitions(array $query = []): array
    {
        return $this->get('/api/events/search_definitions', $query);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function searchDefinitionListings(int|string $id, array $query = []): array
    {
        return $this->get(
            '/api/events/search_definitions/' . rawurlencode((string) $id) . '/event_listings',
            $query,
        );
    }

    // ——— Kategorie / geo / místa / průvodci ———

    /**
     * Kompletní katalog kategorií (včetně nepublikovaných).
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function categories(array $query = []): array
    {
        return $this->get('/api/categories', $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function categoriesPublished(): array
    {
        return $this->get('/api/categories/published');
    }

    /**
     * @param list<int|string> $ids
     * @return array<string, mixed>
     */
    public function categoryDescendants(array $ids): array
    {
        return $this->get('/api/categories/descendants', [
            'ids' => implode(',', array_map(static fn ($id): string => (string) $id, $ids)),
        ]);
    }

    /**
     * Zploštěný seznam kategorií pro admin tabulku (id, name, …).
     *
     * @return list<array{id: int, name: string, internal_name: string, depth: int, parent_id: ?int, published: bool}>
     */
    public function categoriesCatalog(): array
    {
        $out = [];
        $page = 1;
        $totalPages = 1;
        do {
            $payload = $this->categories(['page' => $page, 'per_page' => 200]);
            $rows = $payload['categories'] ?? [];
            if (!is_array($rows)) {
                break;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $parent = $row['parent_id'] ?? null;
                $out[] = [
                    'id' => $id,
                    'name' => trim((string) ($row['name'] ?? '')),
                    'internal_name' => trim((string) ($row['internal_name'] ?? '')),
                    'depth' => (int) ($row['depth'] ?? 0),
                    'parent_id' => $parent !== null && $parent !== '' ? (int) $parent : null,
                    'published' => (bool) ($row['published'] ?? false),
                ];
            }
            $pageInfo = is_array($payload['page'] ?? null) ? $payload['page'] : [];
            $size = max(1, (int) ($pageInfo['size'] ?? 200));
            $total = (int) ($pageInfo['count'] ?? count($out));
            $totalPages = $total > 0 ? (int) ceil($total / $size) : $page;
            $page++;
        } while ($page <= $totalPages && $page <= 20);

        usort(
            $out,
            static fn (array $a, array $b): int => ($a['depth'] <=> $b['depth'])
                ?: ($a['parent_id'] <=> $b['parent_id'])
                ?: ($a['id'] <=> $b['id']),
        );

        return $out;
    }

    /**
     * @return list<array{id: int, eid: string, name: string}>
     */
    public function regionsCatalog(): array
    {
        $payload = $this->regions();
        $rows = $payload['regions'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $eid = trim((string) ($row['eid'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($eid === '' || $name === '') {
                continue;
            }
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'eid' => $eid,
                'name' => $name,
            ];
        }
        usort($out, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function regions(): array
    {
        return $this->get('/api/geolocation/regions');
    }

    /**
     * @return array<string, mixed>
     */
    public function districts(array $query = []): array
    {
        return $this->get('/api/geolocation/districts', $query);
    }

    /**
     * @return list<array{id: int, eid: string, name: string, region_eid: string}>
     */
    public function districtsCatalog(): array
    {
        $out = [];
        $page = 1;
        $totalPages = 1;
        do {
            $payload = $this->districts(['page' => $page, 'per_page' => 200]);
            $rows = $payload['districts'] ?? [];
            if (!is_array($rows)) {
                break;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $eid = trim((string) ($row['eid'] ?? ''));
                $name = trim((string) ($row['name'] ?? ''));
                if ($eid === '' || $name === '') {
                    continue;
                }
                $out[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'eid' => $eid,
                    'name' => $name,
                    'region_eid' => trim((string) ($row['region_eid'] ?? '')),
                ];
            }
            $pageInfo = is_array($payload['page'] ?? null) ? $payload['page'] : [];
            $size = max(1, (int) ($pageInfo['size'] ?? 200));
            $total = (int) ($pageInfo['count'] ?? count($out));
            $totalPages = $total > 0 ? (int) ceil($total / $size) : $page;
            $page++;
        } while ($page <= $totalPages && $page <= 10);

        usort(
            $out,
            static fn (array $a, array $b): int => ($a['region_eid'] <=> $b['region_eid'])
                ?: ($a['name'] <=> $b['name']),
        );

        return $out;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function municipalities(array $query = []): array
    {
        return $this->get('/api/geolocation/municipalities', $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function municipalityByEid(string $eid): array
    {
        return $this->get('/api/geolocation/municipalities/eid/' . rawurlencode($eid));
    }

    /**
     * @return array<string, mixed>
     */
    public function municipalityByCoordinates(float $lat, float $lng): array
    {
        return $this->get('/api/geolocation/municipalities/coordinates', [
            'lat' => $lat,
            'lng' => $lng,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function municipalitiesByRegion(string $regionEid, array $query = []): array
    {
        $query['region_eid'] = $regionEid;

        return $this->get('/api/geolocation/municipalities/by_region', $query);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function places(array $query = []): array
    {
        return $this->get('/api/places', $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function place(int|string $id): array
    {
        return $this->get('/api/places/' . rawurlencode((string) $id));
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function weekendGuides(array $query = []): array
    {
        return $this->get('/api/weekend_guides', $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function weekendGuide(string $slug): array
    {
        return $this->get('/api/weekend_guides/' . rawurlencode($slug));
    }

    // ——— Auth / newsletter / AI / aktivita ———

    /**
     * @return array<string, mixed>
     */
    public function signIn(string $email, string $password): array
    {
        return $this->post('/api/users/sign_in', [
            'user' => [
                'email' => $email,
                'password' => $password,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function registerUser(string $email, string $password, string $passwordConfirmation): array
    {
        return $this->post('/api/users', [
            'user' => [
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $subscriber
     * @return array<string, mixed>
     */
    public function subscribeNewsletter(array $subscriber): array
    {
        return $this->post('/api/newsletter_subscribers', [
            'newsletter_subscriber' => $subscriber,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function parseAiSearchQuery(array $body): array
    {
        return $this->post('/api/ai/search/parse_search_query', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function aiSearchAnswer(string $prompt): array
    {
        return $this->get('/api/ai/search/search_query_answer', ['prompt' => $prompt]);
    }

    /**
     * @param list<array{event_id: mixed, action: string, occurred_at: string}> $interactions
     * @return array<string, mixed>
     */
    public function importActivityLogs(array $interactions): array
    {
        return $this->post('/api/activity_logs/import', [
            'interactions' => $interactions,
        ], true);
    }

    // ——— Filtry ———

    /**
     * Sestaví běžný seznamový filtr (q[…]) pro Plzeňský kraj / okresy.
     *
     * @param list<string>|null $districtEids
     * @param list<int|string>|null $categoryIds
     * @return array{page: int, per_page: int, q: array<string, mixed>}
     */
    public function listingQuery(
        ?string $regionEid = null,
        ?array $districtEids = null,
        ?array $categoryIds = null,
        ?string $startFrom = null,
        ?string $startTo = null,
        ?string $fulltext = null,
        int $page = 1,
        int $perPage = 100,
    ): array {
        $q = [];
        $region = $regionEid ?? $this->defaultRegionEid();
        if ($region !== '') {
            $q['region_eid_eq'] = $region;
        }
        if ($districtEids !== null && $districtEids !== []) {
            $q['district_eid_in'] = array_values(array_map(static fn ($e): string => (string) $e, $districtEids));
        }
        if ($categoryIds !== null && $categoryIds !== []) {
            $q['category_ids_overlaps'] = array_values($categoryIds);
        }
        if ($startFrom !== null && $startFrom !== '') {
            $q['start_date_gteq'] = $startFrom;
        }
        if ($startTo !== null && $startTo !== '') {
            $q['start_date_lteq'] = $startTo;
        }
        if ($fulltext !== null && $fulltext !== '') {
            $q['search_fulltext'] = $fulltext;
        }

        return [
            'page' => max(1, $page),
            'per_page' => max(1, min(200, $perPage)),
            'q' => $q,
        ];
    }

    /**
     * Události v Plzeňském kraji (event_listings + výchozí region).
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function eventListingsPlzensky(array $extra = [], int $page = 1, int $perPage = 100): array
    {
        $query = array_replace_recursive(
            $this->listingQuery(regionEid: self::REGION_PLZENSKY, page: $page, perPage: $perPage),
            $extra,
        );

        return $this->eventListings($query);
    }

    // ——— HTTP ———

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], bool $auth = false): array
    {
        return $this->request('GET', $path, $query, null, $auth);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function post(string $path, ?array $body = null, bool $auth = false, array $query = []): array
    {
        return $this->request('POST', $path, $query, $body, $auth);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        bool $auth = false,
    ): array {
        $url = $this->baseUrl() . (str_starts_with($path, '/') ? $path : '/' . $path);
        $qs = self::buildQuery($query);
        if ($qs !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . $qs;
        }

        $payload = $body !== null
            ? json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            : null;

        $headers = [
            'Accept: application/json',
            'User-Agent: ' . self::UA,
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($auth) {
            $token = $this->authToken();
            if ($token === '') {
                throw new VikendoException('Vikendo auth token není nastaven (vikendo.auth_token).', 401);
            }
            // Klient Vikendo posílá token v Authorization; pokud už obsahuje schéma, necháme ho.
            $headers[] = str_contains($token, ' ')
                ? 'Authorization: ' . $token
                : 'Authorization: Bearer ' . $token;
        }

        $t0 = microtime(true);
        try {
            $result = function_exists('curl_init')
                ? $this->viaCurl($method, $url, $payload, $headers)
                : $this->viaStreams($method, $url, $payload, $headers);
        } catch (\Throwable $e) {
            if (VikendoLog::hasRun()) {
                VikendoLog::error('http.fail', [
                    'method' => $method,
                    'path' => $path,
                    'elapsed_s' => round(microtime(true) - $t0, 2),
                    'message' => $e->getMessage(),
                ]);
            }
            throw $e;
        }

        if (VikendoLog::hasRun()) {
            VikendoLog::info('http.ok', [
                'method' => $method,
                'path' => $path,
                'elapsed_s' => round(microtime(true) - $t0, 2),
                'keys' => array_keys($result),
            ]);
        }

        return $result;
    }

    /**
     * PHP http_build_query s podporou q[district_eid_in][]=… (Ransack styl).
     *
     * @param array<string, mixed> $query
     */
    public static function buildQuery(array $query): string
    {
        if ($query === []) {
            return '';
        }

        $parts = [];
        self::flattenQuery($query, '', $parts);

        return implode('&', $parts);
    }

    /**
     * @param array<string, mixed>|list<mixed> $data
     * @param list<string> $parts
     */
    private static function flattenQuery(array $data, string $prefix, array &$parts): void
    {
        foreach ($data as $key => $value) {
            $name = $prefix === ''
                ? (string) $key
                : (is_int($key) ? $prefix . '[]' : $prefix . '[' . $key . ']');

            if (is_array($value)) {
                if ($value === []) {
                    continue;
                }
                // Seznam skalárů → key[]=a&key[]=b
                $isList = array_is_list($value);
                if ($isList && !is_array($value[0] ?? null)) {
                    foreach ($value as $item) {
                        if ($item === null || $item === '') {
                            continue;
                        }
                        $parts[] = rawurlencode($name . (str_ends_with($name, '[]') ? '' : '[]'))
                            . '=' . rawurlencode(self::scalar($item));
                    }
                    continue;
                }
                self::flattenQuery($value, $name, $parts);
                continue;
            }
            if ($value === null) {
                continue;
            }
            $parts[] = rawurlencode($name) . '=' . rawurlencode(self::scalar($value));
        }
    }

    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function viaCurl(string $method, string $url, ?string $payload, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new VikendoException('Nelze inicializovat cURL pro Vikendo.', 502);
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $this->timeout(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new VikendoException(
                'Vikendo API není dostupné' . ($error !== '' ? ': ' . $error : '.'),
                502,
            );
        }

        return $this->decode((string) $raw, $status);
    }

    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function viaStreams(string $method, string $url, ?string $payload, array $headers): array
    {
        $header = implode("\r\n", $headers) . "\r\n";
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $header,
                'content' => $payload ?? '',
                'timeout' => $this->timeout(),
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new VikendoException('Vikendo API není dostupné.', 502);
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) === 1) {
            $status = (int) $m[1];
        }

        return $this->decode($raw, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw, int $status): array
    {
        if ($raw === '') {
            if ($status >= 200 && $status < 300) {
                return [];
            }
            throw new VikendoException('Vikendo vrátil prázdnou odpověď.', $status);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new VikendoException('Vikendo vrátil neplatnou JSON odpověď.', 502, $raw);
        }

        if ($status >= 400) {
            $message = (string) (
                $decoded['error']
                ?? $decoded['message']
                ?? $decoded['error_description']
                ?? 'Požadavek na Vikendo selhal.'
            );
            throw new VikendoException($message, $status, $decoded);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
