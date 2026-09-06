<?php

declare(strict_types=1);

namespace Modules\iDoklad\Application;

use Gereq\Cache\AppCache;
use Gereq\Debug\FullServiceLogger;
use Gereq\Setting\Settings;

/**
 * Low-level iDoklad API v3 klient (OAuth2 Client Credentials).
 *
 * @see https://api.idoklad.cz/
 */
final class iDokladApiClient
{
    private const TOKEN_URL = 'https://identity.idoklad.cz/server/connect/token';
    private const API_BASE = 'https://api.idoklad.cz/v3';
    private const CACHE_KEY = 'idoklad.access_token';

    public function __construct(
        private readonly Settings $settings,
        private readonly AppCache $cache,
        private readonly HttpJsonClient $http,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->settings->get('idoklad.client_id', '')) !== ''
            && trim((string) $this->settings->get('idoklad.client_secret', '')) !== '';
    }

    public function isEnabled(): bool
    {
        return filter_var($this->settings->get('idoklad.enabled', false), FILTER_VALIDATE_BOOLEAN)
            && $this->isConfigured();
    }

    /** @return array<string, mixed> */
    public function getListCountries(int $page = 1): array
    {
        return $this->fullPaginate('Countries', $page);
    }

    /** @return array<string, mixed> */
    public function getListCurrencies(int $page = 1): array
    {
        return $this->fullPaginate('Currencies', $page);
    }

    /** @return array<string, mixed> */
    public function getListPaymentOptions(int $page = 1): array
    {
        return $this->fullPaginate('PaymentOptions', $page);
    }

    /** @return array<string, mixed> */
    public function getListCashRegisters(int $page = 1): array
    {
        return $this->fullPaginate('CashRegisters', $page);
    }

    /** @return array<string, mixed> */
    public function getNumericSequences(int $page = 1): array
    {
        return $this->fullPaginate('NumericSequences', $page, 20);
    }

    /** @return array<string, mixed> */
    public function getNumericSequenceInfo(int $id): array
    {
        return $this->api('GET', 'NumericSequences', query: ['filter' => 'Id~eq~' . $id]);
    }

    /**
     * @param array<string, mixed> $contactData
     * @return array<string, mixed>
     */
    public function setContact(array $contactData, ?int $iDokladId = null): array
    {
        if ($iDokladId !== null && $iDokladId > 0) {
            $contactData['Id'] = $iDokladId;

            // iDoklad v3: PATCH /Contacts s Id v body (ne /Contacts/{id} → 405).
            return $this->api('PATCH', 'Contacts', body: $contactData);
        }

        return $this->api('POST', 'Contacts', body: $contactData);
    }

    /** @return array<string, mixed> */
    public function getContact(int $iDokladId): array
    {
        return $this->api('GET', 'Contacts/' . $iDokladId);
    }

    /**
     * @param array<string, mixed> $invoiceData
     * @return array<string, mixed>
     */
    public function setInvoice(array $invoiceData, ?int $iDokladId = null): array
    {
        if ($iDokladId !== null && $iDokladId > 0) {
            $invoiceData['Id'] = $iDokladId;

            return $this->api('PATCH', 'IssuedInvoices', body: $invoiceData);
        }

        return $this->api('POST', 'IssuedInvoices', body: $invoiceData);
    }

    /** @return array<string, mixed> */
    public function getInvoice(int $iDokladId): array
    {
        return $this->api('GET', 'IssuedInvoices/' . $iDokladId);
    }

    /**
     * @param array<string, mixed> $invoiceData
     * @return array<string, mixed>
     */
    public function setCreditNote(array $invoiceData, ?int $iDokladId = null): array
    {
        if ($iDokladId !== null && $iDokladId > 0) {
            $invoiceData['Id'] = $iDokladId;

            return $this->api('PATCH', 'CreditNotes', body: $invoiceData);
        }

        return $this->api('POST', 'CreditNotes', body: $invoiceData);
    }

    /** @return array<string, mixed> */
    public function getCreditNote(int $iDokladId): array
    {
        return $this->api('GET', 'CreditNotes/' . $iDokladId);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function setCashVoucher(array $data, ?int $iDokladId = null): array
    {
        if ($iDokladId !== null && $iDokladId > 0) {
            $data['Id'] = $iDokladId;

            return $this->api('PATCH', 'CashVouchers', body: $data);
        }

        return $this->api('POST', 'CashVouchers', body: $data);
    }

    /** @return array<string, mixed> */
    public function getCashVoucher(int $iDokladId): array
    {
        return $this->api('GET', 'CashVouchers/' . $iDokladId);
    }

    /** @return array<string, mixed> */
    public function getReport(int $iDokladId, string $documentType): array
    {
        return $this->api('GET', 'Reports/' . $documentType . '/' . $iDokladId . '/Pdf');
    }

    /**
     * Stáhne PDF report jako binární obsah (nebo base64 z JSON Data).
     */
    public function getReportPdf(int $iDokladId, string $documentType): ?string
    {
        $path = 'Reports/' . $documentType . '/' . $iDokladId . '/Pdf';
        FullServiceLogger::log('idoklad.api.pdf.request', [
            'path' => $path,
            'idoklad_id' => $iDokladId,
            'document_type' => $documentType,
        ]);
        try {
            $token = $this->accessToken();
            if ($token === null) {
                FullServiceLogger::log('idoklad.api.pdf.token_failed', [
                    'path' => $path,
                ]);

                return null;
            }

            $url = self::API_BASE . '/' . ltrim($path, '/');
            $response = $this->http->request(
                'GET',
                $url,
                [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/pdf',
                ],
            );
            $status = (int) ($response['status'] ?? 0);
            $body = (string) ($response['body'] ?? '');
            if ($status >= 200 && $status < 300 && str_starts_with($body, '%PDF')) {
                FullServiceLogger::log('idoklad.api.pdf.ok', [
                    'path' => $path,
                    'bytes' => strlen($body),
                    'source' => 'binary',
                ]);

                return $body;
            }

            // fallback: JSON s base64 v Data
            $jsonResponse = $this->http->request(
                'GET',
                $url,
                [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                ],
            );
            $parsed = $this->parse($jsonResponse);
            $data = $parsed['data'] ?? null;
            $encoded = null;
            if (is_string($data) && $data !== '') {
                $encoded = $data;
            } elseif (is_array($data)) {
                $encoded = $data['Pdf'] ?? $data['pdf'] ?? $data['Data'] ?? $data['data'] ?? null;
            }
            if (!is_string($encoded) || $encoded === '') {
                FullServiceLogger::log('idoklad.api.pdf.empty', [
                    'path' => $path,
                    'status' => $status,
                    'json_status' => $jsonResponse['status'] ?? null,
                    'message' => $parsed['message'] ?? null,
                ]);

                return null;
            }
            $binary = base64_decode($encoded, true);
            if ($binary === false || !str_starts_with($binary, '%PDF')) {
                // někdy API pošle raw PDF v Data jako string bez base64
                if (str_starts_with($encoded, '%PDF')) {
                    $binary = $encoded;
                } else {
                    FullServiceLogger::log('idoklad.api.pdf.decode_failed', [
                        'path' => $path,
                        'encoded_prefix' => substr($encoded, 0, 16),
                    ]);

                    return null;
                }
            }
            FullServiceLogger::log('idoklad.api.pdf.ok', [
                'path' => $path,
                'bytes' => strlen($binary),
                'source' => 'base64',
            ]);

            return $binary;
        } catch (\Throwable $e) {
            FullServiceLogger::log('idoklad.api.pdf.exception', [
                'path' => $path,
                'error' => $e,
            ]);

            return null;
        }
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function api(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $started = microtime(true);
        FullServiceLogger::log('idoklad.api.request', [
            'method' => strtoupper($method),
            'path' => $path,
            'query' => $query,
            'body' => $body,
        ]);
        try {
            $token = $this->accessToken();
            if ($token === null) {
                FullServiceLogger::log('idoklad.api.token_failed', [
                    'method' => strtoupper($method),
                    'path' => $path,
                ]);

                return $this->fail(401, 'Nepodařilo se získat access token iDoklad.');
            }

            $url = self::API_BASE . '/' . ltrim($path, '/');
            if ($query !== []) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
            }

            $response = $this->http->request(
                $method,
                $url,
                ['Authorization: Bearer ' . $token],
                $body,
            );

            $parsed = $this->parse($response);
            FullServiceLogger::log('idoklad.api.response', [
                'method' => strtoupper($method),
                'path' => $path,
                'http_status' => $response['status'],
                'isSucceed' => !empty($parsed['isSucceed']),
                'code' => $parsed['code'] ?? null,
                'message' => $parsed['message'] ?? null,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'data' => $parsed['data'] ?? null,
                'items_count' => is_array($parsed['items'] ?? null) ? count($parsed['items']) : 0,
            ]);

            return $parsed;
        } catch (\Throwable $e) {
            FullServiceLogger::log('idoklad.api.exception', [
                'method' => strtoupper($method),
                'path' => $path,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $e,
            ]);

            return $this->fail(500, $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fullPaginate(string $resource, int $page = 1, int $pageSize = 200): array
    {
        $response = $this->api('GET', $resource, [
            'page' => $page,
            'pageSize' => $pageSize,
        ]);

        if (!($response['isSucceed'] ?? false)) {
            return $response;
        }

        $items = is_array($response['items'] ?? null) ? $response['items'] : [];
        $total = (int) ($response['totalItems'] ?? count($items));
        if ($total > $pageSize && count($items) === $pageSize) {
            $next = $this->fullPaginate($resource, $page + 1, $pageSize);
            if ($next['isSucceed'] ?? false) {
                $items = array_merge($items, is_array($next['items'] ?? null) ? $next['items'] : []);
            }
        }

        $response['items'] = $items;
        $response['totalItems'] = count($items);

        return $response;
    }

    private function accessToken(): ?string
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['access_token'], $cached['expires_at'])) {
            if ((int) $cached['expires_at'] > time() + 60) {
                return (string) $cached['access_token'];
            }
        }

        $clientId = trim((string) $this->settings->get('idoklad.client_id', ''));
        $clientSecret = trim((string) $this->settings->get('idoklad.client_secret', ''));
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $response = $this->http->request(
            'POST',
            self::TOKEN_URL,
            [],
            [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'idoklad_api',
            ],
            'application/x-www-form-urlencoded',
        );

        $json = is_array($response['json']) ? $response['json'] : [];
        $token = (string) ($json['access_token'] ?? '');
        if ($token === '' || $response['status'] >= 400) {
            $this->cache->forget(self::CACHE_KEY);
            FullServiceLogger::log('idoklad.oauth.failed', [
                'http_status' => $response['status'],
                'error' => $json['error'] ?? null,
                'error_description' => $json['error_description'] ?? null,
            ]);

            return null;
        }

        $expiresIn = (int) ($json['expires_in'] ?? 3600);
        $this->cache->put(self::CACHE_KEY, [
            'access_token' => $token,
            'expires_at' => time() + max(60, $expiresIn),
        ], max(60, $expiresIn - 30));

        FullServiceLogger::log('idoklad.oauth.ok', [
            'expires_in' => $expiresIn,
            'token' => $token,
        ]);

        return $token;
    }

    /**
     * @param array{status: int, body: string, json: mixed} $response
     * @return array<string, mixed>
     */
    private function parse(array $response): array
    {
        $status = $response['status'];
        $json = is_array($response['json']) ? $response['json'] : [];
        $data = $json['Data'] ?? $json['data'] ?? (isset($json['Id']) ? $json : null);
        $items = [];
        if (is_array($data) && array_is_list($data)) {
            $items = $data;
        } elseif (is_array($json['Items'] ?? null)) {
            $items = $json['Items'];
        } elseif (is_array($json['items'] ?? null)) {
            $items = $json['items'];
        } elseif (is_array($data) && isset($data['Items']) && is_array($data['Items'])) {
            $items = $data['Items'];
        }

        if (is_array($items) && $items !== [] && !array_is_list($items)) {
            $items = array_values($items);
        }

        $message = null;
        if (isset($json['Message']) && is_string($json['Message'])) {
            $message = $json['Message'];
        } elseif (isset($json['message']) && is_string($json['message'])) {
            $message = $json['message'];
        } elseif (is_array($json['Errors'] ?? null)) {
            $parts = [];
            foreach ($json['Errors'] as $err) {
                if (is_string($err)) {
                    $parts[] = $err;
                } elseif (is_array($err)) {
                    $parts[] = (string) ($err['Message'] ?? $err['message'] ?? json_encode($err));
                }
            }
            if ($parts !== []) {
                $message = implode('; ', $parts);
            }
        }

        $totalItems = (int) (
            $json['TotalItems']
            ?? $json['totalItems']
            ?? (is_array($data) ? ($data['TotalItems'] ?? $data['totalItems'] ?? null) : null)
            ?? (is_array($data) && !array_is_list($data) && !isset($data['Items']) ? 1 : count($items))
        );
        $totalPages = (int) (
            $json['TotalPages']
            ?? $json['totalPages']
            ?? (is_array($data) ? ($data['TotalPages'] ?? $data['totalPages'] ?? 0) : 0)
        );

        return [
            'isSucceed' => $status >= 200 && $status < 300,
            'code' => $status,
            'codeText' => (string) ($json['Status'] ?? ''),
            'message' => $this->mapErrorMessages($message),
            'data' => $data,
            'items' => $items,
            'totalItems' => $totalItems,
            'totalPages' => $totalPages,
            'raw' => $json,
        ];
    }

    /** @return array<string, mixed> */
    private function fail(int $code, string $message): array
    {
        return [
            'isSucceed' => false,
            'code' => $code,
            'codeText' => null,
            'message' => $this->mapErrorMessages($message),
            'data' => null,
            'items' => [],
            'totalItems' => 0,
            'totalPages' => 0,
            'raw' => null,
        ];
    }

    private function mapErrorMessages(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        $normalized = trim($message);

        return match (true) {
            $normalized === 'CashVoucher is paired with invoice. These properties are not allowed to patch: Items'
                => 'idoklad.error.cash_voucher_paired',
            $normalized === 'CashVoucher cannot be created. PartnerId must be same as paired document'
                => 'idoklad.error.cash_voucher_partner_mismatch',
            str_contains(strtolower($normalized), 'countryid') && str_contains(strtolower($normalized), 'required')
                => 'idoklad.error.country_required',
            default => $message,
        };
    }
}
