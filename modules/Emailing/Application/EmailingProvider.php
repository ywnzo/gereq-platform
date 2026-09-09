<?php

declare(strict_types=1);

namespace Modules\Emailing\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Emailing\Domain\MailAccount;
use Modules\Emailing\Domain\OutboxMessage;

final class EmailingProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SmtpSender $sender,
    ) {
    }

    /**
     * @param list<string>|string $to
     * @param list<string>|string|null $cc
     * @param list<array{path?: string, name?: string}> $attachments ignored in v1 (queued HTML/text only)
     */
    public function send(
        array|string $to,
        string $subject,
        ?string $html = null,
        ?string $text = null,
        ?string $accountId = null,
        array|string|null $cc = null,
        array $attachments = [],
    ): OutboxMessage {
        unset($attachments);
        $account = $this->resolveAccount($accountId);
        if ($account === null) {
            throw new \RuntimeException('Žádný aktivní e-mailový účet. Nastavte Emailing účet.');
        }

        return $this->sender->queue($account, $to, $subject, $html, $text, $cc);
    }

    public function defaultAccount(): ?MailAccount
    {
        return $this->resolveAccount(null);
    }

    private function resolveAccount(?string $accountId): ?MailAccount
    {
        if ($accountId !== null && $accountId !== '') {
            $account = $this->em->find(MailAccount::class, $accountId);

            return $account instanceof MailAccount && $account->isActive() ? $account : null;
        }

        $default = $this->em->getRepository(MailAccount::class)->findOneBy(['isDefault' => true, 'isActive' => true]);
        if ($default instanceof MailAccount) {
            return $default;
        }

        $any = $this->em->getRepository(MailAccount::class)->findOneBy(['isActive' => true], ['createdAt' => 'ASC']);

        return $any instanceof MailAccount ? $any : null;
    }
}
