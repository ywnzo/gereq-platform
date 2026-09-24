<?php

declare(strict_types=1);

namespace Modules\Gereq\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Gereq\Application\Ticketing\TicketService;
use Modules\Gereq\Domain\SecurityEvent;
use Modules\Gereq\Domain\SecuritySite;
use Modules\Gereq\Domain\Ticket;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class GereqApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly TicketService $tickets,
        private readonly Authenticator $auth,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function tickets(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));
        /** @var list<Ticket> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('t')
            ->from(Ticket::class, 't')
            ->orderBy('t.lastMessageAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ApiResponse::collection(array_map(
            fn (Ticket $t): array => $this->tickets->ticketPayload($t) ?? [],
            $rows,
        ));
    }

    public function ticket(string $id): JsonResponse
    {
        $ticket = $this->em->find(Ticket::class, $id);
        if (!$ticket instanceof Ticket) {
            return ApiResponse::error('not_found', 'Ticket not found.', status: 404);
        }
        $messages = $this->tickets->messagesForTicket($ticket->id());

        return ApiResponse::data([
            'ticket' => $this->tickets->ticketPayload($ticket),
            'messages' => $this->tickets->messagePayloads($messages),
        ]);
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $ticket = $this->em->find(Ticket::class, $id);
        if (!$ticket instanceof Ticket) {
            return ApiResponse::error('not_found', 'Ticket not found.', status: 404);
        }
        $user = $this->auth->user();
        if ($user === null) {
            return ApiResponse::error('unauthorized', 'Authentication required.', status: 401);
        }
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }
        $body = trim((string) ($payload['body'] ?? ''));
        if ($body === '') {
            return ApiResponse::error('validation', 'body is required.', ['body' => 'required'], 422);
        }
        $internal = (bool) ($payload['internal'] ?? false);
        $msg = $this->tickets->addAgentMessage($ticket, $body, $user->id(), $internal);

        return ApiResponse::data($this->tickets->messagePayloads([$msg])[0]);
    }

    public function securitySites(Request $request): JsonResponse
    {
        /** @var list<SecuritySite> $sites */
        $sites = $this->em->getRepository(SecuritySite::class)->findBy([], ['name' => 'ASC'], 100);
        $items = [];
        foreach ($sites as $site) {
            $items[] = [
                'id' => $site->id(),
                'name' => $site->name(),
                'public_key' => $site->publicKey(),
                'is_active' => $site->isActive(),
                'form_enabled' => $site->formEnabled(),
                'monitor_enabled' => $site->monitorEnabled(),
                'protection_mode' => $site->protectionMode(),
            ];
        }

        return ApiResponse::collection($items);
    }

    public function securityEvents(Request $request): JsonResponse
    {
        $siteId = trim((string) $request->query->get('site_id', ''));
        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(SecurityEvent::class, 'e')
            ->orderBy('e.occurredAt', 'DESC')
            ->setMaxResults(100);
        if ($siteId !== '') {
            $qb->andWhere('e.securitySiteId = :sid')->setParameter('sid', $siteId);
        }
        /** @var list<SecurityEvent> $rows */
        $rows = $qb->getQuery()->getResult();
        $items = [];
        foreach ($rows as $e) {
            $items[] = [
                'id' => $e->id(),
                'security_site_id' => $e->securitySiteId(),
                'occurred_at' => $e->occurredAt()->format(\DateTimeInterface::ATOM),
                'visitor_hash' => $e->visitorHash(),
                'path' => $e->path(),
                'score' => $e->score(),
                'action' => $e->action(),
                'reasons' => $e->reasons(),
            ];
        }

        return ApiResponse::collection($items);
    }
}
