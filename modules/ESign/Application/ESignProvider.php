<?php

declare(strict_types=1);

namespace Modules\ESign\Application;

use Modules\ESign\Domain\SignableDocument;
use Modules\ESign\Domain\Signature;
use Symfony\Component\HttpFoundation\Request;

/** Capability facade for other modules. */
final class ESignProvider
{
    public const SOURCE_CARSERVICE_ORDER = 'carservice_order';
    public const SOURCE_BIKESERVICE_ORDER = 'bikeservice_order';

    public function __construct(private readonly DocumentSigningService $signing)
    {
    }

    public function createDocument(
        string $title,
        string $bodyHtml,
        string $counterpartyName,
        ?string $counterpartyEmail = null,
        ?string $counterpartyCompany = null,
        ?string $number = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
    ): SignableDocument {
        return $this->signing->create(
            $title,
            $bodyHtml,
            $counterpartyName,
            $counterpartyEmail,
            $counterpartyCompany,
            $number,
            $sourceType,
            $sourceId,
        );
    }

    /** @return list<SignableDocument> */
    public function documentsForSource(string $sourceType, string $sourceId): array
    {
        return $this->signing->forSource($sourceType, $sourceId);
    }

    public function latestForSource(string $sourceType, string $sourceId): ?SignableDocument
    {
        return $this->signing->latestForSource($sourceType, $sourceId);
    }

    /**
     * Digitální podpis na místě (oba pady) nebo odeslání odkazu zákazníkovi.
     *
     * @return array{
     *   mode: 'in_person'|'remote',
     *   document: SignableDocument,
     *   signing_url?: string,
     *   provider_image?: ?string,
     *   counterparty_image?: ?string
     * }
     */
    public function signHandover(
        string $sourceType,
        string $sourceId,
        string $title,
        string $bodyHtml,
        string $counterpartyName,
        ?string $counterpartyEmail = null,
        ?string $counterpartyCompany = null,
        ?string $providerPng = null,
        ?string $counterpartyPng = null,
        ?Request $request = null,
    ): array {
        $document = $this->createDocument(
            $title,
            $bodyHtml,
            $counterpartyName,
            $counterpartyEmail,
            $counterpartyCompany,
            null,
            $sourceType,
            $sourceId,
        );

        $customerPad = $counterpartyPng !== null && trim($counterpartyPng) !== '';
        if ($customerPad) {
            $result = $this->signing->completeInPerson($document, (string) $counterpartyPng, $providerPng, $request);
            $providerSig = $result['provider'];
            $customerSig = $result['counterparty'];

            return [
                'mode' => 'in_person',
                'document' => $result['document'],
                'provider_image' => $this->signing->signatureDataUri($providerSig->signaturePath()),
                'counterparty_image' => $this->signing->signatureDataUri($customerSig->signaturePath()),
            ];
        }

        $email = $counterpartyEmail !== null ? trim($counterpartyEmail) : '';
        if ($email === '') {
            throw new \InvalidArgumentException('esign.error.digital_needs_pad_or_email');
        }

        $this->signing->applyProviderSignature($document, $providerPng);
        $sent = $this->signing->sendForSignature($document, $email, $request);
        $providerSig = $this->signing->findSignature($document->id(), Signature::PARTY_PROVIDER);

        return [
            'mode' => 'remote',
            'document' => $document,
            'signing_url' => $sent['url'],
            'provider_image' => $providerSig !== null
                ? $this->signing->signatureDataUri($providerSig->signaturePath())
                : null,
            'counterparty_image' => null,
        ];
    }

    public function signing(): DocumentSigningService
    {
        return $this->signing;
    }
}
