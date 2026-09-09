<?php

declare(strict_types=1);

namespace Modules\Emailing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'emailing_accounts')]
class MailAccount
{
    public const PROTOCOLS = ['imap', 'pop3'];
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(length: 190)]
    private string $email;

    #[ORM\Column(name: 'smtp_host', length: 190)]
    private string $smtpHost = '';

    #[ORM\Column(name: 'smtp_port')]
    private int $smtpPort = 587;

    #[ORM\Column(name: 'smtp_encryption', length: 16)]
    private string $smtpEncryption = 'tls';

    #[ORM\Column(name: 'smtp_username', length: 190)]
    private string $smtpUsername = '';

    #[ORM\Column(name: 'smtp_password_enc', type: 'text')]
    private string $smtpPasswordEnc = '';

    #[ORM\Column(name: 'incoming_protocol', length: 16)]
    private string $incomingProtocol = 'imap';

    #[ORM\Column(name: 'incoming_host', length: 190)]
    private string $incomingHost = '';

    #[ORM\Column(name: 'incoming_port')]
    private int $incomingPort = 993;

    #[ORM\Column(name: 'incoming_encryption', length: 16)]
    private string $incomingEncryption = 'ssl';

    #[ORM\Column(name: 'incoming_username', length: 190)]
    private string $incomingUsername = '';

    #[ORM\Column(name: 'incoming_password_enc', type: 'text')]
    private string $incomingPasswordEnc = '';

    #[ORM\Column(name: 'is_default')]
    private bool $isDefault = false;

    #[ORM\Column(name: 'is_active')]
    private bool $isActive = true;

    #[ORM\Column(name: 'signature_html', type: 'text', nullable: true)]
    private ?string $signatureHtml = null;

    #[ORM\Column(name: 'last_synced_at', nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $email)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->email = $email;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function smtpHost(): string
    {
        return $this->smtpHost;
    }

    public function smtpPort(): int
    {
        return $this->smtpPort;
    }

    public function smtpEncryption(): string
    {
        return $this->smtpEncryption;
    }

    public function smtpUsername(): string
    {
        return $this->smtpUsername;
    }

    public function smtpPasswordEnc(): string
    {
        return $this->smtpPasswordEnc;
    }

    public function incomingProtocol(): string
    {
        return $this->incomingProtocol;
    }

    public function incomingHost(): string
    {
        return $this->incomingHost;
    }

    public function incomingPort(): int
    {
        return $this->incomingPort;
    }

    public function incomingEncryption(): string
    {
        return $this->incomingEncryption;
    }

    public function incomingUsername(): string
    {
        return $this->incomingUsername;
    }

    public function incomingPasswordEnc(): string
    {
        return $this->incomingPasswordEnc;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function signatureHtml(): ?string
    {
        return $this->signatureHtml;
    }

    public function lastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
        $this->touch();
    }

    public function setSmtp(string $host, int $port, string $encryption, string $username, ?string $passwordEnc = null): void
    {
        $this->smtpHost = $host;
        $this->smtpPort = $port;
        $this->smtpEncryption = $encryption;
        $this->smtpUsername = $username;
        if ($passwordEnc !== null && $passwordEnc !== '') {
            $this->smtpPasswordEnc = $passwordEnc;
        }
        $this->touch();
    }

    public function setIncoming(
        string $protocol,
        string $host,
        int $port,
        string $encryption,
        string $username,
        ?string $passwordEnc = null,
    ): void {
        $this->incomingProtocol = $protocol;
        $this->incomingHost = $host;
        $this->incomingPort = $port;
        $this->incomingEncryption = $encryption;
        $this->incomingUsername = $username;
        if ($passwordEnc !== null && $passwordEnc !== '') {
            $this->incomingPasswordEnc = $passwordEnc;
        }
        $this->touch();
    }

    public function setDefault(bool $default): void
    {
        $this->isDefault = $default;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->isActive = $active;
        $this->touch();
    }

    public function setSignatureHtml(?string $html): void
    {
        $html = $html !== null ? trim($html) : null;
        $this->signatureHtml = $html !== '' ? $html : null;
        $this->touch();
    }

    public function markSynced(): void
    {
        $this->lastSyncedAt = new \DateTimeImmutable();
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
