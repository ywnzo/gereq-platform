<?php

declare(strict_types=1);

namespace Modules\iDoklad\Application;

use Gereq\Cache\AppCache;
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
            return $this->api('PATCH', 'Contacts/' . $iDokladId, body: $contactData);
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
            return $this->api('PATCH', 'IssuedInvoices/' . $iDokladId, body: $invoiceData);
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
            return $this->api('PATCH', 'CreditNotes/' . $iDokladId, body: $invoiceData);
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
            return $this->api('PATCH', 'CashVouchers/' . $iDokladId, body: $data);
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
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function api(string $method, string $path, array $query = [], ?array $body = null): array
    {
        try {
            $token = $this->accessToken();
            if ($token === null) {
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

            return $this->parse($response);
        } catch (\Throwable $e) {
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

            return null;
        }

        $expiresIn = (int) ($json['expires_in'] ?? 3600);
        $this->cache->put(self::CACHE_KEY, [
            'access_token' => $token,
            'expires_at' => time() + max(60, $expiresIn),
        ], max(60, $expiresIn - 30));

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
            $data = $data;
        }

        $message = null;
        if (isset($json['Message']) && is_string($json['Message'])) {
            $message = $json['Message'];
        } elseif (isset($json['message']) && is_string($json['message'])) {
            $message = $json['message'];
        }

        $totalItems = (int) ($json['TotalItems'] ?? $json['totalItems'] ?? (is_array($data) && !array_is_list($data) ? 1 : count($items)));
        $totalPages = (int) ($json['TotalPages'] ?? $json['totalPages'] ?? 0);

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
        return match ($message) {
            'CashVoucher is paired with invoice. These properties are not allowed to patch: Items'
                => 'idoklad.error.cash_voucher_paired',
            'CashVoucher cannot be created. PartnerId must be same as paired document'
                => 'idoklad.error.cash_voucher_partner_mismatch',
            default => $message,
        };
    }
}
