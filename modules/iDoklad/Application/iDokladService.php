<?php

declare(strict_types=1);

namespace Modules\iDoklad\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Setting\Settings;
use Modules\iDoklad\Domain\iDokladLink;

/**
 * Aplikační vrstva iDoklad — mapování dat + sync číselníků (inspirace athena _rpsCzech/iDoklad).
 */
final class iDokladService
{
    public function __construct(
        private readonly iDokladApiClient $api,
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function api(): iDokladApiClient
    {
        return $this->api;
    }

    public function isEnabled(): bool
    {
        return $this->api->isEnabled();
    }

    /**
     * @return list<array{id: int, type: string, label: string}>
     */
    public function documentTypes(?string $documentType = null): array
    {
        $types = [
            ['id' => 0, 'type' => 'IssuedInvoice', 'label' => 'idoklad.document_type.issued_invoice'],
            ['id' => 1, 'type' => 'ProformaInvoice', 'label' => 'idoklad.document_type.proforma_invoice'],
            ['id' => 2, 'type' => 'CashVoucher', 'label' => 'idoklad.document_type.cash_voucher'],
            ['id' => 3, 'type' => 'CreditNote', 'label' => 'idoklad.document_type.credit_note'],
            ['id' => 4, 'type' => 'BankStatement', 'label' => 'idoklad.document_type.bank_statement'],
            ['id' => 5, 'type' => 'ReceivedInvoice', 'label' => 'idoklad.document_type.received_invoice'],
            ['id' => 6, 'type' => 'SalesReceipt', 'label' => 'idoklad.document_type.sales_receipt'],
            ['id' => 7, 'type' => 'SalesOrder', 'label' => 'idoklad.document_type.sales_order'],
            ['id' => 9, 'type' => 'IssuedTaxDocument', 'label' => 'idoklad.document_type.issued_tax_document'],
        ];

        if ($documentType !== null && $documentType !== '') {
            foreach ($types as $row) {
                if ($row['type'] === $documentType) {
                    return [$row];
                }
            }

            return [];
        }

        return $types;
    }

    /**
     * @param array{
     *   name?: string,
     *   surname?: string,
     *   phone?: string,
     *   email?: string,
     *   company?: string,
     *   ico?: string,
     *   dic?: string,
     *   street?: string,
     *   city?: string,
     *   zip?: string,
     *   country_id?: int|null
     * } $userData
     * @return array<string, mixed>
     */
    public function setContact(array $userData, ?int $iDokladId = null): array
    {
        $first = trim((string) ($userData['name'] ?? ''));
        $surname = trim((string) ($userData['surname'] ?? ''));
        if ($surname === '' && str_contains($first, ' ')) {
            $parts = preg_split('/\s+/', $first, 2) ?: [$first];
            $first = (string) ($parts[0] ?? '');
            $surname = (string) ($parts[1] ?? '');
        }

        $company = trim((string) ($userData['company'] ?? ''));
        $contact = [
            'Firstname' => $first,
            'Surname' => $surname !== '' ? $surname : $first,
            'Mobile' => (string) ($userData['phone'] ?? ''),
            'Email' => (string) ($userData['email'] ?? ''),
            'CompanyName' => $company !== '' ? $company : trim($first . ' ' . $surname),
            'IdentificationNumber' => (string) ($userData['ico'] ?? ''),
            'VatIdentificationNumber' => (string) ($userData['dic'] ?? ''),
            'Street' => (string) ($userData['street'] ?? ''),
            'City' => (string) ($userData['city'] ?? ''),
            'PostalCode' => (string) ($userData['zip'] ?? ''),
        ];

        if (isset($userData['country_id']) && $userData['country_id'] !== null && (int) $userData['country_id'] > 0) {
            $contact['CountryId'] = (int) $userData['country_id'];
        }

        if ($iDokladId !== null && $iDokladId > 0) {
            $contact['Id'] = $iDokladId;
        }

        return $this->api->setContact($contact, $iDokladId);
    }

    /** @return array<string, mixed> */
    public function getContact(int $iDokladId): array
    {
        return $this->api->getContact($iDokladId);
    }

    /**
     * @param array<string, mixed> $documentData
     * @return array<string, mixed>
     */
    public function setDocument(array $documentData, int $partnerId, ?int $iDokladId = null): array
    {
        $type = (string) ($documentData['documentType'] ?? $documentData['document_type'] ?? 'invoice');

        return match ($type) {
            'invoice' => $this->api->setInvoice($this->normalizeIssuedDocument($documentData, $partnerId, $iDokladId), $iDokladId),
            'creditNote', 'credit_note' => $this->api->setCreditNote($this->normalizeCreditNote($documentData, $partnerId, $iDokladId), $iDokladId),
            'cashVoucher', 'cash_voucher' => $this->api->setCashVoucher($this->normalizeCashVoucher($documentData, $partnerId, $iDokladId), $iDokladId),
            default => [
                'isSucceed' => false,
                'code' => 400,
                'message' => 'idoklad.error.unknown_document_type',
                'data' => null,
                'items' => [],
                'totalItems' => 0,
                'totalPages' => 0,
            ],
        };
    }

    /** @return array<string, mixed> */
    public function getDocument(int $iDokladId, string $documentType): array
    {
        return match ($documentType) {
            'invoice' => $this->api->getInvoice($iDokladId),
            'creditNote', 'credit_note' => $this->api->getCreditNote($iDokladId),
            'cashVoucher', 'cash_voucher' => $this->api->getCashVoucher($iDokladId),
            default => [
                'isSucceed' => false,
                'code' => 400,
                'message' => 'idoklad.error.unknown_document_type',
                'data' => null,
                'items' => [],
                'totalItems' => 0,
                'totalPages' => 0,
            ],
        };
    }

    /** @return array<string, mixed> */
    public function getDocumentReport(int $iDokladId, string $documentType): array
    {
        $apiType = match ($documentType) {
            'invoice' => 'IssuedInvoice',
            'creditNote', 'credit_note' => 'CreditNote',
            'cashVoucher', 'cash_voucher' => 'CashVoucher',
            default => null,
        };

        if ($apiType === null) {
            return [
                'isSucceed' => false,
                'code' => 400,
                'message' => 'idoklad.error.unknown_document_type',
                'data' => null,
                'items' => [],
                'totalItems' => 0,
                'totalPages' => 0,
            ];
        }

        return $this->api->getReport($iDokladId, $apiType);
    }

    public function findLink(string $entityType, string $entityId, string $documentType = 'contact'): ?iDokladLink
    {
        $link = $this->em->getRepository(iDokladLink::class)->findOneBy([
            'entityType' => $entityType,
            'entityId' => $entityId,
            'documentType' => $documentType,
        ]);

        return $link instanceof iDokladLink ? $link : null;
    }

    public function rememberLink(
        string $entityType,
        string $entityId,
        string $iDokladId,
        string $documentType = 'contact',
        array $meta = [],
    ): iDokladLink {
        $link = $this->findLink($entityType, $entityId, $documentType);
        if ($link === null) {
            $link = new iDokladLink($entityType, $entityId, $iDokladId, $documentType);
            $this->em->persist($link);
        } else {
            $link->setIDokladId($iDokladId);
        }
        if ($meta !== []) {
            $link->setMeta($meta);
        }
        $this->em->flush();

        return $link;
    }

    /**
     * Sync číselníku do settings `idoklad.lists`.
     *
     * @return array<string, mixed>
     */
    public function syncList(string $name): array
    {
        $response = match ($name) {
            'countries' => $this->api->getListCountries(),
            'currencies' => $this->api->getListCurrencies(),
            'numericSequences' => $this->api->getNumericSequences(),
            'paymentOptions' => $this->api->getListPaymentOptions(),
            'cashRegisters' => $this->api->getListCashRegisters(),
            default => [
                'isSucceed' => false,
                'code' => 400,
                'message' => 'idoklad.error.unknown_list',
                'items' => [],
            ],
        };

        if ($response['isSucceed'] ?? false) {
            /** @var array<string, mixed> $lists */
            $lists = $this->settings->get('idoklad.lists', []) ?: [];
            if (!is_array($lists)) {
                $lists = [];
            }
            $lists[$name] = $response['items'] ?? [];
            $this->settings->set('idoklad.lists', $lists, \Gereq\Setting\SettingRecord::TYPE_JSON, 'idoklad');
        }

        return $response;
    }

    /** @return list<mixed> */
    public function cachedList(string $name): array
    {
        $lists = $this->settings->get('idoklad.lists', []) ?: [];
        if (!is_array($lists)) {
            return [];
        }
        $items = $lists[$name] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * @param array<string, mixed> $documentData
     * @return array<string, mixed>
     */
    private function normalizeIssuedDocument(array $documentData, int $partnerId, ?int $iDokladId): array
    {
        $payload = $this->baseDocumentPayload($documentData, $partnerId);
        if ($iDokladId !== null && $iDokladId > 0) {
            $payload['Id'] = $iDokladId;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $documentData
     * @return array<string, mixed>
     */
    private function normalizeCreditNote(array $documentData, int $partnerId, ?int $iDokladId): array
    {
        $payload = $this->baseDocumentPayload($documentData, $partnerId);
        $payload['CreditNoteReason'] = (string) ($documentData['creditNoteReason'] ?? $documentData['credit_note_reason'] ?? 'Dobropis');
        if (!empty($documentData['creditedInvoiceId'] ?? $documentData['credited_invoice_id'] ?? null)) {
            $payload['CreditedInvoiceId'] = (int) ($documentData['creditedInvoiceId'] ?? $documentData['credited_invoice_id']);
        }
        if ($iDokladId !== null && $iDokladId > 0) {
            $payload['Id'] = $iDokladId;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $documentData
     * @return array<string, mixed>
     */
    private function normalizeCashVoucher(array $documentData, int $partnerId, ?int $iDokladId): array
    {
        $items = $this->mapCashItems($documentData);
        $payload = [
            'DateOfTransaction' => (string) ($documentData['issueDate'] ?? $documentData['issue_date'] ?? date('c')),
            'IsIncomeTax' => true,
            'Items' => $items,
            'MovementType' => (int) ($documentData['movementType'] ?? $documentData['movement_type'] ?? 1),
            'Name' => (string) ($documentData['note'] ?? $documentData['name'] ?? ''),
            'PartnerId' => $partnerId,
        ];
        if (!empty($documentData['cashRegisterId'] ?? $documentData['cash_register_id'] ?? null)) {
            $payload['CashRegisterId'] = (int) ($documentData['cashRegisterId'] ?? $documentData['cash_register_id']);
        }
        if (!empty($documentData['variableSymbol'] ?? $documentData['variable_symbol'] ?? null)) {
            $payload['VariableSymbol'] = (string) ($documentData['variableSymbol'] ?? $documentData['variable_symbol']);
        }
        if (!empty($documentData['documentNumber'] ?? $documentData['document_number'] ?? null)) {
            $payload['DocumentSerialNumber'] = (int) substr((string) ($documentData['documentNumber'] ?? $documentData['document_number']), -4);
        }
        if ($iDokladId !== null && $iDokladId > 0) {
            $payload['Id'] = $iDokladId;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $documentData
     * @return array<string, mixed>
     */
    private function baseDocumentPayload(array $documentData, int $partnerId): array
    {
        $isTaxEnabled = (bool) ($documentData['taxEnabled'] ?? $documentData['tax_enabled'] ?? true);
        $payload = [
            'DateOfIssue' => (string) ($documentData['issueDate'] ?? $documentData['issue_date'] ?? date('c')),
            'DateOfMaturity' => (string) ($documentData['dueDate'] ?? $documentData['due_date'] ?? date('c', strtotime('+14 days'))),
            'DateOfTaxing' => (string) ($documentData['issueDate'] ?? $documentData['issue_date'] ?? date('c')),
            'Description' => (string) ($documentData['description'] ?? 'GEREQ'),
            'Note' => (string) ($documentData['internalNote'] ?? $documentData['internal_note'] ?? ''),
            'ItemsTextSuffix' => (string) ($documentData['note'] ?? ''),
            'PartnerId' => $partnerId,
            'Items' => $this->mapInvoiceItems($documentData, $isTaxEnabled),
            'IsEet' => false,
            'IsIncomeTax' => true,
        ];

        foreach ([
            'CurrencyId' => ['currencyId', 'currency_id'],
            'PaymentOptionId' => ['paymentOptionId', 'payment_option_id'],
            'NumericSequenceId' => ['numericSequenceId', 'numeric_sequence_id'],
            'VariableSymbol' => ['variableSymbol', 'variable_symbol'],
        ] as $target => $keys) {
            foreach ($keys as $key) {
                if (isset($documentData[$key]) && $documentData[$key] !== '' && $documentData[$key] !== null) {
                    $payload[$target] = is_numeric($documentData[$key]) ? (int) $documentData[$key] : $documentData[$key];
                    break;
                }
            }
        }

        if (!empty($documentData['documentNumber'] ?? $documentData['document_number'] ?? null)) {
            $payload['DocumentSerialNumber'] = (int) substr((string) ($documentData['documentNumber'] ?? $documentData['document_number']), -4);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $documentData
     * @return list<array<string, mixed>>
     */
    private function mapInvoiceItems(array $documentData, bool $isTaxEnabled): array
    {
        $raw = $documentData['items'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'Amount' => (float) ($item['quantity'] ?? $item['Amount'] ?? 1),
                'DiscountPercentage' => (float) ($item['discount'] ?? $item['DiscountPercentage'] ?? 0),
                'IsTaxMovement' => $isTaxEnabled,
                'Name' => (string) ($item['description'] ?? $item['name'] ?? $item['Name'] ?? ''),
                'PriceType' => $isTaxEnabled ? 1 : 2,
                'Unit' => (string) ($item['unit'] ?? $item['Unit'] ?? 'ks'),
                'UnitPrice' => (float) ($item['unitPrice'] ?? $item['unit_price'] ?? $item['UnitPrice'] ?? 0),
                'VatRateType' => (int) ($item['vatRateType'] ?? $item['VatRateType'] ?? ($isTaxEnabled ? 1 : 2)),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $documentData
     * @return list<array<string, mixed>>
     */
    private function mapCashItems(array $documentData): array
    {
        $raw = $documentData['items'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'Name' => (string) ($item['description'] ?? $item['name'] ?? $item['Name'] ?? ''),
                'Price' => (float) ($item['unitPrice'] ?? $item['unit_price'] ?? $item['Price'] ?? 0),
                'PriceType' => (int) ($item['priceType'] ?? $item['PriceType'] ?? 1),
                'VatRateType' => (int) ($item['vatRateType'] ?? $item['VatRateType'] ?? 1),
            ];
        }

        return $items;
    }
}
