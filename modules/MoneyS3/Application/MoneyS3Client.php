<?php

declare(strict_types=1);

namespace Modules\MoneyS3\Application;

use Gereq\Cache\AppCache;
use Gereq\Setting\Settings;

/**
 * Money S3 GraphQL API klient (inspirace athena _rpsCzech/MoneyS3).
 */
final class MoneyS3Client
{
    private const CACHE_KEY = 'moneys3.access_token';
    private const UA = 'GEREQ-Platform/1.0 (+moneys3)';

    private ?string $lastError = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly AppCache $cache,
    ) {
    }

    public function getError(): ?string
    {
        return $this->lastError;
    }

    public function isConfigured(): bool
    {
        return $this->apiUrl() !== ''
            && $this->setting('moneys3.app_id') !== ''
            && $this->setting('moneys3.client_id') !== ''
            && $this->setting('moneys3.client_secret') !== ''
            && $this->setting('moneys3.username') !== ''
            && $this->setting('moneys3.password') !== '';
    }

    public function isEnabled(): bool
    {
        return filter_var($this->settings->get('moneys3.enabled', false), FILTER_VALIDATE_BOOLEAN)
            && $this->isConfigured();
    }

    /** @return array<string, mixed>|null */
    public function getAgendas(): ?array
    {
        return $this->sendRequest('{ agendas { items { name guid } } }');
    }

    /** @return array<string, mixed>|null */
    public function getCentres(string $agendaId): ?array
    {
        return $this->sendRequest(
            '{ centres { items{ id name shortCut } } }',
            [],
            'query',
            ['agendaid' => $agendaId],
        );
    }

    /** @return array<string, mixed>|null */
    public function getNumericalSeries(string $agendaId): ?array
    {
        return $this->sendRequest(
            '{ numericalSeries { items{ name number year prefix numberOfPlaces isInvoicesIssued } } }',
            [],
            'query',
            ['agendaid' => $agendaId],
        );
    }

    /** @return array<string, mixed>|null */
    public function getIssuedInvoices(string $agendaId, ?string $documentNumber = null): ?array
    {
        $fields = [
            'items' => [
                'id',
                'items' => ['type', 'description', 'shortCut', 'serialNumber', 'amount'],
                'dateOfIssue',
                'documentNumber',
                'orderNumber',
                'invoiceType',
                'issuedUser',
                'email',
                'phoneNumber',
                'centre' => ['name'],
            ],
        ];
        $where = $documentNumber !== null && $documentNumber !== ''
            ? [['documentNumber', 'eq', $documentNumber]]
            : [];

        return $this->sendRequest(
            self::buildQuery('issuedInvoices', $fields, $where),
            [],
            'query',
            ['agendaid' => $agendaId],
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function setIssuedInvoice(string $agendaId, array $data): ?array
    {
        $input = [
            'issuedInvoice' => $data,
            'definitionXMLTransfer' => ['shortCut' => '_FP+FV'],
        ];

        return $this->sendRequest(
            self::buildMutation('createIssuedInvoice', $input, ['guid', 'isSuccess']),
            [],
            'mutation',
            ['agendaid' => $agendaId],
        );
    }

    /** @return array<string, mixed>|null */
    public function getCashVoucher(string $agendaId, ?string $documentNumber = null): ?array
    {
        $fields = [
            'items' => [
                'id',
                'dateOfIssue',
                'documentNumber',
                'documentType',
                'centre' => ['name'],
                'totalWithVatHc',
            ],
        ];
        $where = $documentNumber !== null && $documentNumber !== ''
            ? [['documentNumber', 'eq', $documentNumber]]
            : [];

        return $this->sendRequest(
            self::buildQuery('cashVouchers', $fields, $where),
            [],
            'query',
            ['agendaid' => $agendaId],
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function setCashVoucher(string $agendaId, array $data): ?array
    {
        $input = [
            'cashVoucher' => $data,
            'definitionXMLTransfer' => ['shortCut' => '_PD'],
        ];

        return $this->sendRequest(
            self::buildMutation('createCashVoucher', $input, ['guid', 'isSuccess']),
            [],
            'mutation',
            ['agendaid' => $agendaId],
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<array{0: string, 1: string, 2: mixed}> $where
     */
    public static function buildQuery(string $operation, array $fields, array $where = []): string
    {
        $whereClause = self::buildWhereClause($where);
        $fieldsString = self::buildFieldsString($fields);

        return "{ {$operation}{$whereClause} { {$fieldsString} } }";
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>|array<string, mixed> $fields
     */
    public static function buildMutation(string $operation, array $input, array $fields): string
    {
        $inputString = self::buildInputString($input);
        $fieldsString = self::buildFieldsString($fields);

        return "mutation { {$operation}({$inputString}) { {$fieldsString} } }";
    }

    /**
     * @param array<string, mixed> $variables
     * @param array<string, string> $customHeaders
     * @return array<string, mixed>|null
     */
    public function sendRequest(
        string $fields,
        array $variables = [],
        string $type = 'query',
        array $customHeaders = [],
    ): ?array {
        $this->lastError = null;

        if (!in_array($type, ['query', 'mutation', 'subscription'], true)) {
            $this->lastError = 'Invalid request type';

            return null;
        }

        $token = $this->grantAccess();
        if ($token === null) {
            $this->lastError = 'Failed to get access token';

            return null;
        }

        $headers = array_merge([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: ' . self::UA,
        ], $this->formatHeaders($customHeaders));

        try {
            $response = $this->http(
                'POST',
                rtrim($this->apiUrl(), '/') . '/graphql',
                $headers,
                json_encode([
                    'query' => $fields,
                    'variables' => (object) $variables,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return null;
        }

        $json = is_array($response['json']) ? $response['json'] : null;
        if ($json === null || $response['status'] >= 400) {
            $this->lastError = 'MoneyS3 GraphQL request failed (HTTP ' . $response['status'] . ')';

            return null;
        }

        if (isset($json['errors']) && is_array($json['errors']) && $json['errors'] !== []) {
            $first = $json['errors'][0];
            $this->lastError = is_array($first)
                ? (string) ($first['message'] ?? 'GraphQL error')
                : 'GraphQL error';
        }

        return $json;
    }

    private function grantAccess(): ?string
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['access_token'], $cached['expires_at'])) {
            if ((int) $cached['expires_at'] > time() + 60) {
                return (string) $cached['access_token'];
            }
        }

        $token = $this->fetchAccessToken();
        if ($token === null) {
            $this->cache->forget(self::CACHE_KEY);

            return null;
        }

        $expiresIn = (int) ($token['expires_in'] ?? 3600);
        $this->cache->put(self::CACHE_KEY, [
            'access_token' => $token['access_token'],
            'expires_at' => time() + max(60, $expiresIn),
        ], max(60, $expiresIn - 30));

        return (string) $token['access_token'];
    }

    /** @return array{access_token: string, expires_in?: int}|null */
    private function fetchAccessToken(): ?array
    {
        $apiUrl = $this->apiUrl();
        $appId = $this->setting('moneys3.app_id');
        $clientId = $this->setting('moneys3.client_id');
        $clientSecret = $this->setting('moneys3.client_secret');
        $username = $this->setting('moneys3.username');
        $password = $this->setting('moneys3.password');

        if ($apiUrl === '' || $appId === '' || $clientId === '' || $clientSecret === '') {
            return null;
        }

        $auth = base64_encode($clientId . ':' . $clientSecret);
        $url = rtrim($apiUrl, '/') . '/connect/token?AppId=' . rawurlencode($appId);

        try {
            $response = $this->http(
                'POST',
                $url,
                [
                    'Authorization: Basic ' . $auth,
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                    'User-Agent: ' . self::UA,
                ],
                http_build_query([
                    'grant_type' => 'password',
                    'username' => $username,
                    'password' => $password,
                    'scope' => 's3api',
                ]),
            );
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return null;
        }

        $json = is_array($response['json']) ? $response['json'] : [];
        if ($response['status'] >= 400 || empty($json['access_token'])) {
            $this->lastError = 'MoneyS3 token request failed';

            return null;
        }

        return [
            'access_token' => (string) $json['access_token'],
            'expires_in' => (int) ($json['expires_in'] ?? 3600),
        ];
    }

    private function apiUrl(): string
    {
        $fromSettings = $this->setting('moneys3.api_url');
        if ($fromSettings !== '') {
            return $fromSettings;
        }

        return trim((string) env_value('MONEYS3_API_URL', ''));
    }

    private function setting(string $key): string
    {
        $value = trim((string) $this->settings->get($key, ''));
        if ($value !== '') {
            return $value;
        }

        return match ($key) {
            'moneys3.app_id' => trim((string) env_value('MONEYS3_APP_ID', '')),
            'moneys3.client_id' => trim((string) env_value('MONEYS3_CLIENT_ID', '')),
            'moneys3.client_secret' => trim((string) env_value('MONEYS3_CLIENT_SECRET', '')),
            'moneys3.username' => trim((string) env_value('MONEYS3_CLIENT_USERNAME', '')),
            'moneys3.password' => trim((string) env_value('MONEYS3_CLIENT_PASSWORD', '')),
            'moneys3.api_url' => trim((string) env_value('MONEYS3_API_URL', '')),
            default => '',
        };
    }

    /**
     * @param list<array{0: string, 1: string, 2: mixed}> $where
     */
    private static function buildWhereClause(array $where): string
    {
        if ($where === []) {
            return '';
        }

        $conditions = [];
        foreach ($where as $condition) {
            [$field, $operator, $value] = $condition;
            $formatted = is_numeric($value) ? (string) $value : '"' . addcslashes((string) $value, '"\\') . '"';
            $conditions[] = "{$field}: { {$operator}: {$formatted} }";
        }

        return '(where: { ' . implode(', ', $conditions) . ' })';
    }

    /** @param array<string, mixed>|list<string> $fields */
    private static function buildFieldsString(array $fields): string
    {
        $result = [];
        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                $result[] = $key . ' { ' . self::buildFieldsString($value) . ' }';
            } else {
                $result[] = (string) $value;
            }
        }

        return implode(' ', $result);
    }

    /** @param array<string, mixed> $input */
    private static function buildInputString(array $input): string
    {
        $result = [];
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    $mapped = array_map(
                        static fn (mixed $item): string => '{ ' . self::buildInputString(is_array($item) ? $item : []) . ' }',
                        $value,
                    );
                    $result[] = $key . ': [' . implode(', ', $mapped) . ']';
                } else {
                    $result[] = $key . ': { ' . self::buildInputString($value) . ' }';
                }
            } else {
                if (is_bool($value)) {
                    $formatted = $value ? 'true' : 'false';
                } elseif (is_numeric($value)) {
                    $formatted = (string) $value;
                } else {
                    $formatted = '"' . addcslashes((string) $value, '"\\') . '"';
                }
                $result[] = $key . ': ' . $formatted;
            }
        }

        return implode(', ', $result);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, json: mixed}
     */
    private function http(string $method, string $url, array $headers, string $body): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('Nelze inicializovat cURL.');
            }
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                throw new \RuntimeException('HTTP požadavek selhal' . ($error !== '' ? ': ' . $error : '.'));
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $headers),
                    'content' => $body,
                    'timeout' => 45,
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($url, false, $context);
            if ($raw === false) {
                throw new \RuntimeException('HTTP požadavek selhal.');
            }
            $status = 0;
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) === 1) {
                $status = (int) $m[1];
            }
        }

        $json = null;
        try {
            $json = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $json = null;
        }

        return ['status' => $status, 'json' => $json];
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                $lines[] = $value;
                continue;
            }
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
