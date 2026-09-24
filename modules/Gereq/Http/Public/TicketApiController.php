<?php

declare(strict_types=1);

namespace Modules\Gereq\Http\Public;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Security\RateLimiter;
use Modules\Gereq\Application\Ticketing\TicketService;
use Modules\Gereq\Application\Ticketing\TicketWidgetSandbox;
use Modules\Gereq\Domain\Ticket;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Gereq\Http\Exception\HttpException;
use Twig\Environment;

final class TicketApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly TicketService $tickets,
        private readonly TicketWidgetSandbox $sandbox,
        private readonly EntityManagerInterface $em,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function options(Request $request): Response
    {
        $origin = (string) ($request->query->get('origin') ?? $request->headers->get('Origin') ?? '');
        $response = new Response('', 204);
        if ($origin !== '') {
            $response->headers->add($this->tickets->corsHeaders($origin));
        }

        return $response;
    }

    public function session(Request $request): Response
    {
        return $this->handleSession($request, includeWelcome: true);
    }

    public function messages(Request $request): Response
    {
        if ($request->getMethod() === 'POST') {
            return $this->storeMessage($request);
        }

        return $this->handleSession($request, includeWelcome: false);
    }

    public function storeMessage(Request $request): Response
    {
        if (!$this->limiter->hit('gereq-ticket-msg:' . (string) $request->getClientIp(), 60, 60)) {
            return $this->rawError('Too many requests.', 429);
        }
        try {
            $data = $this->jsonBody($request);
            $site = $this->tickets->findActiveSite((string) ($data['key'] ?? ''));
            $this->tickets->assertOrigin($site, (string) ($data['origin'] ?? ''), $request);
            if ($site->usingTestKey()) {
                return $this->rawJson([
                    'ok' => true,
                    'message' => [
                        'id' => 'test-msg',
                        'direction' => 'visitor',
                        'body' => (string) ($data['body'] ?? ''),
                        'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    ],
                    'key_mode' => 'test',
                ]);
            }
            $session = $this->tickets->session(
                $site,
                isset($data['visitor_token']) ? (string) $data['visitor_token'] : null,
                isset($data['page_url']) ? (string) $data['page_url'] : null,
                isset($data['email']) ? (string) $data['email'] : null,
            );
            $ticket = $session['ticket'];
            if (!$ticket instanceof Ticket) {
                throw new HttpException(500, 'Ticket missing.');
            }
            $body = trim((string) ($data['body'] ?? ''));
            if ($body === '') {
                return $this->rawError('Message body required.', 422);
            }
            $msg = $this->tickets->addVisitorMessage(
                $ticket,
                $body,
                isset($data['name']) ? (string) $data['name'] : null,
                isset($data['email']) ? (string) $data['email'] : null,
            );
            $response = $this->rawJson([
                'ok' => true,
                'visitor_token' => $ticket->visitorToken(),
                'ticket' => $this->tickets->ticketPayload($ticket),
                'message' => $this->tickets->messagePayloads([$msg])[0],
                'key_mode' => 'live',
            ]);
            $response->headers->add($this->tickets->corsHeaders((string) ($data['origin'] ?? '')));

            return $response;
        } catch (HttpException $e) {
            return $this->rawError($e->getMessage(), $e->status());
        }
    }

    public function typing(Request $request): Response
    {
        return $this->rawJson(['ok' => true]);
    }

    public function presence(Request $request): Response
    {
        try {
            $data = $this->jsonBody($request);
            $site = $this->tickets->findActiveSite((string) ($data['key'] ?? ''));
            $this->tickets->assertOrigin($site, (string) ($data['origin'] ?? ''), $request);
            if (!$site->usingTestKey()) {
                $token = (string) ($data['visitor_token'] ?? '');
                $ticket = $this->em->getRepository(Ticket::class)->findOneBy(['visitorToken' => $token]);
                if ($ticket instanceof Ticket) {
                    $ticket->touchVisitorSeen();
                    $this->em->flush();
                }
            }

            return $this->rawJson(['ok' => true]);
        } catch (HttpException $e) {
            return $this->rawError($e->getMessage(), $e->status());
        }
    }

    public function rate(Request $request): Response
    {
        try {
            $data = $this->jsonBody($request);
            $site = $this->tickets->findActiveSite((string) ($data['key'] ?? ''));
            $this->tickets->assertOrigin($site, (string) ($data['origin'] ?? ''), $request);
            if ($site->usingTestKey()) {
                return $this->rawJson(['ok' => true, 'key_mode' => 'test']);
            }
            $ticket = $this->em->getRepository(Ticket::class)->findOneBy([
                'visitorToken' => (string) ($data['visitor_token'] ?? ''),
            ]);
            if (!$ticket instanceof Ticket) {
                throw new HttpException(404, 'Ticket not found.');
            }
            $rating = isset($data['rating']) ? (int) $data['rating'] : null;
            $comment = isset($data['comment']) ? (string) $data['comment'] : null;
            $ticket->setRating($rating, $comment);
            $this->em->flush();

            return $this->rawJson(['ok' => true, 'key_mode' => 'live']);
        } catch (HttpException $e) {
            return $this->rawError($e->getMessage(), $e->status());
        }
    }

    public function resolve(Request $request): Response
    {
        try {
            $data = $this->jsonBody($request);
            $site = $this->tickets->findActiveSite((string) ($data['key'] ?? ''));
            $this->tickets->assertOrigin($site, (string) ($data['origin'] ?? ''), $request);
            if ($site->usingTestKey()) {
                return $this->rawJson(['ok' => true, 'key_mode' => 'test']);
            }
            $ticket = $this->em->getRepository(Ticket::class)->findOneBy([
                'visitorToken' => (string) ($data['visitor_token'] ?? ''),
            ]);
            if (!$ticket instanceof Ticket) {
                throw new HttpException(404, 'Ticket not found.');
            }
            $ticket->setStatus('resolved');
            $this->em->flush();

            return $this->rawJson(['ok' => true, 'ticket' => $this->tickets->ticketPayload($ticket), 'key_mode' => 'live']);
        } catch (HttpException $e) {
            return $this->rawError($e->getMessage(), $e->status());
        }
    }

    public function history(Request $request): Response
    {
        try {
            $data = array_merge($request->query->all(), $this->jsonBody($request));
            $site = $this->tickets->findActiveSite((string) ($data['key'] ?? ''));
            $this->tickets->assertOrigin($site, (string) ($data['origin'] ?? ''), $request);
            if ($site->usingTestKey()) {
                return $this->rawJson($this->sandbox->history($site, $data));
            }
            $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
            /** @var list<\Modules\Gereq\Domain\Ticket> $rows */
            $rows = $this->em->createQueryBuilder()
                ->select('t')
                ->from(Ticket::class, 't')
                ->where('t.visitorEmail = :email')
                ->andWhere('t.ticketSiteId = :site')
                ->setParameter('email', $email)
                ->setParameter('site', $site->id())
                ->orderBy('t.lastMessageAt', 'DESC')
                ->setMaxResults(20)
                ->getQuery()
                ->getResult();

            return $this->rawJson([
                'email' => $email,
                'history' => $this->tickets->historyPayloads($rows),
                'key_mode' => 'live',
            ]);
        } catch (HttpException $e) {
            return $this->rawError($e->getMessage(), $e->status());
        }
    }

    private function handleSession(Request $request, bool $includeWelcome): Response
    {
        if (!$this->limiter->hit('gereq-ticket-session:' . (string) $request->getClientIp(), 90, 60)) {
            return $this->rawError('Too many requests.', 429);
        }
        try {
            $data = array_merge($request->query->all(), $this->jsonBody($request));
            $site = $this->tickets->findActiveSite((string) ($data['key'] ?? ''));
            $this->tickets->assertOrigin($site, (string) ($data['origin'] ?? ''), $request);
            if ($site->usingTestKey()) {
                $payload = $this->sandbox->session($site, $data);
                $response = $this->rawJson($payload);
                $response->headers->add($this->tickets->corsHeaders((string) ($data['origin'] ?? '')));

                return $response;
            }
            $session = $this->tickets->session(
                $site,
                isset($data['visitor_token']) ? (string) $data['visitor_token'] : null,
                isset($data['page_url']) ? (string) $data['page_url'] : null,
                isset($data['email']) ? (string) $data['email'] : null,
            );
            $payload = [
                'visitor_token' => $session['visitor_token'],
                'ticket' => $this->tickets->ticketPayload($session['ticket']),
                'messages' => $this->tickets->messagePayloads($session['messages']),
                'history' => $this->tickets->historyPayloads($session['history']),
                'topics' => [],
                'echo' => null,
                'key_mode' => 'live',
            ];
            if ($includeWelcome) {
                $payload['welcome_message'] = $site->agentsOnline()
                    ? $site->welcomeMessage()
                    : ($site->offlineMessage() ?: $site->welcomeMessage());
                $payload['offline'] = !$site->agentsOnline();
                $payload['primary_color'] = $site->primaryColor();
                $payload['site_name'] = $site->name();
                $payload['photo_url'] = null;
            }
            $response = $this->rawJson($payload);
            $response->headers->add($this->tickets->corsHeaders((string) ($data['origin'] ?? '')));

            return $response;
        } catch (HttpException $e) {
            return $this->rawError($e->getMessage(), $e->status());
        }
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }

    /** @param array<string, mixed> $payload */
    private function rawJson(array $payload, int $status = 200): JsonResponse
    {
        return new JsonResponse($payload, $status);
    }

    private function rawError(string $message, int $status = 400): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $status);
    }
}
