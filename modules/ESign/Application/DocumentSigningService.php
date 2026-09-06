<?php

declare(strict_types=1);

namespace Modules\ESign\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\Http\Router;
use Gereq\Setting\Settings;
use Modules\ESign\Domain\Signature;
use Modules\ESign\Domain\SignableDocument;
use Modules\ESign\Domain\SigningLink;
use Symfony\Component\HttpFoundation\Request;

final class DocumentSigningService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentRenderer $renderer,
        private readonly SignatureStorage $storage,
        private readonly TokenCipher $cipher,
        private readonly Settings $settings,
        private readonly Router $router,
        private readonly Authenticator $auth,
    ) {
    }

    /** @return array<string, string> */
    public function consentTexts(): array
    {
        return [
            'read' => 'Potvrzuji, že jsem si celý dokument přečetl(a), rozumím jeho obsahu a souhlasím s ním.',
            'authority' => 'Potvrzuji, že jsem oprávněn(a) uzavřít tento dokument za protistranu uvedenou v dokumentu.',
            'eidas' => 'Souhlasím s uzavřením dokumentu v elektronické podobě prostým elektronickým podpisem podle nařízení eIDAS (čl. 3 bod 10 a čl. 25) a § 562 občanského zákoníku. Beru na vědomí, že nejde o zaručený ani kvalifikovaný elektronický podpis.',
        ];
    }

    public function consentVersion(): string
    {
        return (string) $this->settings->get('esign.consent_version', '2026-09-06');
    }

    public function linkTtlDays(): int
    {
        return max(1, (int) $this->settings->get('esign.link_ttl_days', 14));
    }

    public function nextNumber(): string
    {
        $year = (new \DateTimeImmutable())->format('Y');
        $prefix = 'ES-' . $year . '-';
        $rows = $this->em->getRepository(SignableDocument::class)
            ->createQueryBuilder('d')
            ->select('d.number')
            ->where('d.number LIKE :p')
            ->setParameter('p', $prefix . '%')
            ->orderBy('d.number', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult();
        $last = isset($rows[0]['number']) ? (string) $rows[0]['number'] : '';
        $seq = 1;
        if (preg_match('/ES-\d{4}-(\d+)$/', $last, $m)) {
            $seq = (int) $m[1] + 1;
        }

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function create(
        string $title,
        string $bodyHtml,
        string $counterpartyName,
        ?string $counterpartyEmail = null,
        ?string $counterpartyCompany = null,
        ?string $number = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
    ): SignableDocument {
        $document = new SignableDocument(
            $number !== null && trim($number) !== '' ? trim($number) : $this->nextNumber(),
            trim($title),
            $bodyHtml,
            trim($counterpartyName),
        );
        $document->setCounterpartyEmail($counterpartyEmail);
        $document->setCounterpartyCompany($counterpartyCompany);
        $document->setSource($sourceType, $sourceId);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    /** @return list<SignableDocument> */
    public function forSource(string $sourceType, string $sourceId): array
    {
        if ($sourceType === '' || $sourceId === '') {
            return [];
        }
        /** @var list<SignableDocument> $items */
        $items = $this->em->getRepository(SignableDocument::class)->findBy(
            ['sourceType' => $sourceType, 'sourceId' => $sourceId],
            ['createdAt' => 'DESC'],
        );

        return $items;
    }

    public function latestForSource(string $sourceType, string $sourceId): ?SignableDocument
    {
        $items = $this->forSource($sourceType, $sourceId);

        return $items[0] ?? null;
    }

    public function applyProviderSignature(SignableDocument $document, ?string $pngDataUri = null): Signature
    {
        if ($document->isLocked()) {
            throw new \RuntimeException('esign.error.locked');
        }
        if ($document->isFullySigned()) {
            throw new \RuntimeException('esign.error.already_signed');
        }

        $destination = null;
        $method = Signature::METHOD_SEAL;

        if ($pngDataUri !== null && trim($pngDataUri) !== '') {
            $destination = $this->storage->writePngDataUri(
                $this->storage->documentDir($document->id()) . '/provider.png',
                $pngDataUri,
            );
            $method = Signature::METHOD_DRAWN;
        } else {
            $source = $document->providerSignaturePath()
                ?: (string) $this->settings->get('esign.provider_signature_path', '');
            $binary = $source !== '' ? $this->storage->read($source) : null;
            if (is_string($binary)) {
                $ext = pathinfo($source, PATHINFO_EXTENSION) ?: 'png';
                $destination = $this->storage->documentDir($document->id()) . '/provider.' . $ext;
                $this->storage->writePng($destination, $binary);
                $method = Signature::METHOD_IMAGE;
            }
        }

        $signedAt = new \DateTimeImmutable();
        $signedBy = $this->providerSignerName();
        $hash = hash('sha256', implode('|', [
            $document->number(),
            $document->title(),
            $document->bodyHtml(),
            $signedAt->format(\DateTimeInterface::ATOM),
            $signedBy,
        ]));

        $document->applyProviderSignature($destination, $signedBy, $hash);

        $signature = $this->upsertSignature(
            $document->id(),
            Signature::PARTY_PROVIDER,
            $signedBy,
            $method,
            $destination,
        );
        $user = $this->auth->user();
        $signerEmail = $user !== null && trim((string) ($user->email() ?? '')) !== ''
            ? (string) $user->email()
            : (string) $this->settings->get('esign.provider_email', '');
        $signature->setSignerEmail($signerEmail);
        $signature->setSignerRole('poskytovatel');
        $signature->setConsentVersion($this->consentVersion());
        $signature->setDocumentHash($hash);
        $signature->setConsents(null);
        $signature->setIpAddress(null);
        $signature->setUserAgent(null);
        $signature->touchSignedAt();

        $this->em->flush();

        return $signature;
    }

    /** Jméno podepisujícího za servis — přihlášený uživatel, jinak nastavení eSign. */
    public function providerSignerName(): string
    {
        $user = $this->auth->user();
        if ($user !== null) {
            $name = trim($user->name() ?: (string) ($user->email() ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
        $fallback = trim((string) $this->settings->get('esign.provider_name', 'GEREQ'));

        return $fallback !== '' ? $fallback : 'GEREQ';
    }

    public function defaultProviderSignatureDataUri(): ?string
    {
        $path = trim((string) $this->settings->get('esign.provider_signature_path', ''));

        return $path !== '' ? $this->signatureDataUri($path) : null;
    }

    public function hasProviderSignature(SignableDocument $document): bool
    {
        return $this->findSignature($document->id(), Signature::PARTY_PROVIDER) !== null;
    }

    public function hasCounterpartySignature(SignableDocument $document): bool
    {
        return $this->findSignature($document->id(), Signature::PARTY_COUNTERPARTY) !== null;
    }

    /**
     * @return array{url: string, token: string, link: SigningLink}
     */
    public function sendForSignature(SignableDocument $document, string $email, ?Request $request = null): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('esign.error.invalid_email');
        }
        if ($document->isFullySigned()) {
            throw new \RuntimeException('esign.error.already_signed');
        }
        if (!$this->hasProviderSignature($document)) {
            throw new \RuntimeException('esign.error.provider_required');
        }
        if (trim($document->counterpartyName()) === '') {
            throw new \RuntimeException('esign.error.counterparty_required');
        }
        if ($document->status() === SignableDocument::STATUS_CANCELLED) {
            $document->reopenDraft();
        }

        $this->em->beginTransaction();
        try {
            $this->lockDocument($document);
            $this->revokeOpenLinks($document->id());

            $rawToken = bin2hex(random_bytes(32));
            $link = new SigningLink(
                $document->id(),
                hash('sha256', $rawToken),
                $email,
                new \DateTimeImmutable('+' . $this->linkTtlDays() . ' days'),
                $this->cipher->encrypt($rawToken),
            );
            $document->setCounterpartyEmail($email);
            $this->em->persist($link);
            $this->em->flush();
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }

        $url = $this->router->absoluteUrl('esign.sign', ['token' => $rawToken], $request);

        return ['url' => $url, 'token' => $rawToken, 'link' => $link];
    }

    /**
     * @return array{status: string, link: ?SigningLink, document: ?SignableDocument}
     */
    public function resolve(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['status' => 'missing', 'link' => null, 'document' => null];
        }

        $link = $this->em->getRepository(SigningLink::class)->findOneBy([
            'tokenHash' => hash('sha256', $token),
        ]);
        if (!$link instanceof SigningLink) {
            return ['status' => 'missing', 'link' => null, 'document' => null];
        }

        $document = $this->em->find(SignableDocument::class, $link->documentId());
        if (!$document instanceof SignableDocument) {
            return ['status' => 'missing', 'link' => $link, 'document' => null];
        }

        if ($document->isFullySigned() || $link->usedAt() !== null) {
            return ['status' => 'signed', 'link' => $link, 'document' => $document];
        }
        if ($link->revokedAt() !== null) {
            return ['status' => 'revoked', 'link' => $link, 'document' => $document];
        }
        if ($link->expiresAt() <= new \DateTimeImmutable()) {
            return ['status' => 'expired', 'link' => $link, 'document' => $document];
        }

        return ['status' => 'ok', 'link' => $link, 'document' => $document];
    }

    public function signAsCounterparty(string $token, Request $request, string $pngDataUri): Signature
    {
        $resolved = $this->resolve($token);
        if ($resolved['status'] !== 'ok' || !$resolved['document'] instanceof SignableDocument || !$resolved['link'] instanceof SigningLink) {
            throw new \RuntimeException('esign.error.link_' . $resolved['status']);
        }

        $document = $resolved['document'];
        $link = $resolved['link'];

        $lockedHtml = (string) $document->lockedHtml();
        $lockedHash = (string) $document->lockedHash();
        if ($lockedHtml === '' || $lockedHash === '' || hash('sha256', $lockedHtml) !== $lockedHash) {
            throw new \RuntimeException('esign.error.integrity');
        }

        $path = $this->storage->writePngDataUri(
            $this->storage->documentDir($document->id()) . '/counterparty.png',
            $pngDataUri,
        );

        $signature = $this->upsertSignature(
            $document->id(),
            Signature::PARTY_COUNTERPARTY,
            $document->counterpartyName(),
            Signature::METHOD_DRAWN,
            $path,
        );
        $signature->setSignerEmail($link->email());
        $signature->setSignerRole('protistrana');
        $signature->setConsentVersion($this->consentVersion());
        $signature->setConsents($this->consentTexts());
        $signature->setIpAddress($request->getClientIp());
        $signature->setUserAgent((string) $request->headers->get('User-Agent', ''));
        $signature->setDocumentHash($lockedHash);
        $signature->touchSignedAt();

        $link->markUsed();
        $this->revokeOpenLinks($document->id(), $link->id());

        $executed = $this->renderer->affixCounterpartySignature($document, $signature);
        $document->markSigned($executed);

        $this->em->flush();

        return $signature;
    }

    /**
     * Obě strany podepíší na místě (tablet v dílně) — bez e-mailového odkazu.
     *
     * @return array{document: SignableDocument, provider: Signature, counterparty: Signature}
     */
    public function completeInPerson(
        SignableDocument $document,
        string $counterpartyPngDataUri,
        ?string $providerPngDataUri = null,
        ?Request $request = null,
    ): array {
        if ($document->isFullySigned()) {
            throw new \RuntimeException('esign.error.already_signed');
        }
        if (trim($document->counterpartyName()) === '') {
            throw new \RuntimeException('esign.error.counterparty_required');
        }
        if ($document->status() === SignableDocument::STATUS_CANCELLED) {
            $document->reopenDraft();
        }

        $provider = $this->applyProviderSignature($document, $providerPngDataUri);
        $this->lockDocument($document);

        $lockedHash = (string) $document->lockedHash();
        $path = $this->storage->writePngDataUri(
            $this->storage->documentDir($document->id()) . '/counterparty.png',
            $counterpartyPngDataUri,
        );

        $counterparty = $this->upsertSignature(
            $document->id(),
            Signature::PARTY_COUNTERPARTY,
            $document->counterpartyName(),
            Signature::METHOD_DRAWN,
            $path,
        );
        $counterparty->setSignerEmail($document->counterpartyEmail());
        $counterparty->setSignerRole('protistrana');
        $counterparty->setConsentVersion($this->consentVersion());
        $counterparty->setConsents($this->consentTexts());
        $counterparty->setIpAddress($request?->getClientIp());
        $counterparty->setUserAgent($request !== null ? (string) $request->headers->get('User-Agent', '') : null);
        $counterparty->setDocumentHash($lockedHash);
        $counterparty->touchSignedAt();

        $this->revokeOpenLinks($document->id());
        $executed = $this->renderer->affixCounterpartySignature($document, $counterparty);
        $document->markSigned($executed);
        $this->em->flush();

        return [
            'document' => $document,
            'provider' => $provider,
            'counterparty' => $counterparty,
        ];
    }

    public function cancel(SignableDocument $document): void
    {
        if ($document->isFullySigned()) {
            throw new \RuntimeException('esign.error.already_signed');
        }
        $this->revokeOpenLinks($document->id());
        $document->cancelSigning();
        $this->em->flush();
    }

    public function saveDefaultProviderSignature(string $pngDataUri): string
    {
        $path = 'esign/provider/default.png';
        $this->storage->writePngDataUri($path, $pngDataUri);
        $this->settings->set('esign.provider_signature_path', $path, \Gereq\Setting\SettingRecord::TYPE_STRING, 'esign');

        return $path;
    }

    public function findSignature(string $documentId, string $party): ?Signature
    {
        $item = $this->em->getRepository(Signature::class)->findOneBy([
            'documentId' => $documentId,
            'party' => $party,
        ]);

        return $item instanceof Signature ? $item : null;
    }

    public function signatureDataUri(?string $path): ?string
    {
        return $this->storage->dataUri($path);
    }

    /** @return list<Signature> */
    public function signaturesFor(string $documentId): array
    {
        /** @var list<Signature> $items */
        $items = $this->em->getRepository(Signature::class)->findBy(
            ['documentId' => $documentId],
            ['signedAt' => 'ASC'],
        );

        return $items;
    }

    /** @return list<SigningLink> */
    public function linksFor(string $documentId): array
    {
        /** @var list<SigningLink> $items */
        $items = $this->em->getRepository(SigningLink::class)->findBy(
            ['documentId' => $documentId],
            ['sentAt' => 'DESC'],
        );

        return $items;
    }

    public function unavailableMessage(string $status): string
    {
        return match ($status) {
            'signed' => 'esign.public.already_signed',
            'expired' => 'esign.public.expired',
            'revoked' => 'esign.public.revoked',
            default => 'esign.public.missing',
        };
    }

    private function lockDocument(SignableDocument $document): void
    {
        $html = $this->renderer->renderTokens($document->bodyHtml(), $document);
        if (!str_contains($html, '<!--esign:counterparty-signature-->')
            && !str_contains($document->bodyHtml(), '{{ counterparty_signature }}')
            && !str_contains($document->bodyHtml(), '{{counterparty_signature}}')
        ) {
            $html .= '<div class="esign-sig-block">' . $this->renderer->counterpartyPlaceholder() . '</div>';
        }
        $hash = hash('sha256', $html);
        $document->lock($html, $hash);

        $provider = $this->findSignature($document->id(), Signature::PARTY_PROVIDER);
        if ($provider !== null) {
            $provider->setDocumentHash($hash);
        }
    }

    private function revokeOpenLinks(string $documentId, ?string $exceptId = null): void
    {
        foreach ($this->linksFor($documentId) as $link) {
            if ($exceptId !== null && $link->id() === $exceptId) {
                continue;
            }
            if ($link->isUsable()) {
                $link->revoke();
            }
        }
    }

    private function upsertSignature(
        string $documentId,
        string $party,
        string $signerName,
        string $method,
        ?string $path,
    ): Signature {
        $existing = $this->findSignature($documentId, $party);
        if ($existing instanceof Signature) {
            $existing->setSignerName($signerName);
            $existing->setMethod($method);
            $existing->setSignaturePath($path);

            return $existing;
        }

        $signature = new Signature($documentId, $party, $signerName, $method, $path);
        $this->em->persist($signature);

        return $signature;
    }
}
