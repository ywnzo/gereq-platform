<?php

declare(strict_types=1);

namespace Modules\Gereq\Domain;

use Doctrine\ORM\Mapping as ORM;
use Modules\Gereq\Application\Support\GereqCredential;
use Modules\Gereq\Application\Support\HttpOrigin;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'gereq_ticket_sites')]
#[ORM\UniqueConstraint(name: 'uniq_gereq_ticket_public_key', columns: ['public_key'])]
#[ORM\UniqueConstraint(name: 'uniq_gereq_ticket_test_public_key', columns: ['test_public_key'])]
class TicketSite
{
    public const STRATEGIES = ['least_busy', 'specific', 'unassigned'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(name: 'public_key', length: 64)]
    private string $publicKey;

    #[ORM\Column(name: 'test_public_key', length: 64, nullable: true)]
    private ?string $testPublicKey = null;

    /** @var list<string> */
    #[ORM\Column(name: 'allowed_origins', type: 'json')]
    private array $allowedOrigins = [];

    #[ORM\Column(name: 'welcome_message', type: 'text', nullable: true)]
    private ?string $welcomeMessage = null;

    #[ORM\Column(name: 'offline_message', type: 'text', nullable: true)]
    private ?string $offlineMessage = null;

    #[ORM\Column(name: 'primary_color', length: 16, options: ['default' => '#0A1F38'])]
    private string $primaryColor = '#0A1F38';

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'agents_online', options: ['default' => true])]
    private bool $agentsOnline = true;

    #[ORM\Column(name: 'show_teaser', options: ['default' => false])]
    private bool $showTeaser = false;

    #[ORM\Column(name: 'assignment_strategy', length: 32, options: ['default' => 'unassigned'])]
    private string $assignmentStrategy = 'unassigned';

    #[ORM\Column(name: 'default_assignee_id', length: 36, nullable: true)]
    private ?string $defaultAssigneeId = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    private bool $usingTestKey = false;

    public function __construct(string $name)
    {
        $this->id = Uuid::uuid4()->toString();
        $this->name = $name;
        $this->publicKey = GereqCredential::publicKey();
        $this->welcomeMessage = 'Dobrý den, jak vám můžeme pomoci?';
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string { return $this->id; }
    public function name(): string { return $this->name; }
    public function publicKey(): string { return $this->publicKey; }
    public function testPublicKey(): ?string { return $this->testPublicKey; }
    /** @return list<string> */
    public function allowedOrigins(): array { return $this->allowedOrigins; }
    public function welcomeMessage(): ?string { return $this->welcomeMessage; }
    public function offlineMessage(): ?string { return $this->offlineMessage; }
    public function primaryColor(): string { return $this->primaryColor; }
    public function isActive(): bool { return $this->isActive; }
    public function agentsOnline(): bool { return $this->agentsOnline; }
    public function showTeaser(): bool { return $this->showTeaser; }
    public function assignmentStrategy(): string { return $this->assignmentStrategy; }
    public function defaultAssigneeId(): ?string { return $this->defaultAssigneeId; }
    public function usingTestKey(): bool { return $this->usingTestKey; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function markCredentialMode(bool $test): void { $this->usingTestKey = $test; }

    public function setName(string $name): void { $this->name = trim($name); $this->touch(); }

    /** @param list<string>|string $origins */
    public function setAllowedOrigins(array|string $origins): void
    {
        if (is_string($origins)) {
            $origins = preg_split('/[\s,]+/', $origins) ?: [];
        }
        $normalized = [];
        foreach ($origins as $o) {
            if (!is_string($o)) continue;
            $n = HttpOrigin::normalize($o);
            if ($n !== '' && !in_array($n, $normalized, true)) $normalized[] = $n;
        }
        $this->allowedOrigins = $normalized;
        $this->touch();
    }

    public function setWelcomeMessage(?string $m): void { $this->welcomeMessage = $m; $this->touch(); }
    public function setOfflineMessage(?string $m): void { $this->offlineMessage = $m; $this->touch(); }
    public function setPrimaryColor(string $c): void { $this->primaryColor = strtoupper(trim($c)) ?: '#0A1F38'; $this->touch(); }
    public function setActive(bool $v): void { $this->isActive = $v; $this->touch(); }
    public function setAgentsOnline(bool $v): void { $this->agentsOnline = $v; $this->touch(); }
    public function setShowTeaser(bool $v): void { $this->showTeaser = $v; $this->touch(); }
    public function setAssignmentStrategy(string $s): void
    {
        $this->assignmentStrategy = in_array($s, self::STRATEGIES, true) ? $s : 'unassigned';
        $this->touch();
    }
    public function setDefaultAssigneeId(?string $id): void { $this->defaultAssigneeId = $id; $this->touch(); }

    public function issueTestKey(): string
    {
        $this->testPublicKey = GereqCredential::publicKey(true);
        $this->touch();
        return $this->testPublicKey;
    }

    public function allowsOrigin(string $origin): bool
    {
        $normalized = HttpOrigin::normalize($origin);
        if ($normalized === '') return false;
        if ($this->usingTestKey && HttpOrigin::isLoopback($normalized)) return true;
        return in_array($normalized, $this->allowedOrigins, true);
    }

    public function embedSnippet(string $baseUrl): string
    {
        $src = rtrim($baseUrl, '/') . '/js/gereq-tickets.js';
        $teaser = $this->showTeaser ? ' data-teaser="1"' : '';
        return '<script src="' . htmlspecialchars($src, ENT_QUOTES) . '" data-key="' . htmlspecialchars($this->publicKey, ENT_QUOTES) . '"' . $teaser . ' defer></script>';
    }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
