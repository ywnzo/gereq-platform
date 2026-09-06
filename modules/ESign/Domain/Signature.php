<?php

declare(strict_types=1);

namespace Modules\ESign\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'esign_signatures')]
#[ORM\UniqueConstraint(name: 'uniq_esign_signatures_document_party', columns: ['document_id', 'party'])]
#[ORM\Index(name: 'idx_esign_signatures_document', columns: ['document_id'])]
class Signature
{
    public const PARTY_PROVIDER = 'provider';
    public const PARTY_COUNTERPARTY = 'counterparty';

    public const METHOD_DRAWN = 'drawn';
    public const METHOD_IMAGE = 'image';
    public const METHOD_SEAL = 'seal';
    public const METHOD_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'document_id', length: 36)]
    private string $documentId;

    #[ORM\Column(length: 32)]
    private string $party;

    #[ORM\Column(name: 'signer_name', length: 190)]
    private string $signerName;

    #[ORM\Column(name: 'signer_email', length: 190, nullable: true)]
    private ?string $signerEmail = null;

    #[ORM\Column(name: 'signer_role', length: 64, nullable: true)]
    private ?string $signerRole = null;

    #[ORM\Column(length: 32)]
    private string $method;

    #[ORM\Column(name: 'signature_path', length: 255, nullable: true)]
    private ?string $signaturePath = null;

    #[ORM\Column(name: 'consent_version', length: 32, nullable: true)]
    private ?string $consentVersion = null;

    /** @var array<string, string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $consents = null;

    #[ORM\Column(name: 'ip_address', length: 64, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(name: 'user_agent', length: 512, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(name: 'document_hash', length: 64, nullable: true)]
    private ?string $documentHash = null;

    #[ORM\Column(name: 'signed_at')]
    private \DateTimeImmutable $signedAt;

    /**
     * @param array<string, string>|null $consents
     */
    public function __construct(
        string $documentId,
        string $party,
        string $signerName,
        string $method,
        ?string $signaturePath = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->documentId = $documentId;
        $this->party = $party;
        $this->signerName = $signerName;
        $this->method = $method;
        $this->signaturePath = $signaturePath;
        $this->signedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function documentId(): string
    {
        return $this->documentId;
    }

    public function party(): string
    {
        return $this->party;
    }

    public function signerName(): string
    {
        return $this->signerName;
    }

    public function signerEmail(): ?string
    {
        return $this->signerEmail;
    }

    public function signerRole(): ?string
    {
        return $this->signerRole;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function signaturePath(): ?string
    {
        return $this->signaturePath;
    }

    public function consentVersion(): ?string
    {
        return $this->consentVersion;
    }

    /** @return array<string, string>|null */
    public function consents(): ?array
    {
        return $this->consents;
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function documentHash(): ?string
    {
        return $this->documentHash;
    }

    public function signedAt(): \DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function setSignerEmail(?string $email): void
    {
        $this->signerEmail = $email !== null && $email !== '' ? strtolower(trim($email)) : null;
    }

    public function setSignerRole(?string $role): void
    {
        $this->signerRole = $role;
    }

    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    public function setSignaturePath(?string $path): void
    {
        $this->signaturePath = $path;
    }

    public function setConsentVersion(?string $version): void
    {
        $this->consentVersion = $version;
    }

    /** @param array<string, string>|null $consents */
    public function setConsents(?array $consents): void
    {
        $this->consents = $consents;
    }

    public function setIpAddress(?string $ip): void
    {
        $this->ipAddress = $ip;
    }

    public function setUserAgent(?string $ua): void
    {
        if ($ua === null) {
            $this->userAgent = null;

            return;
        }
        $this->userAgent = mb_substr($ua, 0, 512);
    }

    public function setDocumentHash(?string $hash): void
    {
        $this->documentHash = $hash;
    }

    public function touchSignedAt(): void
    {
        $this->signedAt = new \DateTimeImmutable();
    }

    public function setSignerName(string $name): void
    {
        $this->signerName = $name;
    }
}
