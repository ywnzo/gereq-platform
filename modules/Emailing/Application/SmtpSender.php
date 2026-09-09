<?php

declare(strict_types=1);

namespace Modules\Emailing\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Emailing\Domain\MailAccount;
use Modules\Emailing\Domain\OutboxMessage;
use Modules\Emailing\Application\Jobs\SendOutboxJob;
use Gereq\Queue\Queue;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class SmtpSender
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CredentialVault $vault,
        private readonly Queue $queue,
    ) {
    }

    /**
     * @param list<string>|string $to
     * @param list<string>|string|null $cc
     */
    public function queue(
        MailAccount $account,
        array|string $to,
        string $subject,
        ?string $html = null,
        ?string $text = null,
        array|string|null $cc = null,
        ?string $inReplyTo = null,
        ?string $references = null,
    ): OutboxMessage {
        $outbox = new OutboxMessage($account->id(), $this->joinAddresses($to), $subject);
        $outbox->setBody($html, $text);
        if ($cc !== null) {
            $outbox->setCc($this->joinAddresses($cc));
        }
        $outbox->setThreadHeaders(
            MailThreadResolver::normalizeId($inReplyTo),
            MailThreadResolver::buildReferencesHeader($references, $inReplyTo),
        );
        $this->em->persist($outbox);
        $this->em->flush();
        $this->queue->dispatch(new SendOutboxJob($outbox->id()));

        return $outbox;
    }

    public function sendNow(OutboxMessage $outbox): void
    {
        $account = $this->em->find(MailAccount::class, $outbox->accountId());
        if (!$account instanceof MailAccount) {
            $outbox->markFailed('Účet neexistuje.');
            $this->em->flush();

            return;
        }

        $outbox->markSending();
        $this->em->flush();

        try {
            $mailer = $this->mailerFor($account);
            $email = (new Email())
                ->from(new Address($account->email(), $account->name()))
                ->subject($outbox->subject());

            foreach ($this->splitAddresses($outbox->toAddresses()) as $addr) {
                $email->addTo($addr);
            }
            foreach ($this->splitAddresses((string) $outbox->ccAddresses()) as $addr) {
                $email->addCc($addr);
            }

            if ($outbox->bodyHtml()) {
                $email->html($outbox->bodyHtml());
            }
            if ($outbox->bodyText()) {
                $email->text($outbox->bodyText());
            } elseif ($outbox->bodyHtml()) {
                $email->text(strip_tags($outbox->bodyHtml() ?? ''));
            }

            $replyTo = MailThreadResolver::normalizeId($outbox->inReplyTo());
            if ($replyTo !== null) {
                $email->getHeaders()->addIdHeader('In-Reply-To', $replyTo);
            }
            $references = $outbox->referencesRaw();
            if ($references !== null && $references !== '') {
                $email->getHeaders()->addTextHeader('References', $references);
            }

            $mailer->send($email);
            $outbox->markSent();
            $this->em->flush();
        } catch (\Throwable $e) {
            $outbox->markFailed($e->getMessage());
            $this->em->flush();
            throw $e;
        }
    }

    public function mailerFor(MailAccount $account): Mailer
    {
        return new Mailer(Transport::fromDsn($this->dsnFor($account)));
    }

    public function dsnFor(MailAccount $account): string
    {
        $password = $this->vault->decrypt($account->smtpPasswordEnc());
        $user = rawurlencode($account->smtpUsername() !== '' ? $account->smtpUsername() : $account->email());
        $pass = rawurlencode($password);
        $host = MailHostNormalizer::normalize($account->smtpHost());
        $port = $account->smtpPort();
        $enc = $account->smtpEncryption();

        $scheme = match ($enc) {
            'ssl' => 'smtps',
            default => 'smtp',
        };

        $query = '';
        if ($enc === 'tls') {
            $query = '?encryption=tls';
        } elseif ($enc === 'none') {
            $query = '?encryption=null';
        }

        return sprintf('%s://%s:%s@%s:%d%s', $scheme, $user, $pass, $host, $port, $query);
    }

    public function testConnection(MailAccount $account): void
    {
        $transport = Transport::fromDsn($this->dsnFor($account));
        // Opening transport validates DSN; actual SMTP handshake happens on send.
        unset($transport);
        if ($account->smtpHost() === '') {
            throw new \InvalidArgumentException('SMTP host není nastaven.');
        }
    }

    /** @param list<string>|string $addresses */
    private function joinAddresses(array|string $addresses): string
    {
        if (is_string($addresses)) {
            return trim($addresses);
        }

        return implode(', ', array_values(array_filter(array_map('trim', $addresses))));
    }

    /** @return list<string> */
    private function splitAddresses(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[,;]+/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }
}
