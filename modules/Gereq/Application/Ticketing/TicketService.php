<?php

declare(strict_types=1);

namespace Modules\Gereq\Application\Ticketing;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Config\Repository;
use Modules\Gereq\Application\Support\HttpOrigin;
use Modules\Gereq\Domain\Ticket;
use Modules\Gereq\Domain\TicketMessage;
use Modules\Gereq\Domain\TicketSite;
use Symfony\Component\HttpFoundation\Request;
use Gereq\Http\Exception\HttpException;

final class TicketService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssignmentService $assignment,
        private readonly Repository $config,
    ) {
    }

    public function findActiveSite(string $key): TicketSite
    {
        /** @var TicketSite|null $site */
        $site = $this->em->createQueryBuilder()
            ->select('s')
            ->from(TicketSite::class, 's')
            ->where('s.isActive = true')
            ->andWhere('s.publicKey = :key OR s.testPublicKey = :key')
            ->setParameter('key', $key)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$site instanceof TicketSite) {
            throw new HttpException(404, 'Ticket site not found.');
        }

        $test = is_string($site->testPublicKey())
            && $site->testPublicKey() !== ''
            && hash_equals((string) $site->testPublicKey(), $key);
        $site->markCredentialMode($test);

        return $site;
    }

    public function assertOrigin(TicketSite $site, ?string $origin, ?Request $request = null): void
    {
        $claimed = HttpOrigin::normalize((string) ($origin ?? ''));
        $header = $this->requestOrigin($request);
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $appOrigin = HttpOrigin::normalize($appUrl);

        if ($claimed === '' || !$site->allowsOrigin($claimed)) {
            throw new HttpException(403, 'Origin není povolený pro tento widget.');
        }

        if ($header !== null && $header !== '') {
            if ($appOrigin !== '' && $header === $appOrigin) {
                return;
            }
            if ($header !== $claimed || !$site->allowsOrigin($header)) {
                throw new HttpException(403, 'Origin hlavičky neodpovídá povolenému webu.');
            }

            return;
        }

        $env = (string) $this->config->get('app.env', 'production');
        if ($env === 'production') {
            throw new HttpException(403, 'Chybí Origin hlavička.');
        }
    }

    public function requestOrigin(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }
        $header = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        if (!is_string($header) || $header === '') {
            return null;
        }

        return HttpOrigin::normalize($header) ?: null;
    }

    /**
     * @return array{visitor_token: string, ticket: ?Ticket, messages: list<TicketMessage>, history: list<Ticket>}
     */
    public function session(TicketSite $site, ?string $visitorToken, ?string $pageUrl = null, ?string $email = null): array
    {
        $ticket = null;
        if (is_string($visitorToken) && preg_match('/^[a-f0-9]{64}$/', $visitorToken) === 1) {
            $ticket = $this->em->getRepository(Ticket::class)->findOneBy(['visitorToken' => $visitorToken]);
        }
        if (!$ticket instanceof Ticket) {
            $visitorToken = bin2hex(random_bytes(32));
            $ticket = new Ticket($this->nextNumber(), $visitorToken, $site->id());
            if ($pageUrl) {
                $ticket->setSourceUrl($pageUrl);
            }
            if ($email) {
                $ticket->setVisitorEmail($email);
            }
            $this->assignment->assign($ticket, $site);
            $this->em->persist($ticket);
            $this->em->flush();
        } else {
            $ticket->touchVisitorSeen();
            if ($pageUrl) {
                $ticket->setSourceUrl($pageUrl);
            }
            if ($email && !$ticket->visitorEmail()) {
                $ticket->setVisitorEmail($email);
            }
            $this->em->flush();
        }

        $messages = $this->messagesForTicket($ticket->id(), publicOnly: true);
        $history = [];
        if ($ticket->visitorEmail()) {
            $history = $this->em->createQueryBuilder()
                ->select('t')
                ->from(Ticket::class, 't')
                ->where('t.visitorEmail = :email')
                ->andWhere('t.ticketSiteId = :site')
                ->setParameter('email', $ticket->visitorEmail())
                ->setParameter('site', $site->id())
                ->orderBy('t.lastMessageAt', 'DESC')
                ->setMaxResults(20)
                ->getQuery()
                ->getResult();
        }

        return [
            'visitor_token' => $ticket->visitorToken(),
            'ticket' => $ticket,
            'messages' => $messages,
            'history' => $history,
        ];
    }

    public function addVisitorMessage(Ticket $ticket, string $body, ?string $name = null, ?string $email = null): TicketMessage
    {
        if ($name) {
            $ticket->setVisitorName($name);
        }
        if ($email) {
            $ticket->setVisitorEmail($email);
        }
        if (in_array($ticket->status(), ['resolved', 'closed'], true)) {
            $ticket->setStatus('open');
        }
        $msg = new TicketMessage($ticket->id(), 'visitor', trim($body));
        $ticket->touchLastMessage();
        $ticket->setUnread(true);
        if (!$ticket->subject()) {
            $ticket->setSubject(mb_substr(trim($body), 0, 80));
        }
        $this->em->persist($msg);
        $this->em->flush();

        return $msg;
    }

    public function addAgentMessage(Ticket $ticket, string $body, string $userId, bool $internal = false): TicketMessage
    {
        $direction = $internal ? 'internal' : 'agent';
        $msg = new TicketMessage($ticket->id(), $direction, trim($body), $userId);
        $ticket->touchLastMessage();
        if (!$internal) {
            $ticket->setUnread(false);
            if ($ticket->status() === 'open') {
                $ticket->setStatus('pending');
            }
        }
        $this->em->persist($msg);
        $this->em->flush();

        return $msg;
    }

    /** @return list<TicketMessage> */
    public function messagesForTicket(string $ticketId, bool $publicOnly = false): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(TicketMessage::class, 'm')
            ->where('m.ticketId = :id')
            ->setParameter('id', $ticketId)
            ->orderBy('m.createdAt', 'ASC');
        if ($publicOnly) {
            $qb->andWhere('m.isInternal = false');
        }

        /** @var list<TicketMessage> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function ticketPayload(?Ticket $ticket): ?array
    {
        if (!$ticket instanceof Ticket) {
            return null;
        }

        return [
            'id' => $ticket->id(),
            'number' => $ticket->number(),
            'status' => $ticket->status(),
            'subject' => $ticket->subject(),
            'visitor_name' => $ticket->visitorName(),
            'visitor_email' => $ticket->visitorEmail(),
            'priority' => $ticket->priority(),
            'is_unread' => $ticket->isUnread(),
            'rating' => $ticket->rating(),
            'last_message_at' => $ticket->lastMessageAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $ticket->createdAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param list<TicketMessage> $messages
     * @return list<array<string, mixed>>
     */
    public function messagePayloads(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $out[] = [
                'id' => $m->id(),
                'direction' => $m->direction(),
                'body' => $m->body(),
                'is_internal' => $m->isInternal(),
                'is_system' => $m->isSystem(),
                'attachments' => $m->attachments(),
                'user_id' => $m->userId(),
                'created_at' => $m->createdAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $out;
    }

    /**
     * @param list<Ticket> $tickets
     * @return list<array<string, mixed>>
     */
    public function historyPayloads(array $tickets): array
    {
        $out = [];
        foreach ($tickets as $t) {
            $payload = $this->ticketPayload($t);
            if ($payload !== null) {
                $out[] = $payload;
            }
        }

        return $out;
    }

    public function nextNumber(): string
    {
        $year = (new \DateTimeImmutable())->format('Y');
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Ticket::class, 't')
            ->where('t.number LIKE :prefix')
            ->setParameter('prefix', 'SF-' . $year . '-%')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('SF-%s-%04d', $year, $count + 1);
    }

    /** @return array<string, string> */
    public function corsHeaders(string $origin): array
    {
        $normalized = HttpOrigin::normalize($origin);
        if ($normalized === '') {
            return [];
        }

        return [
            'Access-Control-Allow-Origin' => $normalized,
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept, Origin',
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Origin',
        ];
    }
}
